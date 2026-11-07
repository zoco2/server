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
 */
class SmartEditSubmitJob extends Job
{
    /** 提交最多尝试 3 次（首次 + 两次重投） */
    public const MAX_ATTEMPTS = 3;

    /** 重投间隔（秒） */
    private const RETRY_DELAY = 10;

    /** 提交只发一个 HTTP 请求，给 60 秒足够 */
    protected int $timeout = 60;

    public function handle()
    {
        $taskId = (int)($this->data['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new \RuntimeException('SmartEditSubmitJob 缺少 task_id');
        }

        $task = SmartEditTask::findOrEmpty($taskId);
        if ($task->isEmpty()) {
            Log::channel('mns')->warning('[smart_edit] submit job skipped, task not found id=' . $taskId);
            return;
        }
        // 用户可能在排队期间取消或删除任务，这里直接跳过，不再提交
        if ((string)$task['status'] !== SmartEditTask::STATUS_SUBMITTING) {
            Log::channel('mns')->info(sprintf(
                '[smart_edit] submit job skipped id=%d status=%s',
                $taskId,
                (string)$task['status']
            ));
            return;
        }

        $tenantId = (int)$task['tenant_id'];
        $timeline = (array)$task['timeline_json'];
        if ($timeline === []) {
            $task->save([
                'status' => SmartEditTask::STATUS_FAILED,
                'error_code' => 'TIMELINE_MISSING',
                'error_message' => '任务参数缺失，无法提交',
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            return;
        }

        $config = SmartEditImsConfigService::require($tenantId);
        $params = (array)$task['params_json'];
        [$outputWidth, $outputHeight] = SmartEditService::resolveOutputSize((string)($params['aspect_ratio'] ?? ''));
        $output = SmartEditMaterialService::outputTarget($config, (string)$task['task_no']);

        try {
            $client = new ImsClient($config['access_key'], $config['secret_key'], $config['region']);
            $jobId = $client->submit($timeline, $output['url'], $outputWidth, $outputHeight);
        } catch (\Throwable $e) {
            $this->retryOrFail($task, $e);
            return;
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
    }

    /**
     * 提交失败后自己重投一次，次数用尽才落失败。
     *
     * 消费端不会调用 Job::failed()，所以重试不能依赖 MNS 重投；
     * 这里按消息里带的 attempt 计数显式重投，避免任务永久停在 submitting。
     *
     * @param SmartEditTask $task 任务
     * @param \Throwable    $e    提交异常
     */
    private function retryOrFail(SmartEditTask $task, \Throwable $e): void
    {
        $attempt = (int)($this->data['attempt'] ?? 0) + 1;
        $taskId = (int)$task['id'];

        Log::channel('mns')->error(sprintf(
            '[smart_edit] submit failed task=%s attempt=%d/%d %s',
            (string)$task['task_no'],
            $attempt,
            self::MAX_ATTEMPTS,
            $e->getMessage()
        ));

        if ($attempt < self::MAX_ATTEMPTS) {
            try {
                self::dispatch(
                    ['task_id' => $taskId, 'attempt' => $attempt],
                    self::RETRY_DELAY,
                    '',
                    (int)$task['tenant_id']
                );
                return;
            } catch (\Throwable $dispatchError) {
                Log::channel('mns')->error(
                    '[smart_edit] submit retry dispatch failed task=' . $task['task_no'] . ' ' . $dispatchError->getMessage()
                );
            }
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
