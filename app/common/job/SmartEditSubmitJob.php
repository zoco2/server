<?php

namespace app\common\job;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditImsConfigService;
use app\common\service\smart_edit\SmartEditMaterialService;
use app\common\service\smart_edit\SmartEditService;
use app\common\service\smart_edit\ImsClient;
use think\facade\Log;

/**
 * 智能剪辑：把已落库的 Timeline 提交到 IMS。
 *
 * 提交改到消费端执行，接口只负责建任务和投递，避免 IMS 接口慢或超时
 * 把用户请求拖死。任务行在投递前已经写好 timeline_json，消费端可直接重放。
 *
 * 消息体带的是任务 ID 数组，单任务就是长度为 1 的数组；
 * 批量场景（分镜混剪等）一次投递多条，逐条提交，互不影响。
 */
class SmartEditSubmitJob extends Job
{
    /** 单条任务最多尝试 3 次（首次 + 两次重投） */
    public const MAX_ATTEMPTS = 3;

    /** 单条消息最多携带的任务数，避免一次消息过长、执行过久 */
    public const MAX_TASKS_PER_JOB = 50;

    /** 重投间隔（秒） */
    private const RETRY_DELAY = 10;

    /** 提交是串行的 HTTP 请求 */
    protected int $timeout = 60;

    public function handle()
    {
        $taskIds = self::normalizeTaskIds($this->data);
        if ($taskIds === []) {
            throw new \RuntimeException('SmartEditSubmitJob 缺少 task_ids');
        }

        $attempt = (int)($this->data['attempt'] ?? 0);
        $tenantId = (int)($this->tenantId ?? 0);

        $failedTaskIds = [];
        foreach ($taskIds as $taskId) {
            $task = SmartEditTask::findOrEmpty($taskId);
            if ($task->isEmpty()) {
                Log::channel('mns')->warning('[smart_edit] submit job skipped, task not found id=' . $taskId);
                continue;
            }
            // 用户可能在排队期间取消或删除任务，这里直接跳过，不再提交
            if ((string)$task['status'] !== SmartEditTask::STATUS_SUBMITTING) {
                Log::channel('mns')->info(sprintf(
                    '[smart_edit] submit job skipped id=%d status=%s',
                    $taskId,
                    (string)$task['status']
                ));
                continue;
            }
            // 消息里的租户用于还原上下文；任务自身的 tenant_id 是权威值
            if ($tenantId > 0 && (int)$task['tenant_id'] !== $tenantId) {
                Log::channel('mns')->error(sprintf(
                    '[smart_edit] submit job tenant mismatch id=%d job_tenant=%d task_tenant=%d',
                    $taskId,
                    $tenantId,
                    (int)$task['tenant_id']
                ));
                continue;
            }

            if (!$this->submitOne($task)) {
                $failedTaskIds[] = $taskId;
            }
        }

        if ($failedTaskIds !== []) {
            $this->retryOrFail($failedTaskIds, $attempt, $tenantId);
        }
    }

    /**
     * 提交单条任务。
     *
     * @param SmartEditTask $task 任务
     * @return bool 是否提交成功；失败时由调用方统一安排重投
     */
    private function submitOne(SmartEditTask $task): bool
    {
        $timeline = (array)$task['timeline_json'];
        if ($timeline === []) {
            $task->save([
                'status' => SmartEditTask::STATUS_FAILED,
                'error_code' => 'TIMELINE_MISSING',
                'error_message' => '任务参数缺失，无法提交',
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            return true;
        }

        try {
            $config = SmartEditImsConfigService::require((int)$task['tenant_id']);
            $params = (array)$task['params_json'];
            [$outputWidth, $outputHeight] = SmartEditService::resolveOutputSize((string)($params['aspect_ratio'] ?? ''));
            $output = SmartEditMaterialService::outputTarget($config, (string)$task['task_no']);

            $client = new ImsClient($config['access_key'], $config['secret_key'], $config['region']);
            $jobId = $client->submit($timeline, $output['url'], $outputWidth, $outputHeight);
        } catch (\Throwable $e) {
            Log::channel('mns')->error(sprintf(
                '[smart_edit] submit failed task=%s %s',
                (string)$task['task_no'],
                $e->getMessage()
            ));
            return false;
        }

        $task->save([
            'provider_job_id' => $jobId,
            'region' => $config['region'],
            'output_target' => $output['uri'],
            'status' => SmartEditTask::STATUS_PROCESSING,
            'error_code' => '',
            'error_message' => '',
            'next_poll_time' => time() + 5,
            'update_time' => time(),
        ]);
        return true;
    }

    /**
     * 只把失败的任务重新投递，次数用尽才落失败。
     *
     * 消费端不会调用 Job::failed()，所以重试不能依赖 MNS 重投；
     * 这里按消息里带的 attempt 计数显式重投，避免任务永久停在 submitting。
     *
     * @param array<int, int> $taskIds  失败的任务 ID
     * @param int             $attempt  已尝试次数
     * @param int             $tenantId 租户 ID
     */
    private function retryOrFail(array $taskIds, int $attempt, int $tenantId): void
    {
        $nextAttempt = $attempt + 1;
        Log::channel('mns')->error(sprintf(
            '[smart_edit] submit failed ids=%s attempt=%d/%d',
            implode(',', $taskIds),
            $nextAttempt,
            self::MAX_ATTEMPTS
        ));

        if ($nextAttempt < self::MAX_ATTEMPTS && $tenantId > 0) {
            try {
                self::dispatch(
                    ['task_ids' => array_values($taskIds), 'attempt' => $nextAttempt],
                    self::RETRY_DELAY,
                    '',
                    $tenantId
                );
                return;
            } catch (\Throwable $dispatchError) {
                Log::channel('mns')->error(
                    '[smart_edit] submit retry dispatch failed ids=' . implode(',', $taskIds)
                    . ' ' . $dispatchError->getMessage()
                );
            }
        }

        foreach ($taskIds as $taskId) {
            $task = SmartEditTask::findOrEmpty($taskId);
            if ($task->isEmpty()) {
                continue;
            }
            // 已进入终态的任务不要被提交失败覆盖（例如用户已取消）
            if ((string)$task['status'] !== SmartEditTask::STATUS_SUBMITTING) {
                continue;
            }
            $task->save([
                'status' => SmartEditTask::STATUS_FAILED,
                'error_code' => 'SUBMIT_FAILED',
                'error_message' => '剪辑任务提交失败，请稍后重试',
                'finish_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    /**
     * 归一化任务 ID：支持 task_ids 数组，也接受旧的单个 task_id 消息。
     *
     * @param array<string, mixed> $data 任务数据
     * @return array<int, int> 去重后的任务 ID，顺序保持入参顺序
     */
    public static function normalizeTaskIds(array $data): array
    {
        $raw = $data['task_ids'] ?? null;
        if ($raw === null && isset($data['task_id'])) {
            $raw = [$data['task_id']];
        }
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, self::MAX_TASKS_PER_JOB);
    }
}
