<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;

final class PublishTaskJob extends Job
{
    public function handle(): void
    {
        $arguments = $this->arguments();
        if ($arguments === null) {
            throw new InvalidArgumentException('发布任务队列消息无效');
        }
        PublishTaskService::consume(...$arguments);
    }

    public function failed(): void
    {
        $arguments = $this->arguments();
        if ($arguments !== null) {
            PublishTaskService::abandon(...$arguments);
        }
    }

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
