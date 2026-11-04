<?php

namespace app\common\job;

use app\common\service\mq\LightMqService;
use think\facade\Config;

/**
 * Job 抽象基类。
 *
 * 一次入队消息携带足够信息让消费端独立还原：任务类、原始数据、重试次数、租户 ID。
 * 租户 ID 是硬性要求——BaseModel 的全局作用域依赖 request()->tenantId，
 * 消费进程里没有登录态，只能靠消息把租户带过来。
 */
abstract class Job implements JobInterface
{
    /** 任务尝试次数（MNS 最大接收次数） */
    protected int $tries = 3;

    /** 任务超时时间（秒），供子类自行控制 */
    protected int $timeout = 60;

    /** 延迟执行秒数 */
    protected int $delay = 0;

    /** 任务数据 */
    protected array $data = [];

    /** 任务 ID */
    protected string $id = '';

    /** 队列名 */
    protected string $queue = 'default';

    /** 租户 ID */
    protected ?int $tenantId = null;

    /**
     * @param array $data 任务数据
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
        $this->tenantId = isset(request()->tenantId) ? (int)request()->tenantId : null;
        $this->id = uniqid('job_', true);
    }

    /**
     * 投递任务到队列。
     *
     * @param array  $data    任务数据
     * @param int    $delay   延迟秒数
     * @param string $queue   队列名，空则用配置默认队列
     * @param int    $tenant  租户 ID；不传则取当前请求上下文
     */
    public static function dispatch(array $data, int $delay = 0, string $queue = '', int $tenant = 0): void
    {
        $job = new static($data);
        if ($tenant > 0) {
            $job->tenantId = $tenant;
        }
        if ($queue === '') {
            $queue = (string)Config::get('mns.default_queue', 'default');
        }
        $job->onQueue($queue);
        if ($delay > 0) {
            $job->delay($delay);
        }
        (new LightMqService())->dispatchJob($job->getQueue(), $job, $job->getDelay());
    }

    /**
     * 任务最终失败回调，子类按需覆盖。
     */
    public function failed()
    {
    }

    public function delay(int $seconds): self
    {
        $this->delay = $seconds;
        return $this;
    }

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;
        return $this;
    }

    public function getTries(): int
    {
        return $this->tries;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * 序列化为队列消息体。
     */
    public function getPayload(): array
    {
        return [
            'id' => $this->id,
            'time' => time(),
            'delay' => $this->delay,
            'attempts' => $this->tries,
            'queue' => $this->queue,
            'data' => [
                'job' => static::class,
                'data' => $this->data,
                'tries' => $this->tries,
                'timeout' => $this->timeout,
                'tenant_id' => $this->tenantId,
            ],
        ];
    }

    /**
     * 从队列消息体恢复任务，并恢复租户上下文。
     *
     * @throws \RuntimeException 消息缺少租户 ID 时抛出：没有租户就无法安全访问数据
     */
    public static function fromPayload(array $payload): self
    {
        $data = $payload['data']['data'] ?? $payload['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        /** @var Job $job */
        $job = new static($data);
        $job->id = (string)($payload['id'] ?? $job->id);
        $job->tries = (int)($payload['data']['tries'] ?? $payload['tries'] ?? $job->tries);
        $job->timeout = (int)($payload['data']['timeout'] ?? $payload['timeout'] ?? $job->timeout);
        $job->delay = (int)($payload['delay'] ?? $job->delay);
        $job->queue = (string)($payload['queue'] ?? $job->queue);
        $tenantId = (int)($payload['data']['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('Job 消息缺少租户 ID，无法执行');
        }
        $job->tenantId = $tenantId;
        request()->tenantId = $tenantId;

        return $job;
    }

    /**
     * 清理租户上下文，避免同一消费进程内串到下一个任务。
     */
    public function clearTenantContext(): void
    {
        request()->tenantId = null;
    }
}
