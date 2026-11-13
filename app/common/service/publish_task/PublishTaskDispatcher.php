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
        $tasks = PublishTask::query()->getTable();
        $runs = PublishTaskRun::query()->getTable();
        $now = time();

        // 已有相同 tasksn 的记录，或同一租户和用户有待执行、执行中的记录时，跳过该计划。
        $due = Db::table($tasks)->alias('task')->where('task.status', 'scheduled')
            ->whereNotNull('task.tasksn')
            ->where('task.next_run_at', '<=', $now)
            ->whereNotExists(static function ($query) use ($runs) {
                $query->table($runs)->alias('run')->field('run.id')
                    ->whereColumn('run.tenant_id', '=', 'task.tenant_id')
                    ->whereColumn('run.tasksn', '=', 'task.tasksn');
            })
            ->whereNotExists(static function ($query) use ($runs) {
                $query->table($runs)->alias('run')->field('run.id')->whereIn('run.status', ['pending', 'running'])
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
            foreach ($due as $row) {
                $tenantId = (int)$row['tenant_id'];
                $taskId = (int)$row['task_id'];
                $request->tenantId = $tenantId;
                try {
                    PublishTaskService::dispatchDue($taskId, $tenantId, (int)$row['user_id']);
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
