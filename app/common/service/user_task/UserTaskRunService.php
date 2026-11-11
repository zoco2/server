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
    private const LEASE_SECONDS = 60;
    private const MAX_ATTEMPTS = 3;
    private const HANDLERS = ['hello_world' => HelloWorldJob::class];

    public static function supports(string $type): bool
    {
        return isset(self::HANDLERS[$type]);
    }

    public static function drain(): void
    {
        // available_at 在排队时是可领取时间，在运行时是租约到期时间。
        $runs = Db::name(self::TABLE)->whereIn('status', [self::QUEUED, self::RUNNING])
            ->where('available_at', '<=', time())
            ->order(['available_at' => 'asc', 'id' => 'asc'])->limit(self::BATCH_SIZE)->select()->toArray();
        foreach ($runs as $run) {
            self::execute($run);
        }
    }

    private static function execute(array $run): void
    {
        $now = time();
        // 候选列表可能已过时，领取时必须仍匹配扫描到的状态和租约令牌。
        $scope = ['id' => $run['id'], 'status' => $run['status'], 'lease_token' => $run['lease_token']];
        $claim = Db::name(self::TABLE)->where($scope)->where('available_at', '<=', $now);
        // 进程反复崩溃时结束这条记录，让该计划可以继续调度后续轮次。
        if ((int)$run['attempts'] >= self::MAX_ATTEMPTS) {
            $claim->update(self::result('failed', null, '执行租约超时，已达到接管次数上限'));
            return;
        }

        $token = bin2hex(random_bytes(16));
        if ($claim->update([
            'status' => self::RUNNING,
            'attempts' => (int)$run['attempts'] + 1,
            'lease_token' => $token,
            'available_at' => $now + self::LEASE_SECONDS,
            'started_at' => $run['started_at'] ?? $now,
        ]) !== 1) {
            return;
        }

        // 租约超时可能触发重跑，有外部副作用的 Job 需要按执行记录 ID 做幂等。
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

        // 租约被接管后，旧进程不能覆盖新进程的结果。
        Db::name(self::TABLE)->where([
            'id' => $run['id'],
            'status' => self::RUNNING,
            'lease_token' => $token,
        ])->update($result);
    }

    private static function result(string $status, ?string $output, string $reason): array
    {
        return [
            'status' => $status,
            'output' => $output,
            'reason' => $reason,
            'lease_token' => '',
            'finished_at' => time(),
        ];
    }
}
