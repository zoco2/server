<?php

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\point\PointService;
use app\common\service\ConfigService;
use RuntimeException;
use think\facade\Db;

class SmartEditBillingService
{
    /** 读取租户后台配置。尚未设价时每条成片按 0 点计。 */
    public static function unitPoints(): int
    {
        $settings = ConfigService::get('business_price', 'settings', []);
        return (int)($settings['smart_edit_points'] ?? 0);
    }

    /** 提交前核对页面报价和余额。实际扣点在创建任务的事务里进行。 */
    public static function quote(int $tenantId, int $userId, int $count, array $input): int
    {
        $points = self::unitPoints();
        if (isset($input['expected_unit_points']) && (string)$input['expected_unit_points'] !== (string)$points) {
            throw new RuntimeException('剪辑单价已更新，请确认新价格后重新提交');
        }
        PointService::assertCanConsumeAmounts($tenantId, $userId, 0, $points * $count);
        return $points;
    }

    /** 建任务和扣点同事务。组任务以批次号记一笔流水，退款按单条成片记录。 */
    public static function charge(int $tenantId, int $userId, int $unitPoints, int $count, string $sourceSn): void
    {
        if ($unitPoints > 0 && $count > 0) {
            PointService::consumeBusinessAmountsInCurrentTransaction(
                $tenantId, $userId, 0, $unitPoints * $count, $sourceSn, '智能剪辑',
                ['unit_points' => $unitPoints, 'quantity' => $count]
            );
        }
    }

    /** 锁定后复核状态、上游任务号和提交轮次，拦住取消或重试后才到达的旧结果。 */
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

    /** 首次提交的轮次为 0，每次手动重试递增一次。 */
    public static function attempt(SmartEditTask $task): int
    {
        return (int)($task['params_json']['billing_attempt'] ?? 0);
    }

    /** 用同一个事务保存退款和失败状态，重复到达的失败消息会被状态检查拦住。 */
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

    /** 调用方持有任务行锁。退回本轮实扣点数，余额流水和任务实扣字段一并保存。 */
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
        // 清零本轮实扣，任务保存后再次处理会跳过退款。
        $task['user_charge_points'] = 0;
    }
}
