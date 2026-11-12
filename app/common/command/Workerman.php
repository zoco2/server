<?php

namespace app\common\command;

use app\common\model\FailedJob;
use app\common\model\smart_edit\SmartEditTask;
use app\common\service\mq\LightMqService;
use app\common\service\smart_edit\SmartEditService;
use app\common\service\user_task\UserTaskPlanService;
use app\common\service\user_task\UserTaskRunService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Workerman 常驻消费 MNS 队列。
 *
 * 参照 kkvoice_server 的实现：单进程循环拉取，成功才删除消息；
 * 失败不删除，交由 MNS 的 VisibilityTimeout 重投，达到上限后记入失败表。
 *
 * 用法：
 *   php think workerman start        前台运行
 *   php think workerman start -d     守护进程
 *   php think workerman stop|restart|status
 */
class Workerman extends Command
{
    /** 每次拉取的长轮询等待秒数 */
    private const CONSUMER_WAIT_SECONDS = 3;
    /** 拉取间隔秒数 */
    private const CONSUMER_INTERVAL = 0.2;
    /** 空闲心跳日志间隔秒数 */
    private const IDLE_HEARTBEAT_SECONDS = 60;

    /** 智能剪辑轮询间隔秒数 */
    private const SMART_EDIT_POLL_INTERVAL = 3;

    /** 单轮最多轮询的剪辑任务数 */
    private const SMART_EDIT_POLL_BATCH = 50;

    protected function configure(): void
    {
        $this->setName('workerman')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status|connections', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, '以守护进程运行')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, '队列名，默认取 mns.default_queue', '')
            ->setDescription('MNS 队列常驻消费者');
    }

