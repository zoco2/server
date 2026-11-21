<?php

namespace app\common\service\smart_edit;

use app\common\job\SmartEditSubmitJob;
use app\common\model\smart_edit\SmartEditTask;
use app\common\service\pricing\BillingTenantScope;
use think\facade\Log;

class SmartEditPollService
{
    private const DISPATCH_RECHECK_SECONDS = 900;

    /** 繁忙租户用尽本轮名额时，下轮从后面的租户继续。 */
    private static int $afterTenant = 0;

    public static function run(int $limit): int
    {
        $startAfter = self::$afterTenant;
        $count = 0;
        $lastTenant = 0;
        BillingTenantScope::each('smart_edit_task', static function (int $tenantId) use ($limit, $startAfter, &$count, &$lastTenant) {
            if ($tenantId <= $startAfter || $count >= $limit) {
                return;
            }
            $cutoff = time() - self::DISPATCH_RECHECK_SECONDS;
            $tasks = SmartEditTask::where('tenant_id', $tenantId)
                ->whereIn('status', SmartEditTask::RUNNING_STATUSES)
                ->where('next_poll_time', '<=', time())
                ->where(static function ($query) use ($cutoff) {
                    $query->where('provider_job_id', '<>', '')
                        ->whereOr(static function ($query) use ($cutoff) {
                            $query->where('status', SmartEditTask::STATUS_SUBMITTING)->where('update_time', '<=', $cutoff);
                        });
                })->order('next_poll_time asc,id asc')->limit($limit - $count)->select();
            foreach ($tasks as $task) {
                try {
                    if ((string)$task['provider_job_id'] === '') {
                        self::recoverSubmission($task);
                    } else {
                        SmartEditService::refresh((int)$task['id']);
                    }
                } catch (\Throwable $e) {
                    Log::channel('mns')->error(sprintf('[smart_edit] poll failed tenant=%d task=%d %s',
                        $tenantId, (int)$task['id'], $e->getMessage()));
                }
                $count++;
                $lastTenant = $tenantId;
            }
        });
        self::$afterTenant = $count >= $limit ? $lastTenant : 0;
        if ($count === 0 && $startAfter > 0) {
            return self::run($limit);
        }
        return $count;
    }

    /** 建任务事务提交后进程可能在发 MNS 前退出。补送同一任务号和轮次，不产生新的扣款。 */
    private static function recoverSubmission(SmartEditTask $task): void
    {
        $claimed = false;
        SmartEditBillingService::withCurrentTask($task, static function (SmartEditTask $locked) use (&$claimed) {
            if ((int)$locked['next_poll_time'] <= time()) {
                $locked->save(['next_poll_time' => time() + self::DISPATCH_RECHECK_SECONDS, 'update_time' => time()]);
                $claimed = true;
            }
        });
        if (!$claimed) {
            return;
        }
        try {
            SmartEditSubmitJob::dispatch([
                'task_ids' => [(int)$task['id']], 'billing_attempt' => SmartEditBillingService::attempt($task),
            ], 0, '', (int)$task['tenant_id']);
        } catch (\Throwable $e) {
            SmartEditBillingService::fail($task, [
                'status' => SmartEditTask::STATUS_FAILED, 'error_code' => 'DISPATCH_FAILED',
                'error_message' => '剪辑任务投递失败，请重试', 'finish_time' => time(), 'update_time' => time(),
            ]);
            throw $e;
        }
    }
}
