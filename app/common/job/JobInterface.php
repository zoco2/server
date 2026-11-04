<?php

namespace app\common\job;

/**
 * Job 接口。
 */
interface JobInterface
{
    /**
     * 执行任务。
     */
    public function handle();

    /**
     * 任务最终失败时的回调（达到最大重试次数后调用）。
     */
    public function failed();

    /**
     * 序列化为队列消息体。
     */
    public function getPayload(): array;

    /**
     * 从队列消息体恢复任务。
     */
    public static function fromPayload(array $payload): self;
}
