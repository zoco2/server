<?php

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditGroup;
use app\common\model\smart_edit\SmartEditTask;
use app\common\service\ConfigService;
use app\common\service\point\PointService;
use RuntimeException;
use think\facade\Db;

class SmartEditBillingService
{
    /** 读取平台设置的成片单价，未配置时默认 0 点。 */
    public static function unitPoints(): int
    {
        $settings = ConfigService::getPlatform('business_price', 'settings', []);
        return (int)($settings['smart_edit_points'] ?? 0);
    }

    public static function quote(array $input): int
    {
        $points = self::unitPoints();
        if (isset($input['expected_unit_points']) && (string)$input['expected_unit_points'] !== (string)$points) {
            throw new RuntimeException('剪辑单价已更新，请确认新价格后重新提交');
        }
        return $points;
    }

    /** 按提交时已知的总条数预占。余额校验、预占和创建任务在同一事务。 */
    public static function reserve(int $tenantId, int $userId, int $unitPoints, int $count, string $sourceSn): void
    {
        if ($unitPoints > 0 && $count > 0) {
            PointService::reserveBusinessAmountsInCurrentTransaction(
                $tenantId, $userId, 0, $unitPoints * $count, $sourceSn, '智能剪辑预占',
                ['unit_points' => $unitPoints, 'quantity' => $count]
            );
        }
    }

    /** 终态或重试先锁整组，再锁子任务。普通状态更新只锁子任务。 */
    public static function withCurrentTask(SmartEditTask $expected, callable $action, bool $lockGroup = false): bool
    {
        return Db::transaction(static function () use ($expected, $action, $lockGroup) {
            $group = $lockGroup && (string)$expected['batch_no'] !== '' ? SmartEditGroup::where([
                'tenant_id' => (int)$expected['tenant_id'],
                'user_id' => (int)$expected['user_id'],
                'batch_no' => (string)$expected['batch_no'],
            ])->lock(true)->find() : null;
            $task = SmartEditTask::where('id', (int)$expected['id'])->where('tenant_id', (int)$expected['tenant_id'])
                ->where('user_id', (int)$expected['user_id'])->lock(true)->findOrEmpty();
            if ($task->isEmpty() || (string)$task['status'] !== (string)$expected['status']
                || (string)$task['provider_job_id'] !== (string)$expected['provider_job_id']
                || self::attempt($task) !== self::attempt($expected)) {
                return false;
            }
            $action($task, $group);
            return true;
        });
    }

    /** 首次提交为第 0 轮，手动重试进入新的预占轮次。 */
    public static function attempt(SmartEditTask $task): int
    {
        return (int)($task['params_json']['billing_attempt'] ?? 0);
    }

    public static function fail(SmartEditTask $task, array $failure): void
    {
        self::finish($task, static fn(SmartEditTask $locked) => $locked->save($failure));
    }

    /** 投递失败和重投耗尽共用同一终态处理，旧消息不能结束新提交。 */
    public static function failSubmission(int $tenantId, array $ids, int $attempt, array $failure): void
    {
        $tasks = SmartEditTask::where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->where('status', SmartEditTask::STATUS_SUBMITTING)->select();
        foreach ($tasks as $task) {
            if (self::attempt($task) === $attempt) {
                self::fail($task, $failure);
            }
        }
    }

    /** 成片终态和整次结算放在同一个事务内。 */
    public static function finish(SmartEditTask $task, callable $transition, bool $delete = false): bool
    {
        return self::withCurrentTask($task, static function (SmartEditTask $locked, ?SmartEditGroup $group) use ($transition, $delete) {
            if (in_array((string)$locked['status'], SmartEditTask::RUNNING_STATUSES, true)) {
                $transition($locked);
                self::settleFinishedTask($locked, $group);
            }
            if ($delete) {
                $locked->delete();
            }
        }, true);
    }

    /** 删除的成片也计入原提交，避免少结算已交付视频。 */
    private static function settleFinishedTask(SmartEditTask $task, ?SmartEditGroup $group): void
    {
        if ((int)($group['config_json']['reserved_points'] ?? 0) > 0) {
            $tasks = SmartEditTask::withTrashed()->where([
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
                'batch_no' => (string)$task['batch_no'],
            ]);
            // 使用加锁读，读取其他成片已提交的最新终态。
            if ((clone $tasks)->whereIn('status', SmartEditTask::RUNNING_STATUSES)->lock(true)->value('id') !== null) {
                return;
            }
            $succeeded = $tasks->where('status', SmartEditTask::STATUS_SUCCESS);
            $count = (clone $succeeded)->field('id')->lock(true)->select()->count();
            self::settleGroup($group, $count);
            if ($count > 0) {
                $succeeded->update(['user_charge_points' => (int)$group['config_json']['unit_points']]);
            }
            return;
        }

        // 组已结束时只有新预占的重试需要结算，旧成片由整组负责。
        if ((string)$task['batch_no'] !== '' && self::attempt($task) === 0) {
            return;
        }
        $unit = (int)($task['params_json']['unit_points'] ?? 0);
        $actual = $task['status'] === SmartEditTask::STATUS_SUCCESS ? $unit : 0;
        self::settle(
            (int)$task['tenant_id'], (int)$task['user_id'], $unit, $actual,
            $task['task_no'] . '-' . self::attempt($task)
        );
        $task->save(['user_charge_points' => $actual]);
    }

    /** 组行已加锁。组结束只结算一次，展开前失败按 0 条结算。 */
    public static function settleGroup(SmartEditGroup $group, int $succeeded): void
    {
        $config = (array)$group['config_json'];
        $reserved = (int)($config['reserved_points'] ?? 0);
        if ($reserved <= 0) {
            return;
        }
        $actual = $succeeded * (int)$config['unit_points'];
        self::settle((int)$group['tenant_id'], (int)$group['user_id'], $reserved, $actual, (string)$group['batch_no']);
        $config['reserved_points'] = 0;
        $config['settled_points'] = $actual;
        $group->save(['config_json' => $config]);
    }

    private static function settle(int $tenantId, int $userId, int $reserved, int $actual, string $sourceSn): void
    {
        if ($reserved > 0) {
            // 预占已占用可用余额。结算保留成功用量，一次释放剩余点数。
            PointService::settleReservedBusinessAmountsInCurrentTransaction(
                $tenantId, $userId, 0, $reserved, 0, $actual, $sourceSn, '智能剪辑'
            );
        }
    }
}