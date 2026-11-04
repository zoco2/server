<?php

namespace app\common\model;

/**
 * 队列最终失败的任务记录。
 *
 * 与 Crontab 等一样不参与租户全局作用域（表不在分表检查列表里时默认仍会加 tenant_id 条件，
 * 这里显式写入 tenant_id，查询时由调用方自行过滤）。
 */
class FailedJob extends BaseModel
{
    /** 待处理 */
    public const STATUS_PENDING = 0;
    /** 已重试成功 */
    public const STATUS_RETRIED = 1;
    /** 已放弃 */
    public const STATUS_ABANDONED = 2;

    protected $name = 'tenant_failed_jobs';

    /**
     * 记录一条最终失败的队列任务。
     *
     * @param string     $queue      队列名
     * @param string     $jobClass   任务类
     * @param string     $jobId      任务 ID
     * @param array      $payload    完整消息体
     * @param \Throwable $exception  异常
     * @param int        $retryCount 已接收次数
     * @return int 记录 ID
     */
    public static function record(
        string $queue,
        string $jobClass,
        string $jobId,
        array $payload,
        \Throwable $exception,
        int $retryCount = 0
    ): int {
        $row = self::create([
            'tenant_id' => (int)(request()->tenantId ?? 0),
            'queue' => $queue,
            'job_class' => $jobClass,
            'job_id' => $jobId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'exception' => mb_substr(
                $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine(),
                0,
                2000
            ),
            'failed_at' => time(),
            'retry_count' => $retryCount,
            'status' => self::STATUS_PENDING,
            'create_time' => time(),
        ]);
        return (int)$row['id'];
    }
}
