<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;

/** 发布任务的 MNS 消费入口，将队列载荷交给发布服务处理。 */
final class PublishTaskJob extends Job
{
    /** 校验消息中的任务归属和唤醒时间，再交给发布服务处理。 */
    public function handle(): void
    {
        $arguments = $this->arguments();
        if ($arguments === null) {
            throw new InvalidArgumentException('发布任务队列消息无效');
        }
        PublishTaskService::consume(...$arguments);
    }

    /** 重试耗尽后终止仍与本消息匹配的任务，无法识别归属的消息直接跳过。 */
    public function failed(): void
    {
        $arguments = $this->arguments();
        if ($arguments !== null) {
            PublishTaskService::abandon(...$arguments);
        }
    }

    /**
     * Job 基类已恢复租户上下文，这里只读取任务参数。
     *
     * @return array{int, int, int}|null
     */
    private function arguments(): ?array
    {
        $id = (int)($this->data['task_id'] ?? 0);
        $userId = (int)($this->data['user_id'] ?? 0);
        $wakeAt = $this->data['wake_at'] ?? null;
        if ($id <= 0 || $userId <= 0 || !is_int($wakeAt) || $wakeAt <= 0) {
            return null;
        }
        return [$id, $userId, $wakeAt];
    }
}
