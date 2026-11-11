<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use InvalidArgumentException;
use think\facade\Db;

final class UserTaskPlanService
{
    private const TABLE = 'user_task_plan';
    private const BATCH_SIZE = 100;
    private const MAX_TIMESTAMP = 4294967295;

    public static function create(int $tenantId, int $userId, array $params): array
    {
        $owner = self::owner($tenantId, $userId);
        $now = time();
        $name = $params['name'] ?? '';
        $jobType = $params['job_type'] ?? 'hello_world';
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 100) {
            throw new InvalidArgumentException('请输入 1 到 100 字的计划名称');
        }
        if (!is_string($jobType) || !UserTaskRunService::supports($jobType)) {
            throw new InvalidArgumentException('任务类型暂不支持');
        }
        $startAt = self::integer($params['start_at'] ?? null, $now + 1, '开始时间须晚于当前时间');
        $interval = self::integer($params['interval_seconds'] ?? 0, 0, '执行间隔必须是非负整数秒');

        $id = Db::name(self::TABLE)->insertGetId($owner + [
            'name' => trim($name),
            'job_type' => $jobType,
            'start_at' => $startAt,
            'interval_seconds' => $interval,
            'next_run_at' => $startAt,
            'status' => 'enabled',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return ['id' => (int)$id, 'next_run_at' => $startAt];
    }

    public static function lists(int $tenantId, int $userId, int $page, int $pageSize): array
    {
        $query = Db::name(self::TABLE)->where(self::owner($tenantId, $userId))->where('status', '<>', 'deleted');
        return self::paginate($query, $page, $pageSize);
    }

    public static function runs(int $tenantId, int $userId, int $id, int $page, int $pageSize): array
    {
        $owner = self::owner($tenantId, $userId);
        if (!Db::name(self::TABLE)->where($owner + ['id' => $id])->value('id')) {
            throw new InvalidArgumentException('计划不存在或无权限');
        }
        $query = Db::name(UserTaskRunService::TABLE)->where($owner + ['plan_id' => $id])
            ->field('id,plan_id,job_type,scheduled_at,status,attempts,output,reason,started_at,finished_at');
        return self::paginate($query, $page, $pageSize);
    }

    public static function pause(int $tenantId, int $userId, int $id): void
    {
        self::stop($tenantId, $userId, $id, false);
    }

    public static function delete(int $tenantId, int $userId, int $id): void
    {
        self::stop($tenantId, $userId, $id, true);
    }

    public static function resume(int $tenantId, int $userId, int $id): void
    {
        $scope = self::owner($tenantId, $userId) + ['id' => $id];
        Db::transaction(static function () use ($scope): void {
            $plan = self::lockedPlan($scope);
            if ($plan['status'] === 'enabled') {
                return;
            }
            if ($plan['status'] !== 'paused') {
                throw new InvalidArgumentException('该计划已结束');
            }

            $now = time();
            $nextAt = (int)$plan['next_run_at'];
            $interval = (int)$plan['interval_seconds'];
            if ($nextAt <= $now) {
                $nextAt = $interval > 0 ? self::latestDue($nextAt, $interval, $now) + $interval : $now + 1;
            }
            Db::name(self::TABLE)->where($scope)->update([
                'status' => 'enabled',
                'next_run_at' => $nextAt,
                'update_time' => $now,
            ]);
        });
    }

    public static function scheduleDue(?int $now = null): void
    {
        $now ??= time();
        $due = ['status' => 'enabled'];
        $ids = Db::name(self::TABLE)->where($due)->where('next_run_at', '<=', $now)
            ->order(['next_run_at' => 'asc', 'id' => 'asc'])->limit(self::BATCH_SIZE)->column('id');

        foreach ($ids as $id) {
            Db::transaction(static function () use ($id, $due, $now): void {
                $plan = Db::name(self::TABLE)->where($due + ['id' => $id])->where('next_run_at', '<=', $now)
                    ->lock(true)->find();
                if (!$plan) {
                    return;
                }

                $interval = (int)$plan['interval_seconds'];
                $scheduledAt = self::latestDue((int)$plan['next_run_at'], $interval, $now);
                $active = Db::name(UserTaskRunService::TABLE)->where('plan_id', $id)
                    ->whereIn('status', [UserTaskRunService::QUEUED, UserTaskRunService::RUNNING])->value('id');
                Db::name(UserTaskRunService::TABLE)->insert([
                    'plan_id' => $id,
                    'tenant_id' => $plan['tenant_id'],
                    'user_id' => $plan['user_id'],
                    'job_type' => $plan['job_type'],
                    'scheduled_at' => $scheduledAt,
                    'status' => $active ? 'skipped' : UserTaskRunService::QUEUED,
                    'available_at' => $now,
                    'reason' => $active ? '上一轮尚未结束' : '',
                    'finished_at' => $active ? $now : null,
                ]);
                Db::name(self::TABLE)->where('id', $id)->update([
                    'status' => $interval > 0 ? 'enabled' : 'finished',
                    'next_run_at' => $interval > 0 ? $scheduledAt + $interval : null,
                    'update_time' => $now,
                ]);
            });
        }
    }

    private static function latestDue(int $nextAt, int $interval, int $now): int
    {
        return $interval > 0 ? $nextAt + intdiv($now - $nextAt, $interval) * $interval : $nextAt;
    }

    private static function stop(int $tenantId, int $userId, int $id, bool $delete): void
    {
        $scope = self::owner($tenantId, $userId) + ['id' => $id];
        Db::transaction(static function () use ($scope, $id, $delete): void {
            $plan = self::lockedPlan($scope);
            if (!$delete && $plan['status'] === 'finished') {
                throw new InvalidArgumentException('该计划已结束');
            }

            $now = time();
            Db::name(self::TABLE)->where($scope)->update([
                'status' => $delete ? 'deleted' : 'paused',
                'next_run_at' => $delete ? null : $plan['next_run_at'],
                'update_time' => $now,
            ]);
            Db::name(UserTaskRunService::TABLE)->where(['plan_id' => $id, 'status' => UserTaskRunService::QUEUED])
                ->update([
                    'status' => 'cancelled',
                    'reason' => $delete ? '计划已删除' : '计划已暂停',
                    'finished_at' => $now,
                ]);
        });
    }

    private static function lockedPlan(array $scope): array
    {
        $plan = Db::name(self::TABLE)->where($scope)->where('status', '<>', 'deleted')->lock(true)->find();
        if (!$plan) {
            throw new InvalidArgumentException('计划不存在或无权限');
        }
        return $plan;
    }

    private static function owner(int $tenantId, int $userId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('当前用户无法管理计划任务');
        }
        return ['tenant_id' => $tenantId, 'user_id' => $userId];
    }

    private static function integer(mixed $value, int $min, string $error): int
    {
        $number = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => [
                'min_range' => $min,
                'max_range' => self::MAX_TIMESTAMP,
            ]])
            : false;
        if ($number === false) {
            throw new InvalidArgumentException($error);
        }
        return $number;
    }

    private static function paginate(\think\db\BaseQuery $query, int $page, int $pageSize): array
    {
        $count = (clone $query)->count();
        $lists = $query->order('id', 'desc')->page(max(1, $page), min(100, max(1, $pageSize)))->select()->toArray();
        return ['lists' => $lists, 'count' => $count];
    }
}
