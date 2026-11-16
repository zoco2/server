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
     * 读取计划和执行记录的标识，供消费端锁住对应计划。
     *
     * @return array{int, int, int, int, string}|null
     */
    private function arguments(): ?array
    {
        $taskId = (int)($this->data['task_id'] ?? 0);
        $runId = (int)($this->data['run_id'] ?? 0);
        $tenantId = (int)$this->tenantId;
        $userId = (int)($this->data['user_id'] ?? 0);
        $taskSn = $this->data['tasksn'] ?? '';
        if ($taskId <= 0 || $runId <= 0 || $tenantId <= 0 || $userId <= 0
            || !is_string($taskSn) || !preg_match('/\A[0-9a-f]{32}\z/', $taskSn)) {
            return null;
        }
        return [$taskId, $runId, $tenantId, $userId, $taskSn];
    }
}
