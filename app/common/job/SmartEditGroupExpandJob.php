<?php

namespace app\common\job;

use app\common\model\smart_edit\SmartEditGroup;
use app\common\service\smart_edit\SmartEditGroupService;
use app\common\service\smart_edit\SmartEditService;
use think\facade\Log;

/**
 * 智能剪辑镜头组：笛卡尔积展开成任务，再分批投递提交 job。
 *
 * 展开可能产生上千条任务，放在 HTTP 请求里会超时，所以整个展开过程在消费端完成。
 * 组行在投递前已存好 shots_json 与 config_json，消费端可直接重放。
 */
class SmartEditGroupExpandJob extends Job
{
    /** 展开最多尝试 3 次（首次 + 两次重投） */
    public const MAX_ATTEMPTS = 3;

    /** 重投间隔（秒） */
    private const RETRY_DELAY = 30;

    /** 展开是本地计算 + 批量写库，给 10 分钟 */
    protected int $timeout = 600;

    public function handle()
    {
        $batchNo = trim((string)($this->data['batch_no'] ?? ''));
        if ($batchNo === '') {
            throw new \RuntimeException('SmartEditGroupExpandJob 缺少 batch_no');
        }
        $tenantId = (int)($this->tenantId ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('SmartEditGroupExpandJob 缺少租户 ID');
        }

        $group = SmartEditGroup::where('tenant_id', $tenantId)->where('batch_no', $batchNo)->findOrEmpty();
        if ($group->isEmpty()) {
            Log::channel('mns')->warning('[smart_edit] group expand skipped, group not found batch=' . $batchNo);
            return;
        }
        // 用户可能在排队期间删了组；已提交的组不重复展开
        if ((string)$group['status'] !== SmartEditGroup::STATUS_EXPANDING) {
            Log::channel('mns')->info(sprintf(
                '[smart_edit] group expand skipped batch=%s status=%s',
                $batchNo,
                (string)$group['status']
            ));
            return;
        }

        $attempt = (int)($this->data['attempt'] ?? 0);

        try {
            $taskIds = $this->expand($group, $tenantId);
        } catch (\Throwable $e) {
            Log::channel('mns')->error(sprintf(
                '[smart_edit] group expand failed batch=%s attempt=%d %s',
                $batchNo,
                $attempt,
                $e->getMessage()
            ));
            $this->retryOrFail($group, $attempt, $tenantId, $e);
            return;
        }

        // 按 MNS 单消息上限分片投递提交 job
        foreach (array_chunk($taskIds, SmartEditSubmitJob::MAX_TASKS_PER_JOB) as $chunk) {
            try {
                SmartEditSubmitJob::dispatch(['task_ids' => array_values($chunk)], 0, '', $tenantId);
            } catch (\Throwable $e) {
                // 提交消息投递失败：任务停在 submitting，用户可在列表里看到并重试
                Log::channel('mns')->error(
                    '[smart_edit] group submit dispatch failed batch=' . $batchNo . ' ' . $e->getMessage()
                );
            }
        }

        $group->save([
            'status' => SmartEditGroup::STATUS_SUBMITTED,
            'error_code' => '',
            'error_message' => '',
            'update_time' => time(),
        ]);

        Log::channel('mns')->info(sprintf(
            '[smart_edit] group expanded batch=%s combos=%d tasks=%d',
            $batchNo,
            (int)$group['combo_count'],
            count($taskIds)
        ));
    }

    /**
     * 展开组为任务行，返回新建的任务 ID 列表。
     *
     * @param SmartEditGroup $group    组
     * @param int            $tenantId 租户 ID
     * @return array<int, int>
     * @throws \Throwable 建任务失败时抛出，由调用方决定重试
     */
    private function expand(SmartEditGroup $group, int $tenantId): array
    {
        $shots = (array)$group['shots_json'];
        $shared = (array)$group['config_json'];
        $batchNo = (string)$group['batch_no'];
        $userId = (int)$group['user_id'];

        $combos = SmartEditGroupService::expandShots($shots);
        $taskIds = [];
        foreach ($combos as $index => $clips) {
            $task = SmartEditService::createTask(
                $tenantId,
                $userId,
                $shared,
                $clips,
                $batchNo,
                $index + 1
            );
            $taskIds[] = (int)$task['id'];
        }
        return $taskIds;
    }

    /**
     * 展开失败后按次数重投，用尽才把组置失败。
     *
     * 消费端不会调用 Job::failed()，重试只能显式重投。
     *
     * @param SmartEditGroup $group    组
     * @param int            $attempt  已尝试次数
     * @param int            $tenantId 租户 ID
     * @param \Throwable     $e        异常
     */
    private function retryOrFail(SmartEditGroup $group, int $attempt, int $tenantId, \Throwable $e): void
    {
        $nextAttempt = $attempt + 1;
        if ($nextAttempt < self::MAX_ATTEMPTS) {
            try {
                self::dispatch(
                    ['batch_no' => (string)$group['batch_no'], 'attempt' => $nextAttempt],
                    self::RETRY_DELAY,
                    '',
                    $tenantId
                );
                return;
            } catch (\Throwable $dispatchError) {
                Log::channel('mns')->error(
                    '[smart_edit] group expand retry dispatch failed batch=' . $group['batch_no']
                    . ' ' . $dispatchError->getMessage()
                );
            }
        }

        $group->save([
            'status' => SmartEditGroup::STATUS_FAILED,
            'error_code' => 'EXPAND_FAILED',
            'error_message' => mb_substr('生成任务失败：' . $e->getMessage(), 0, 480),
            'update_time' => time(),
        ]);
    }
}
