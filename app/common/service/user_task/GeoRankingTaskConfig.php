<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use app\common\model\merchant\MerchantBrand;
use app\common\model\tenant\Tenant;
use app\common\service\geo\GeoDiagnosisService;
use app\common\service\geo\GeoMediaCatalogService;
use app\common\service\tenant\TenantUrlService;
use Exception;
use InvalidArgumentException;

final class GeoRankingTaskConfig
{
    public static function fromInput(int $tenantId, int $userId, mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('请填写 GEO 任务配置');
        }
        $brandId = filter_var($input['brand_id'] ?? null, FILTER_VALIDATE_INT);
        $brand = $brandId > 0 ? MerchantBrand::where(['id' => $brandId, 'user_id' => $userId])->findOrEmpty() : null;
        if (!$brand || $brand->isEmpty()) {
            throw new InvalidArgumentException('品牌不存在或无权限');
        }

        $question = is_string($input['question'] ?? null) ? trim($input['question']) : '';
        if ($question === '' || mb_strlen($question) > 2000) {
            throw new InvalidArgumentException('请输入 1 到 2000 字的 GEO 问题');
        }
        $threshold = filter_var($input['target_mention_rate'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($threshold === false || !is_finite($threshold) || $threshold < 0 || $threshold > 100) {
            throw new InvalidArgumentException('目标提及率必须在 0% 到 100% 之间');
        }

        $platforms = $input['platforms'] ?? null;
        if (!is_array($platforms) || !$platforms || count(array_filter($platforms, 'is_string')) !== count($platforms)) {
            throw new InvalidArgumentException('请选择 GEO 平台');
        }
        $platforms = array_values(array_unique($platforms));
        $repeat = filter_var($input['repeat_count'] ?? 1, FILTER_VALIDATE_INT);
        if ($repeat < 1 || count($platforms) * $repeat > 20) {
            throw new InvalidArgumentException('平台数量乘以检测次数须在 1 到 20 之间');
        }
        try {
            $available = array_column(array_filter(
                GeoDiagnosisService::platforms(),
                static fn (array $platform): bool => ($platform['upstreamConfigured'] ?? true) !== false
            ), 'id');
        } catch (Exception) {
            throw new InvalidArgumentException('暂时无法获取 GEO 平台，请稍后再试');
        }
        if (array_diff($platforms, $available)) {
            throw new InvalidArgumentException('包含不可用的 GEO 平台，请重新选择');
        }

        return [
            'brand_id' => $brandId,
            'question' => $question,
            'platforms' => $platforms,
            'repeat_count' => $repeat,
            'target_mention_rate' => $threshold,
            'media_targets' => self::mediaTargets($input['media_targets'] ?? null),
            'preview_origin' => self::previewOrigin($tenantId),
        ];
    }

    private static function mediaTargets(mixed $input): array
    {
        if (!is_array($input) || !$input) {
            throw new InvalidArgumentException('请至少选择一个投稿媒体');
        }
        $targets = [];
        foreach ($input as $target) {
            $id = filter_var($target['media_id'] ?? null, FILTER_VALIDATE_INT);
            $type = filter_var($target['price_type'] ?? null, FILTER_VALIDATE_INT);
            if ($id < 1 || $type < 1 || isset($targets[$id])) {
                throw new InvalidArgumentException('请为每个媒体选择一个价格类别');
            }
            try {
                $media = GeoMediaCatalogService::detail($id);
            } catch (Exception) {
                throw new InvalidArgumentException('投稿媒体不存在或已下架，请重新选择');
            }
            if (!in_array($type, array_column($media['price_types'] ?? [], 'type'), true)) {
                throw new InvalidArgumentException('投稿媒体的价格类别不可用，请重新选择');
            }
            $targets[$id] = ['media_id' => $id, 'price_type' => $type, 'media_name' => (string)$media['name']];
        }
        return array_values($targets);
    }

    private static function previewOrigin(int $tenantId): string
    {
        $tenant = Tenant::findOrEmpty($tenantId);
        if ($tenant->isEmpty()) {
            throw new InvalidArgumentException('当前租户不存在');
        }
        // CLI 无请求域名，保存按租户访问方式解析出的主机和当前入口的协议。
        $url = TenantUrlService::links($tenant->toArray())['current']['pc'];
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url(request()->domain(), PHP_URL_SCHEME);
        if (!$host || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('无法确定租户的稿件预览地址');
        }
        return $scheme . '://' . $host;
    }
}
