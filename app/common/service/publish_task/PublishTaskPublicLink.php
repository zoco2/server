<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;

/** 验证发给员工的任务链接，只公开发布所需的字段。 */
final class PublishTaskPublicLink
{
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{40,160}';

    /** 每个接收人生成独立链接，数据库只保存校验值。 */
    public static function create(int $tenantId, int $runId, int $staffId, string $appId, string $openid): array
    {
        $payload = implode(':', [$tenantId, $runId, $staffId, bin2hex(random_bytes(24))]);
        $token = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return [$token, self::hash($token, $appId, $openid)];
    }

    /** 共享域名上先校验链接，再建立请求的租户上下文。 */
    public static function tenantId(string $token): int
    {
        return (int)(self::resolve($token)['tenant_id'] ?? 0);
    }

    public static function detail(string $token, int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }
        $link = self::resolve($token, $tenantId);
        if ($link === null) {
            return null;
        }
        $brandId = (int)MerchantStore::where(['id' => $link['store_id'], 'user_id' => $link['user_id']])
            ->value('brand_id');
        $brandName = $brandId > 0
            ? (string)MerchantBrand::where(['id' => $brandId, 'user_id' => $link['user_id']])->value('name')
            : '';
        return [
            'content' => (string)$link['task']['content'],
            'platforms' => json_decode($link['task']['platforms'], true, flags: JSON_THROW_ON_ERROR),
            'brand_name' => $brandName,
        ];
    }

    private static function resolve(string $token, int $expectedTenantId = 0): ?array
    {
        if (!preg_match('/^' . self::TOKEN_PATTERN . '$/D', $token)) {
            return null;
        }
        $payload = base64_decode(strtr($token, '-_', '+/'), true);
        if ($payload === false || !preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):[a-f0-9]{48}$/D', $payload, $matches)) {
            return null;
        }
        [$tenantId, $runId, $staffId] = array_map('intval', array_slice($matches, 1));
        if ($expectedTenantId > 0 && $tenantId !== $expectedTenantId) {
            return null;
        }

        // 此查询也用于共享域名的租户解析，因此每条查询显式限定租户。
        $run = PublishTaskRun::withoutGlobalScope()->where(['tenant_id' => $tenantId, 'id' => $runId])
            ->field('task_id,user_id,recipient_results')->findOrEmpty();
        if ($run->isEmpty()) {
            return null;
        }
        $results = json_decode($run->recipient_results ?? '{}', true);
        $recipient = $results[$staffId] ?? [];
        $savedHash = $recipient['public_link_hash'] ?? '';
        if (!is_string($savedHash) || strlen($savedHash) !== 64
            || !in_array($recipient['last_run_status'] ?? '', ['sending', 'sent', 'unknown'], true)) {
            return null;
        }

        $owner = ['tenant_id' => $tenantId, 'user_id' => (int)$run->user_id];
        $employee = MerchantStaff::withoutGlobalScope()->where($owner)
            ->where('id', $staffId)->whereNull('delete_time')->field('store_id,appid,openid')->findOrEmpty();
        if ($employee->isEmpty() || !$employee->appid || !$employee->openid
            || !hash_equals($savedHash, self::hash($token, trim((string)$employee->appid), trim((string)$employee->openid)))) {
            return null;
        }

        $task = PublishTask::withoutGlobalScope()->where($owner)
            ->where('id', (int)$run->task_id)->field('staff_ids,content,platforms')->findOrEmpty();
        $staffIds = json_decode($task->staff_ids ?? '[]', true);
        if ($task->isEmpty() || !is_array($staffIds) || !in_array($staffId, $staffIds, true)) {
            return null;
        }
        return $owner + ['store_id' => (int)$employee->store_id, 'task' => $task->getData()];
    }

    private static function hash(string $token, string $appId, string $openid): string
    {
        return hash('sha256', implode("\0", ['publish_task_link', $token, $appId, $openid]));
    }
}
