<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use app\common\job\HelloWorldJob;
use think\facade\Db;
use think\facade\Log;
use Throwable;

final class UserTaskRunService
{
    public const TABLE = 'user_task_run';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';

    private const BATCH_SIZE = 20;
    private const HANDLERS = ['hello_world' => HelloWorldJob::class];

    public static function supports(string $type): bool
    {
        return isset(self::HANDLERS[$type]);
    }

    public static function drain(): void
    {
        $runs = Db::name(self::TABLE)->where('status', self::QUEUED)
            ->field('id,job_type')->order('id', 'asc')->limit(self::BATCH_SIZE)->select()->toArray();
        foreach ($runs as $run) {
            self::execute($run);
        }
    }

    private static function execute(array $run): void
    {
        // 多个 Worker 可能看到同一候选记录，只有将 queued 改为 running 的进程可以执行。
        $claimed = Db::name(self::TABLE)->where(['id' => $run['id'], 'status' => self::QUEUED])
            ->update(['status' => self::RUNNING, 'started_at' => time()]);
        if ($claimed !== 1) {
            return;
        }

        $handler = self::HANDLERS[$run['job_type']] ?? null;
        if ($handler === null) {
            $result = self::result('failed', null, '任务类型暂不支持');
        } else {
            try {
                $output = (new $handler())->handle();
                $result = self::result('success', $output, '');
            } catch (Throwable $e) {
                Log::error(sprintf('[user-task] run=%d handler=%s error=%s', $run['id'], $handler, $e->getMessage()));
                $result = self::result('failed', null, '任务执行失败');
            }
        }

        Db::name(self::TABLE)->where(['id' => $run['id'], 'status' => self::RUNNING])->update($result);
    }

    private static function result(string $status, ?string $output, string $reason): array
    {
        return [
            'status' => $status,
            'output' => $output,
            'reason' => $reason,
            'finished_at' => time(),
        ];
    }
}