    protected function execute(Input $input, Output $output): int
    {
        $queue = trim((string)$input->getOption('queue'));
        if ($queue === '') {
            $queue = (string)config('mns.default_queue', 'default');
        }

        // Workerman 从全局 $argv 读取 start/stop/restart 与 -d，
        // 这里按命令的 action 参数重组，避免把 think 的其它选项混进去。
        global $argv;
        $action = (string)$input->getArgument('action');
        if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'status', 'connections'], true)) {
            $action = 'start';
        }
        $argv = ['think', $action];
        if ($input->getOption('daemon')) {
            $argv[] = '-d';
        }

        $logDir = app()->getRootPath() . 'runtime/log/workerman/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        Worker::$logFile = $logDir . 'workerman.log';
        Worker::$stdoutFile = $logDir . 'stdout.log';
        Worker::$pidFile = $logDir . 'workerman.pid';

        $worker = new Worker();
        $worker->name = 'mns-consumer';
        $worker->count = 1;
        $worker->onWorkerStart = function (Worker $worker) use ($queue) {
            Log::channel('mns')->info(sprintf('[mns] consumer started queue=%s', $queue));
            self::consume($queue, $worker);
            self::pollSmartEdit($worker);
        };

        // 两个子进程各自运行事件循环，MNS 长轮询和 Job 耗时都不会阻塞调度。
        self::registerTaskWorker('user-task-scheduler', [UserTaskPlanService::class, 'scheduleDue']);
        self::registerTaskWorker('user-task-runner', [UserTaskRunService::class, 'drain']);

        Worker::runAll();
        return 0;
    }

    private static function registerTaskWorker(string $name, callable $tick): void
    {
        $worker = new Worker();
        $worker->name = $name;
        $worker->count = 1;
        $worker->onWorkerStart = static function (Worker $worker) use ($name, $tick): void {
            Worker::log(sprintf('[%s] started', $name));
            $retryAt = 0;
            Timer::add(1, static function () use ($worker, $name, $tick, &$retryAt): void {
                if ($worker->stopping || time() < $retryAt
                    || (function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed())) {
                    return;
                }
                try {
                    $tick();
                } catch (\Throwable $e) {
                    Worker::log(sprintf('[%s] tick failed: %s', $name, $e->getMessage()));
                    self::closeDatabase();
                    // 数据库不可用时退避，避免每秒重连并重复写日志。
                    $retryAt = time() + 5;
                }
            });
        };
    }

    /**
     * 常驻消费循环。
     */
    private static function consume(string $queue, Worker $worker): void
    {
        $state = [
            'service' => null,
            'idle' => 0,
            'lastHeartbeat' => time(),
        ];

        Timer::add(self::CONSUMER_INTERVAL, function () use ($queue, $worker, &$state) {
            if ($worker->stopping) {
                Log::channel('mns')->info('[mns] consumer stopping');
                return;
            }

            try {
                // 懒加载：首次或连接失效后重建，避免每轮都读配置
                if ($state['service'] === null) {
                    $state['service'] = new LightMqService();
                }
                /** @var LightMqService $service */
                $service = $state['service'];

                // 不自动删除：成功才删，失败留给 MNS 重投
                $message = $service->receiveMessage($queue, self::CONSUMER_WAIT_SECONDS, false);
                if ($message === null) {
                    $state['idle']++;
                    $now = time();
                    if (($now - $state['lastHeartbeat']) >= self::IDLE_HEARTBEAT_SECONDS) {
                        Log::channel('mns')->info(sprintf('[mns] heartbeat queue=%s idle_loops=%d', $queue, $state['idle']));
                        $state['lastHeartbeat'] = $now;
                    }
                    // 空闲时释放数据库连接，避免长连接被 MySQL 回收
                    self::closeDatabase();
                    return;
                }
                $state['idle'] = 0;
                $state['lastHeartbeat'] = time();

                self::reconnectDatabase();
                self::handleMessage($service, $queue, $message);
                self::closeDatabase();
            } catch (\Throwable $e) {
                Log::channel('mns')->error(sprintf(
                    '[mns] consume error: %s @ %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));

                // 数据库连接断开的典型症状，重建 service 与连接
                if (str_contains($e->getMessage(), 'MySQL server has gone away')
                    || str_contains($e->getMessage(), 'Lost connection')) {
                    $state['service'] = null;
                    self::closeDatabase();
                }
                // 避免异常时空转打满 CPU
                usleep(100000);
            }
        });
    }

    /**
     * 智能剪辑轮询：把到期的剪辑任务查一次 IMS，把成片落地。
     *
     * 独立定时器，与 MNS 消费互不阻塞；替代原先单独启动的 smart-edit:worker。
     */
    private static function pollSmartEdit(Worker $worker): void
    {
        Timer::add(self::SMART_EDIT_POLL_INTERVAL, function () use ($worker) {
            if ($worker->stopping) {
                return;
            }
            try {
                self::reconnectDatabase();
                $tasks = SmartEditTask::whereIn('status', SmartEditTask::RUNNING_STATUSES)
                    ->where('provider_job_id', '<>', '')
                    ->where('next_poll_time', '<=', time())
                    ->order('next_poll_time', 'asc')
                    ->limit(self::SMART_EDIT_POLL_BATCH)
                    ->column('id');
                if ($tasks === []) {
                    return;
                }
                foreach ($tasks as $taskId) {
                    try {
                        SmartEditService::refresh((int)$taskId);
                    } catch (\Throwable $e) {
                        Log::channel('mns')->error(sprintf(
                            '[smart_edit] poll failed id=%d %s',
                            (int)$taskId,
                            $e->getMessage()
                        ));
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('mns')->error(sprintf(
                    '[smart_edit] poll error: %s @ %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
                if (str_contains($e->getMessage(), 'MySQL server has gone away')
                    || str_contains($e->getMessage(), 'Lost connection')) {
                    self::closeDatabase();
                }
            }
        });
    }

    /**
     * 处理单条消息。
     *
     * @param array<string,mixed> $message
     */
    private static function handleMessage(LightMqService $service, string $queue, array $message): void
    {
        $payload = $message[LightMqService::RECEIVE_KEY_PAYLOAD] ?? null;
        $receiptHandle = (string)($message[LightMqService::RECEIVE_KEY_RECEIPT_HANDLE] ?? '');
        $messageId = (string)($message[LightMqService::RECEIVE_KEY_MESSAGE_ID] ?? '');
        $dequeueCount = (int)($message[LightMqService::RECEIVE_KEY_DEQUEUE_COUNT] ?? 0);

        if (!is_array($payload) || $payload === []) {
            Log::channel('mns')->error(sprintf('[mns] invalid payload message_id=%s', $messageId));
            self::safeDelete($service, $queue, $receiptHandle);
            return;
        }

        try {
            $job = LightMqService::restoreJob($payload);
            $job->handle();
            $job->clearTenantContext();
            self::safeDelete($service, $queue, $receiptHandle);
            Log::channel('mns')->info(sprintf(
                '[mns] handled queue=%s job=%s job_id=%s message_id=%s',
                $queue,
                get_class($job),
                $job->getId(),
                $messageId
            ));
        } catch (\Throwable $e) {
            self::handleFailure($service, $queue, $payload, $receiptHandle, $messageId, $dequeueCount, $e);
            request()->tenantId = null;
            request()->source = null;
        }
    }

    /**
     * 失败处理：未达上限交给 MNS 重投，达上限记录后删除。
     *
     * @param array<string,mixed> $payload
     */
    private static function handleFailure(
        LightMqService $service,
        string $queue,
        array $payload,
        string $receiptHandle,
        string $messageId,
        int $dequeueCount,
        \Throwable $e
    ): void {
        $jobClass = (string)($payload['data']['job'] ?? 'Unknown');
        $jobId = (string)($payload['id'] ?? '');
        $maxRetry = max(1, (int)config('mns.max_retry', 3));

        Log::channel('mns')->error(sprintf(
            '[mns] failed queue=%s job=%s job_id=%s message_id=%s dequeue=%d/%d error=%s',
            $queue,
            $jobClass,
            $jobId,
            $messageId,
            $dequeueCount,
            $maxRetry,
            $e->getMessage()
        ));

        if ($dequeueCount < $maxRetry) {
            // 不删除：MNS 在 VisibilityTimeout 到期后自动重投
            return;
        }

        try {
            self::reconnectDatabase();
            FailedJob::record($queue, $jobClass, $jobId, $payload, $e, $dequeueCount);
            self::safeDelete($service, $queue, $receiptHandle);
            Log::channel('mns')->error(sprintf('[mns] abandoned job=%s job_id=%s', $jobClass, $jobId));
        } catch (\Throwable $recordError) {
            Log::channel('mns')->error('[mns] record failed job error: ' . $recordError->getMessage());
        }
    }

    private static function safeDelete(LightMqService $service, string $queue, string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            return;
        }
        try {
            $service->deleteMessage($queue, $receiptHandle);
        } catch (\Throwable $e) {
            Log::channel('mns')->error('[mns] delete error: ' . $e->getMessage());
        }
    }

    /**
     * 处理前探活：常驻进程长时间空闲后 MySQL 会断开。
     */
    private static function reconnectDatabase(): void
    {
        try {
            Db::connect('mysql')->query('SELECT 1');
        } catch (\Throwable $e) {
            Log::channel('mns')->warning('[mns] db ping failed, reconnecting: ' . $e->getMessage());
            try {
                Db::connect('mysql', true);
            } catch (\Throwable $reconnectError) {
                Log::channel('mns')->error('[mns] db reconnect failed: ' . $reconnectError->getMessage());
                throw $reconnectError;
            }
        }
    }

    private static function closeDatabase(): void
    {
        try {
            Db::close();
        } catch (\Throwable $e) {
            Log::channel('mns')->warning('[mns] db close failed: ' . $e->getMessage());
        }
    }
}
