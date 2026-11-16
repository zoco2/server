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
    /** 每个接收人生成独立链接，数据库只保存校验值。 */
    public static function create(int $tenantId, int $runId, int $staffId, string $appId, string $openid): array
    {
        $payload = implode(':', [$tenantId, $runId, $staffId, bin2hex(random_bytes(24))]);
        $token = base64_encode($payload);
        return [$token, self::hash($token, $appId, $openid)];
    }

    public static function detail(string $token, int $tenantId): ?array
    {
        if ($tenantId <= 0 || strlen($token) > 160) {
            return null;
        }
        $payload = base64_decode($token, true);
        if ($payload === false || !preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):[a-f0-9]{48}$/D', $payload, $matches)) {
            return null;
        }
        [$linkTenantId, $runId, $staffId] = array_map('intval', array_slice($matches, 1));
        if ($linkTenantId !== $tenantId) {
            return null;
        }

        // 使用现有租户上下文查询本轮执行记录和接收人。
        $run = PublishTaskRun::where('id', $runId)
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

        $userId = (int)$run->user_id;
        $employee = MerchantStaff::where(['id' => $staffId, 'user_id' => $userId])
            ->field('store_id,appid,openid')->findOrEmpty();
        if ($employee->isEmpty() || !$employee->appid || !$employee->openid
            || !hash_equals($savedHash, self::hash($token, trim((string)$employee->appid), trim((string)$employee->openid)))) {
            return null;
        }

        $task = PublishTask::where(['id' => (int)$run->task_id, 'user_id' => $userId])
            ->field('staff_ids,content,platforms')->findOrEmpty();
        $staffIds = json_decode($task->staff_ids ?? '[]', true);
        if ($task->isEmpty() || !is_array($staffIds) || !in_array($staffId, $staffIds, true)) {
            return null;
        }

        $brandId = (int)MerchantStore::where(['id' => (int)$employee->store_id, 'user_id' => $userId])
            ->value('brand_id');
        $brandName = $brandId > 0
            ? (string)MerchantBrand::where(['id' => $brandId, 'user_id' => $userId])->value('name')
            : '';
        return [
            'content' => (string)$task->content,
            'platforms' => json_decode($task->platforms, true, flags: JSON_THROW_ON_ERROR),
            'brand_name' => $brandName,
        ];
    }

    private static function hash(string $token, string $appId, string $openid): string
    {
        return hash('sha256', implode("\0", ['publish_task_link', $token, $appId, $openid]));
    }
}
