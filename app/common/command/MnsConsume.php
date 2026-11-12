<?php

namespace app\common\command;

use app\common\model\FailedJob;
use app\common\job\Job;
use app\common\service\mq\LightMqService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Log;

/**
 * 从 MNS 队列拉取并执行任务（单次运行，适合 crontab 调用）。
 *
 * 常驻消费请用 workerman。本命令每轮处理完即退出。
 *
 * 示例：
 *   php think mns:consume --max=20
 *   php think mns:consume --queue=test --wait=5
 */
class MnsConsume extends Command
{
    /** 连续空轮询次数上限，超过即退出 */
    private const DEFAULT_IDLE_BREAK = 3;

    protected function configure(): void
    {
        $this->setName('mns:consume')
            ->setDescription('从 MNS 队列拉取并执行任务')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, '队列名，默认取 mns.default_queue', '')
            ->addOption('max', null, Option::VALUE_OPTIONAL, '最多处理条数', 20)
            ->addOption('wait', null, Option::VALUE_OPTIONAL, '长轮询等待秒数', 3)
            ->addOption('idle-break', null, Option::VALUE_OPTIONAL, '连续空队列次数后退出', self::DEFAULT_IDLE_BREAK);
    }

    protected function execute(Input $input, Output $output): int
    {
        $queue = trim((string)$input->getOption('queue'));
        if ($queue === '') {
            $queue = (string)config('mns.default_queue', 'default');
        }
        $max = max(1, (int)$input->getOption('max'));
        $wait = max(1, (int)$input->getOption('wait'));
        $idleBreak = max(1, (int)$input->getOption('idle-break'));

        try {
            $service = new LightMqService();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $handled = 0;
        $idle = 0;
        while ($handled < $max) {
            try {
                // 自动删除交给调用方：只有任务成功才删，失败留给 MNS 重投。
                $message = $service->receiveMessage($queue, $wait, false);
            } catch (\Throwable $e) {
                Log::channel('mns')->error('[mns.consume] receive error: ' . $e->getMessage());
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return 1;
            }

            if ($message === null) {
                $idle++;
                if ($idle >= $idleBreak) {
                    break;
                }
                continue;
            }
            $idle = 0;

            $this->handleMessage($service, $queue, $message, $output);
            $handled++;
        }

        $output->writeln(sprintf('[mns.consume] done queue=%s handled=%d', $queue, $handled));
        return 0;
    }

    /**
     * 处理单条消息：成功删除，失败按重试上限决定是否丢弃。
     *
     * @param array<string,mixed> $message
     */
    private function handleMessage(LightMqService $service, string $queue, array $message, Output $output): void
    {
        $payload = $message[LightMqService::RECEIVE_KEY_PAYLOAD] ?? null;
        $receiptHandle = (string)($message[LightMqService::RECEIVE_KEY_RECEIPT_HANDLE] ?? '');
        $messageId = (string)($message[LightMqService::RECEIVE_KEY_MESSAGE_ID] ?? '');
        $dequeueCount = (int)($message[LightMqService::RECEIVE_KEY_DEQUEUE_COUNT] ?? 0);

        if (!is_array($payload) || $payload === []) {
            Log::channel('mns')->error(sprintf('[mns.consume] invalid payload message_id=%s', $messageId));
            $this->safeDelete($service, $queue, $receiptHandle);
            return;
        }

        // 消息还原失败时没有 Job 实例，仍需按原始消息记录失败。
        $job = null;
        try {
            $job = LightMqService::restoreJob($payload);
            $job->handle();
            $job->clearTenantContext();
            $this->safeDelete($service, $queue, $receiptHandle);
            Log::channel('mns')->info(sprintf(
                '[mns.consume] handled queue=%s job=%s job_id=%s message_id=%s',
                $queue,
                get_class($job),
                $job->getId(),
                $messageId
            ));
            $output->writeln(sprintf('[ok] queue=%s job=%s job_id=%s', $queue, get_class($job), $job->getId()));
        } catch (\Throwable $e) {
            $this->handleFailure($service, $queue, $payload, $receiptHandle, $messageId, $dequeueCount, $e, $output, $job);
            // 无论成败都要清理租户上下文，避免串到下一个任务
            request()->tenantId = null;
            request()->source = null;
        }
    }

    /**
     * 失败处理：未达上限交给 MNS 重投，达上限记录后删除。
     *
     * @param array<string,mixed> $payload
     */
    private function handleFailure(
        LightMqService $service,
        string $queue,
        array $payload,
        string $receiptHandle,
        string $messageId,
        int $dequeueCount,
        \Throwable $e,
        Output $output,
        ?Job $job
    ): void {
        $jobClass = (string)($payload['data']['job'] ?? 'Unknown');
        $jobId = (string)($payload['id'] ?? '');
        $maxRetry = max(1, (int)config('mns.max_retry', 3));

        Log::channel('mns')->error(sprintf(
            '[mns.consume] failed queue=%s job=%s job_id=%s message_id=%s dequeue=%d/%d error=%s',
            $queue,
            $jobClass,
            $jobId,
            $messageId,
            $dequeueCount,
            $maxRetry,
            $e->getMessage()
        ));

        if ($dequeueCount < $maxRetry) {
            // 不删除消息，等 VisibilityTimeout 到期后由 MNS 重投。
            $output->writeln(sprintf('[retry] job=%s dequeue=%d/%d', $jobClass, $dequeueCount, $maxRetry));
            return;
        }

        try {
            // 与 Workerman 消费入口一致，确认消息前先更新任务的最终状态。
            $job?->failed();
            FailedJob::record($queue, $jobClass, $jobId, $payload, $e, $dequeueCount);
            $this->safeDelete($service, $queue, $receiptHandle);
            Log::channel('mns')->error(sprintf('[mns.consume] abandoned job=%s job_id=%s', $jobClass, $jobId));
            $output->writeln(sprintf('[abandoned] job=%s job_id=%s', $jobClass, $jobId));
        } catch (\Throwable $recordError) {
            Log::channel('mns')->error('[mns.consume] record failed job error: ' . $recordError->getMessage());
            $output->writeln('<error>' . $recordError->getMessage() . '</error>');
        }
    }

    private function safeDelete(LightMqService $service, string $queue, string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            return;
        }
        try {
            $service->deleteMessage($queue, $receiptHandle);
        } catch (\Throwable $e) {
            Log::channel('mns')->error('[mns.consume] delete error: ' . $e->getMessage());
        }
    }
}
