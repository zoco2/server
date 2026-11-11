<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use InvalidArgumentException;
use think\facade\Db;

final class UserTaskPlanService
{
    private const TABLE = 'user_task_plan';
    private const BATCH_SIZE = 100;

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
        $schedule = UserTaskSchedule::fromInput($params);
        $next = $schedule->next($now + 1);
        if ($next === null) {
            throw new InvalidArgumentException('有效期内没有可执行的时间');
        }

        $id = Db::name(self::TABLE)->insertGetId($owner + $schedule->toRow() + [
            'name' => trim($name),
            'job_type' => $jobType,
            'next_run_at' => $next,
            'status' => 'enabled',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return ['id' => (int)$id, 'next_run_at' => $next];
    }

    public static function preview(array $params): array
    {
        $schedule = UserTaskSchedule::fromInput($params);
        $from = time() + 1;
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $next = $schedule->next($from);
            if ($next === null) {
                break;
            }
            $runs[] = $next;
            $from = $next + 1;
        }
        return ['next_runs' => $runs, 'timezone' => $schedule->timezone->getName()];
    }

    public static function lists(int $tenantId, int $userId, int $page, int $pageSize): array
    {
        $query = Db::name(self::TABLE)->where(self::owner($tenantId, $userId))->where('status', '<>', 'deleted');
        $result = self::paginate($query, $page, $pageSize);
        foreach ($result['lists'] as &$plan) {
            $plan['schedule_type'] ??= (int)$plan['interval_seconds'] > 0 ? 'interval' : 'once';
            $plan['schedule_config'] = json_decode((string)$plan['schedule_config'], true) ?: [];
        }
        return $result;
    }

    public static function runs(int $tenantId, int $userId, int $id, int $page, int $pageSize): array
    {
        $owner = self::owner($tenantId, $userId);
        if (!Db::name(self::TABLE)->where($owner + ['id' => $id])->value('id')) {
            throw new InvalidArgumentException('计划不存在或无权限');
        }
        $query = Db::name(UserTaskRunService::TABLE)->where($owner + ['plan_id' => $id])
            ->field('id,plan_id,job_type,scheduled_at,status,output,reason,started_at,finished_at');
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
            $schedule = UserTaskSchedule::fromRow($plan);
            // 周期计划按下一次有效时间恢复。单次计划允许在有效期内恢复原来暂停的执行。
            $nextAt = $schedule->type === 'once' ? max($now + 1, (int)$plan['next_run_at']) : $schedule->next($now + 1);
            if ($nextAt !== null && $schedule->hasEnded($nextAt)) {
                $nextAt = null;
            }
            Db::name(self::TABLE)->where($scope)->update([
                'status' => $nextAt === null ? 'finished' : 'enabled',
                'next_run_at' => $nextAt,
                'update_time' => $now,
            ]);
        });
    }

    public static function scheduleDue(?int $now = null): void
    {
        $now ??= time();
        // 停机后恢复时先关闭过期计划，避免补跑已经离开有效期的轮次。
        Db::name(self::TABLE)->whereIn('status', ['enabled', 'paused'])->where('end_at', '<=', $now)
            ->update(['status' => 'finished', 'next_run_at' => null, 'update_time' => $now]);
        $due = ['status' => 'enabled'];
        // 扫描阶段只取候选 ID，每条计划在独立事务中加锁并重新确认到期状态。
        $ids = Db::name(self::TABLE)->where($due)->where('next_run_at', '<=', $now)
            ->order(['next_run_at' => 'asc', 'id' => 'asc'])->limit(self::BATCH_SIZE)->column('id');

        foreach ($ids as $id) {
            Db::transaction(static function () use ($id, $due, $now): void {
                $plan = Db::name(self::TABLE)->where($due + ['id' => $id])->where('next_run_at', '<=', $now)
                    ->lock(true)->find();
                if (!$plan) {
                    return;
                }

                $schedule = UserTaskSchedule::fromRow($plan);
                $scheduledAt = $schedule->latestDue((int)$plan['next_run_at'], $now);
                $nextAt = $schedule->type === 'once' ? null : $schedule->next($scheduledAt + 1);
                // 同一计划只允许一条待执行或执行中的记录，其余到期轮次记为跳过。
                $active = Db::name(UserTaskRunService::TABLE)->where('plan_id', $id)
                    ->whereIn('status', [UserTaskRunService::QUEUED, UserTaskRunService::RUNNING])->value('id');
                Db::name(UserTaskRunService::TABLE)->insert([
                    'plan_id' => $id,
                    'tenant_id' => $plan['tenant_id'],
                    'user_id' => $plan['user_id'],
                    'job_type' => $plan['job_type'],
                    'scheduled_at' => $scheduledAt,
                    'expires_at' => $schedule->endAt,
                    'status' => $active ? 'skipped' : UserTaskRunService::QUEUED,
                    'reason' => $active ? '上一轮尚未结束' : '',
                    'finished_at' => $active ? $now : null,
                ]);
                // 执行记录和下次时间一起提交。单次计划的 finished 表示调度结束，执行结果单独记录。
                Db::name(self::TABLE)->where('id', $id)->update([
                    'status' => $nextAt === null ? 'finished' : 'enabled',
                    'next_run_at' => $nextAt,
                    'update_time' => $now,
                ]);
            });
        }
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
            // 条件更新只取消尚未领取的记录，已经开始的执行继续结束。
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

    private static function paginate(\think\db\BaseQuery $query, int $page, int $pageSize): array
    {
        $count = (clone $query)->count();
        $lists = $query->order('id', 'desc')->page(max(1, $page), min(100, max(1, $pageSize)))->select()->toArray();
        return ['lists' => $lists, 'count' => $count];
    }
}
