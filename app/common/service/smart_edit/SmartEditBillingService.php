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
    /** 读取当前租户设置的成片单价，未配置时默认 0 点。 */
    public static function unitPoints(): int
    {
        $settings = ConfigService::get('business_price', 'settings', []);
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

    /** 锁定后复核状态、上游任务号和提交轮次，只处理当前提交。 */
    public static function withCurrentTask(SmartEditTask $expected, callable $action): bool
    {
        return Db::transaction(static function () use ($expected, $action) {
            $task = SmartEditTask::where('id', (int)$expected['id'])->where('tenant_id', (int)$expected['tenant_id'])
                ->where('user_id', (int)$expected['user_id'])->lock(true)->findOrEmpty();
            if ($task->isEmpty() || (string)$task['status'] !== (string)$expected['status']
                || (string)$task['provider_job_id'] !== (string)$expected['provider_job_id']
                || self::attempt($task) !== self::attempt($expected)) {
                return false;
            }
            $action($task);
            return true;
        });
    }

    /** 先锁组、再锁成片。终态、取消和重试使用同一顺序，最后一个终态才结算整组。 */
    public static function withTaskGroup(SmartEditTask $expected, callable $action): bool
    {
        return Db::transaction(static function () use ($expected, $action) {
            $group = (string)$expected['batch_no'] === '' ? null : SmartEditGroup::where([
                'tenant_id' => (int)$expected['tenant_id'],
                'user_id' => (int)$expected['user_id'],
                'batch_no' => (string)$expected['batch_no'],
            ])->lock(true)->find();
            return self::withCurrentTask($expected, static fn(SmartEditTask $task) => $action($task, $group));
        });
    }

    /** 首次提交为第 0 轮，手动重试进入新的预占轮次。 */
    public static function attempt(SmartEditTask $task): int
    {
        return (int)($task['params_json']['billing_attempt'] ?? 0);
    }

    public static function fail(SmartEditTask $task, array $failure): void
    {
        if (!in_array((string)$task['status'], SmartEditTask::RUNNING_STATUSES, true)) {
            return;
        }
        self::withTaskGroup($task, static function (SmartEditTask $locked, ?SmartEditGroup $group) use ($failure) {
            $locked->save($failure);
            self::settleFinishedTask($locked, $group);
        });
    }

    /** 调用方先写入成片终态。删除的成片也计入原提交，避免少结算已交付视频。 */
    public static function settleFinishedTask(SmartEditTask $task, ?SmartEditGroup $group): void
    {
        if ((string)$task['batch_no'] !== '' && self::attempt($task) === 0) {
            if ($group === null || (int)($group['config_json']['reserved_points'] ?? 0) <= 0) {
                return;
            }
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

        // 单条提交和整组结束后的单条重试都是数量为 1 的预占。
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