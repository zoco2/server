<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;

/** 发布任务的 MNS 消费入口，将队列载荷交给发布服务处理。 */
final class PublishTaskJob extends Job
{
    /** 校验本次消息的归属和令牌，再执行或接续对应的任务。 */
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
     * 从队列上下文和消息数据提取任务 ID、租户 ID、用户 ID 和唤醒令牌。
     *
     * @return array{int, int, int, string}|null
     */
    private function arguments(): ?array
    {
        $id = (int)($this->data['task_id'] ?? 0);
        $tenantId = (int)$this->tenantId;
        $userId = (int)($this->data['user_id'] ?? 0);
        $token = $this->data['wake_token'] ?? null;
        if ($id <= 0 || $tenantId <= 0 || $userId <= 0 || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return null;
        }
        return [$id, $tenantId, $userId, $token];
    }
}
