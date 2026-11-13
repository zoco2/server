<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\AdminTerminalEnum;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/** 扫描任务表和执行记录表，投递到期任务。 */
final class PublishTaskDispatcher
{
    private const BATCH_SIZE = 20;

    public static function scan(): void
    {
        $taskTable = PublishTask::query()->getTable();
        $runTable = PublishTaskRun::query()->getTable();
        $tables = array_fill_keys(Db::connect()->getTables(), true);
        $now = time();

        foreach ($tables as $table => $_) {
            // 共用表用原名，分表用相同的租户后缀配对。
            if ($table === $runTable
                || !preg_match('/^' . preg_quote($taskTable, '/') . '(_[a-zA-Z0-9_]+)?$/D', $table, $match)) {
                continue;
            }
            $runs = $runTable . ($match[1] ?? '');
            if (!isset($tables[$runs])) {
                continue;
            }

            try {
                self::scanTables($table, $runs, $now);
            } catch (Throwable $e) {
                Log::channel('mns')->error(sprintf('[publish_task] scan failed table=%s error=%s', $table, $e->getMessage()));
            }
        }
    }

    private static function scanTables(string $tasks, string $runs, int $now): void
    {
        // 入队失败或长期未消费时，重试原执行记录。
        $retryBefore = $now - PublishTaskService::REDISPATCH_AFTER_SECONDS;
        $retries = Db::table($runs)->where('status', 'running')->where(function ($query) use ($retryBefore) {
            $query->whereNull('dispatched_at')->whereOr('dispatched_at', '<=', $retryBefore);
        })->field('task_id,tenant_id,user_id')->order('dispatched_at', 'asc')->order('id', 'asc')
            ->limit(self::BATCH_SIZE)->select()->toArray();

        // 共用表上可能有多个租户，同一租户和用户已有执行记录时跳过。
        $due = Db::table($tasks)->alias('task')->where('task.status', 'scheduled')
            ->where('task.next_run_at', '<=', $now)
            ->whereNotExists(static function ($query) use ($runs) {
                $query->table($runs)->alias('run')->field('run.id')->where('run.status', 'running')
                    ->whereColumn('run.tenant_id', '=', 'task.tenant_id')
                    ->whereColumn('run.user_id', '=', 'task.user_id');
            })->field('task.id AS task_id,task.tenant_id,task.user_id')
            ->order('task.next_run_at', 'asc')->order('task.id', 'asc')
            ->limit(self::BATCH_SIZE)->select()->toArray();

        $request = request();
        $previousSource = $request->source;
        $previousTenantId = $request->tenantId;
        try {
            // 候选查询只读。投递前恢复租户上下文，由模型在用户锁内复查。
            $request->source = AdminTerminalEnum::USER;
            foreach (array_merge($retries, $due) as $row) {
                $tenantId = (int)$row['tenant_id'];
                $taskId = (int)$row['task_id'];
                $request->tenantId = $tenantId;
                try {
                    PublishTaskService::dispatchDue($taskId, $tenantId, (int)$row['user_id'], $tasks);
                } catch (Throwable $e) {
                    Log::channel('mns')->error(sprintf('[publish_task] dispatch failed tenant=%d task=%d type=%s', $tenantId, $taskId, get_class($e)));
                }
            }
        } finally {
            $request->source = $previousSource;
            $request->tenantId = $previousTenantId;
        }
    }
}
