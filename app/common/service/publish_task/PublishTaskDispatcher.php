<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\AdminTerminalEnum;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;
use app\common\model\tenant\Tenant;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/** 按租户扫描到期任务，由发布服务加锁后投递执行记录。 */
final class PublishTaskDispatcher
{
    private const TENANT_BATCH = 10;
    private const TASK_BATCH = 20;

    private static int $lastTenantId = 0;

    public static function scan(): void
    {
        $request = request();
        $previousSource = $request->source;
        $previousTenantId = $request->tenantId;
        try {
            // 只用平台上下文读取租户目录，任务查询始终在对应的租户上下文中执行。
            $request->source = AdminTerminalEnum::PLATFORM;
            $request->tenantId = null;
            $tenants = Tenant::where('disable', 0)->where('id', '>', self::$lastTenantId)
                ->field('id,sn,tactics')->order('id', 'asc')->limit(self::TENANT_BATCH)->select()->toArray();
            if ($tenants === [] && self::$lastTenantId > 0) {
                self::$lastTenantId = 0;
                $tenants = Tenant::where('disable', 0)->field('id,sn,tactics')
                    ->order('id', 'asc')->limit(self::TENANT_BATCH)->select()->toArray();
            }

            $now = time();
            $request->source = AdminTerminalEnum::USER;
            foreach ($tenants as $tenant) {
                $tenantId = (int)$tenant['id'];
                self::$lastTenantId = $tenantId;
                $request->tenantId = $tenantId;
                try {
                    self::dispatchForTenant($tenant, $now);
                } catch (Throwable $e) {
                    // 某个租户缺表或查询失败时，继续处理这一批的其他租户。
                    Log::channel('mns')->error(sprintf('[publish_task] scan failed tenant=%d error=%s', $tenantId, $e->getMessage()));
                }
            }
            if (count($tenants) < self::TENANT_BATCH) {
                self::$lastTenantId = 0;
            }
        } finally {
            $request->source = $previousSource;
            $request->tenantId = $previousTenantId;
        }
    }

    private static function dispatchForTenant(array $tenant, int $now): void
    {
        // 预检实际表的字段。缺表或缺租户字段时停止该租户的扫描，避免落到共用表。
        $suffix = (int)$tenant['tactics'] === 1 ? '_' . $tenant['sn'] : '';
        foreach ([PublishTask::query(), PublishTaskRun::query()] as $query) {
            $connection = $query->getConnection();
            $table = $query->getTable();
            if (!in_array('tenant_id', $connection->getTableFields($table), true)
                || ($suffix !== '' && !in_array('tenant_id', $connection->getTableFields($table . $suffix), true))) {
                return;
            }
        }

        // 重用原执行记录，覆盖发送失败和消息已入队但长期没有被消费的情况。
        $retryBefore = $now - PublishTaskService::REDISPATCH_AFTER_SECONDS;
        $retries = PublishTaskRun::where('status', 'running')->where(function ($query) use ($retryBefore) {
            $query->whereNull('dispatched_at')->whereOr('dispatched_at', '<=', $retryBefore);
        })->field('task_id,user_id')->order('dispatched_at', 'asc')->order('id', 'asc')
            ->limit(self::TASK_BATCH)->select()->toArray();

        // 在取前一批任务前排除仍在执行的用户，给同租户的其他用户留出机会。
        $runningUsers = PublishTaskRun::where('status', 'running')->field('user_id')->buildSql(false);
        $tasks = PublishTask::where('status', 'scheduled')->where('next_run_at', '<=', $now)
            ->whereNotIn('user_id', Db::raw($runningUsers))->field('id AS task_id,user_id')
            ->order('next_run_at', 'asc')->order('id', 'asc')->limit(self::TASK_BATCH)->select()->toArray();

        $tenantId = (int)$tenant['id'];
        foreach (array_merge($retries, $tasks) as $row) {
            $taskId = (int)$row['task_id'];
            try {
                PublishTaskService::dispatchDue($taskId, $tenantId, (int)$row['user_id']);
            } catch (Throwable $e) {
                Log::channel('mns')->error(sprintf('[publish_task] dispatch failed tenant=%d task=%d type=%s', $tenantId, $taskId, get_class($e)));
            }
        }
    }
}
