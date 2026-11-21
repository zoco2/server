<?php

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\point\PointService;
use app\common\service\pricing\BusinessPriceService;
use RuntimeException;
use think\facade\Db;

class SmartEditBillingService
{
    public static function quote(int $tenantId, int $userId, int $count, array $input): int
    {
        $points = BusinessPriceService::smartEditPoints($tenantId);
        if (isset($input['expected_unit_points']) && (string)$input['expected_unit_points'] !== (string)$points) {
            throw new RuntimeException('剪辑单价已更新，请确认新价格后重新提交');
        }
        PointService::assertCanConsumeAmounts($tenantId, $userId, 0, $points * $count);
        return $points;
    }

    /** 调用方在建任务的事务内扣款。镜头组只生成一笔批量扣款流水。 */
    public static function charge(int $tenantId, int $userId, int $unitPoints, int $count, string $sourceSn): void
    {
        if ($unitPoints > 0 && $count > 0) {
            PointService::consumeBusinessAmountsInCurrentTransaction(
                $tenantId, $userId, 0, $unitPoints * $count, $sourceSn, '智能剪辑',
                ['unit_points' => $unitPoints, 'quantity' => $count]
            );
        }
    }

    /** 终态处理前锁任务，并确认消息或轮询结果仍属于当前提交轮次。 */
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

    public static function attempt(SmartEditTask $task): int
    {
        return (int)($task['params_json']['billing_attempt'] ?? 0);
    }

    public static function fail(SmartEditTask $task, array $failure): void
    {
        if (!in_array((string)$task['status'], SmartEditTask::RUNNING_STATUSES, true)) {
            return;
        }
        self::withCurrentTask($task, static function (SmartEditTask $locked) use ($failure) {
            self::refund($locked);
            $locked->save($failure);
        });
    }

    /** 调用方持有任务行锁，成功成片保留扣款，其他任务按该条原价退回。 */
    public static function refund(SmartEditTask $task): void
    {
        $points = (int)$task['user_charge_points'];
        if ($points <= 0) {
            return;
        }
        PointService::refundBusinessAmountsInCurrentTransaction(
            (int)$task['tenant_id'], (int)$task['user_id'], 0, $points,
            $task['task_no'] . '-' . self::attempt($task), '智能剪辑退回',
            ['task_no' => (string)$task['task_no'], 'batch_no' => (string)$task['batch_no']]
        );
        $task['user_charge_points'] = 0;
    }
}
