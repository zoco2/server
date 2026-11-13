<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;

/** 发布任务的 MNS 消费入口，将队列载荷交给发布服务处理。 */
final class PublishTaskJob extends Job
{
    /** 校验执行记录和归属，再交给发布服务处理。 */
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
     * 从队列上下文和消息数据提取执行记录 ID、租户 ID 和用户 ID。
     *
     * @return array{int, int, int}|null
     */
    private function arguments(): ?array
    {
        $runId = (int)($this->data['run_id'] ?? 0);
        $tenantId = (int)$this->tenantId;
        $userId = (int)($this->data['user_id'] ?? 0);
        if ($runId <= 0 || $tenantId <= 0 || $userId <= 0) {
            return null;
        }
        return [$runId, $tenantId, $userId];
    }
}
