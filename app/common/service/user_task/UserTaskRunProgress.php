<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use app\common\service\geo\AigcGeoManuscriptService;
use InvalidArgumentException;
use think\facade\Db;

final class UserTaskRunProgress
{
    private const TABLE = 'user_task_run_event';
    private const PAGE_SIZE = 100;

    public static function record(int $runId, string $step, string $status, string $message, array $details = []): void
    {
        Db::name(self::TABLE)->insert([
            'run_id' => $runId,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'create_time' => time(),
        ]);
    }

    public static function read(int $tenantId, int $userId, int $runId, int $afterId): array
    {
        if ($tenantId <= 0 || $userId <= 0 || $runId <= 0) {
            throw new InvalidArgumentException('执行记录不存在或无权限');
        }
        $run = Db::name(UserTaskRunService::TABLE)
            ->where(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId])
            ->field('id,job_type,status,reason,output,started_at,finished_at')->find();
        if (!$run) {
            throw new InvalidArgumentException('执行记录不存在或无权限');
        }
        $events = Db::name(self::TABLE)->where('run_id', $runId)->where('id', '>', max(0, $afterId))
            ->order('id', 'asc')->limit(self::PAGE_SIZE + 1)->select()->toArray();
        $hasMore = count($events) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($events);
        }
        foreach ($events as &$event) {
            $event['details'] = json_decode($event['details'], true, flags: JSON_THROW_ON_ERROR);
            if ($event['step'] === 'submission' && isset($event['details']['failure_reason'])) {
                $event['details']['failure_reason'] = AigcGeoManuscriptService::publicMessage($event['details']['failure_reason']);
            }
        }
        unset($event);
        return ['run' => $run, 'events' => $events, 'has_more' => $hasMore];
    }
}
