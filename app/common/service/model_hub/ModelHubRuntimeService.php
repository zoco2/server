<?php

declare(strict_types=1);

namespace app\common\service\model_hub;

use app\common\model\ai\ModelHubCall;
use app\common\service\point\PointService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\db\exception\PDOException;
use think\facade\Db;

final class ModelHubRuntimeService
{
    // 测试单价同时用于租户成本和用户售价，单位为服务端算力点。
    private const TEST_PRICES = ['chat' => ['model-hub-demo' => 1.0]];

    /**
     * 调用方从已认证的业务请求传入请求号。执行器接收能力、模型和请求参数。
     *
     * @param array{capability: string, model: string, estimated_units: int, parameters: array} $request
     * @param callable(string, string, array): array{status: 'success'|'failed', usage_units?: int, result?: array} $execute
     *        执行器在明确失败时返回 failed。超时等无法确认受理的情况抛异常。
     */
    public static function run(string $requestKey, array $request, callable $execute): ModelHubCall
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        $userId = (int)(request()->userId ?? 0);
        if ($tenantId <= 0 || $userId <= 0) {
            throw new RuntimeException('模型调用需要已登录的租户用户');
        }

        $capability = $request['capability'];
        $model = $request['model'];
        $estimatedUnits = $request['estimated_units'];
        $parameters = $request['parameters'];
        $unitPrice = self::TEST_PRICES[$capability][$model] ?? null;
        if (trim($requestKey) === '' || $estimatedUnits <= 0 || $unitPrice === null) {
            throw new InvalidArgumentException('需要有效的请求号、模型和预估用量');
        }

        $callKey = hash('sha256', $tenantId . ':' . $userId . ':' . $requestKey);
        $requestHash = hash('sha256', json_encode([$capability, $model, $estimatedUnits, $parameters], JSON_THROW_ON_ERROR));
        $pointSource = 'MH-' . substr($callKey, 0, 48);
        $reservedPoints = $estimatedUnits * $unitPrice;

        // 调用单和预占一起提交，事务成功后才允许执行模型。
        try {
            $call = Db::transaction(static function () use (
                $tenantId, $userId, $callKey, $requestHash, $capability, $model, $unitPrice,
                $estimatedUnits, $reservedPoints, $pointSource
            ): ModelHubCall {
                $call = ModelHubCall::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'call_key' => $callKey,
                    'request_hash' => $requestHash,
                    'capability' => $capability,
                    'model' => $model,
                    'unit_price' => $unitPrice,
                    'estimated_units' => $estimatedUnits,
                    'reserved_points' => $reservedPoints,
                    'status' => 'running',
                ]);
                PointService::reserveBusinessAmountsInCurrentTransaction(
                    $tenantId, $userId, $reservedPoints, $reservedPoints, $pointSource . '-reserve',
                    'Model Hub 预占', ['model_hub_call_id' => (int)$call['id']]
                );
                return $call;
            });
        } catch (PDOException $error) {
            if (($error->getData()['PDO Error Info']['SQLSTATE'] ?? '') !== '23000') {
                throw $error;
            }
            // 唯一键保护并发重试。只认当前用户已有的同一笔调用。
            $existing = ModelHubCall::where('user_id', $userId)->where('call_key', $callKey)->findOrEmpty();
            if ($existing->isEmpty()) {
                throw $error;
            }
            if ($existing['request_hash'] !== $requestHash) {
                throw new InvalidArgumentException('该请求号已用于其他模型参数');
            }
            return $existing;
        }

        try {
            $receipt = $execute($capability, $model, $parameters);
        } catch (Throwable) {
            return self::review($call, 'upstream_unknown');
        }

        $status = $receipt['status'] ?? '';
        $units = $receipt['usage_units'] ?? null;
        $call->result = $receipt['result'] ?? [];
        if ($status !== 'failed' && ($status !== 'success' || !is_int($units) || $units < 0)) {
            return self::review($call, 'usage_unconfirmed');
        }

        $failed = $status === 'failed';
        $actualUnits = $failed ? 0 : $units;
        $actualPoints = $actualUnits * (float)$call['unit_price'];
        // 先保存用量和结果。补扣失败时可核对同一笔调用，预占仍有效。
        $call->save(['status' => 'settling', 'actual_units' => $actualUnits]);

        try {
            Db::transaction(static function () use (
                $call, $tenantId, $userId, $reservedPoints, $actualPoints, $pointSource, $failed
            ): void {
                $extra = ['model_hub_call_id' => (int)$call['id']];
                if ($failed) {
                    PointService::releaseReservedBusinessAmountsInCurrentTransaction(
                        $tenantId, $userId, $reservedPoints, $reservedPoints, $pointSource . '-release',
                        'Model Hub 执行失败退款', $extra
                    );
                } else {
                    PointService::settleReservedBusinessAmountsInCurrentTransaction(
                        $tenantId, $userId, $reservedPoints, $reservedPoints,
                        $actualPoints, $actualPoints, $pointSource, 'Model Hub 结算', $extra
                    );
                }
                $call->save([
                    'status' => $failed ? 'refunded' : 'settled',
                    'charged_points' => $actualPoints,
                ]);
            });
        } catch (Throwable) {
            return self::review($call, 'settlement_failed');
        }
        return $call;
    }

    private static function review(ModelHubCall $call, string $reason): ModelHubCall
    {
        $call->save(['status' => 'needs_review', 'reason' => $reason]);
        return $call;
    }
}
