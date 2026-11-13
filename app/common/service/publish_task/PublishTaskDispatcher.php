<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\AdminTerminalEnum;
use app\common\model\merchant\PublishTask;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/** 扫描到期计划，逐个交给发布服务处理。 */
final class PublishTaskDispatcher
{
    public static function scan(): void
    {
        $tasks = PublishTask::query()->getTable();
        $due = Db::table($tasks)->where('status', 'scheduled')
            ->whereNotNull('tasksn')->where('next_run_at', '<=', time())
            ->field('id,tenant_id,user_id,tasksn')->order('next_run_at asc,id asc')->select();

        $request = request();
        $previousSource = $request->source;
        $previousTenantId = $request->tenantId;
        try {
            // 按计划的租户进入模型查询范围。
            $request->source = AdminTerminalEnum::USER;
            foreach ($due as $row) {
                $tenantId = (int)$row['tenant_id'];
                $taskId = (int)$row['id'];
                $request->tenantId = $tenantId;
                try {
                    PublishTaskService::dispatchDue($taskId, $tenantId, (int)$row['user_id'], (string)$row['tasksn']);
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
