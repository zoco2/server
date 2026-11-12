<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use app\common\job\HelloWorldJob;
use think\facade\Db;
use think\facade\Log;
use Throwable;
use Workerman\Worker;

final class UserTaskRunService
{
    public const TABLE = 'user_task_run';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';

    private const BATCH_SIZE = 20;
    private const HANDLERS = ['hello_world' => HelloWorldJob::class];

    public static function supports(string $type): bool
    {
        return $type === GeoRankingTaskJob::TYPE || isset(self::HANDLERS[$type]);
    }

    public static function drain(): void
    {
        $runs = Db::name(self::TABLE)->where('status', self::QUEUED)
            ->field('id,plan_id,job_type,expires_at')->order('id', 'asc')->limit(self::BATCH_SIZE)->select()->toArray();
        foreach ($runs as $run) {
            self::execute($run);
        }
    }

    private static function execute(array $run): void
    {
        $queued = ['id' => $run['id'], 'status' => self::QUEUED];
        $now = time();
        $expiresAt = isset($run['expires_at']) ? (int)$run['expires_at'] : null;
        if ($expiresAt !== null && $now >= $expiresAt) {
            Db::name(self::TABLE)->where($queued)->update(self::result('expired', null, '计划有效期已结束'));
            return;
        }

        if ($run['job_type'] === GeoRankingTaskJob::TYPE) {
            GeoRankingTaskJob::start((int)$run['id'], $expiresAt);
            return;
        }

        // 多个 Worker 可能看到同一候选记录，只有将 queued 改为 running 的进程可以执行。
        $claimed = Db::name(self::TABLE)->where($queued)->update(['status' => self::RUNNING, 'started_at' => $now]);
        if ($claimed !== 1) {
            return;
        }

        // 领取可能等待数据库锁，执行前再判断一次是否跨过有效期。
        if ($expiresAt !== null && time() >= $expiresAt) {
            Db::name(self::TABLE)->where(['id' => $run['id'], 'status' => self::RUNNING])
                ->update(self::result('expired', null, '计划有效期已结束') + ['started_at' => null]);
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

        $updated = Db::name(self::TABLE)->where(['id' => $run['id'], 'status' => self::RUNNING])->update($result);
        if ($updated !== 1) {
            return;
        }

        // 结果落库后再记日志。演示任务的固定输出可直接用来确认执行成功。
        $message = sprintf(
            '[user-task-runner] plan_id=%d run_id=%d job=%s status=%s',
            $run['plan_id'],
            $run['id'],
            $run['job_type'],
            $result['status']
        );
        if ($handler === HelloWorldJob::class && $result['status'] === 'success') {
            $message .= ' output=' . $result['output'];
        } elseif ($result['reason'] !== '') {
            $message .= ' reason=' . $result['reason'];
        }
        Worker::log($message);
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
