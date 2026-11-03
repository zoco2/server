<?php

namespace app\tenantapi\logic\geo;

use app\common\logic\BaseLogic;
use app\common\model\geo\GeoMedia;
use app\common\service\ConfigService;
use app\common\service\geo\GeoMediaSyncService;
use Exception;

/**
 * GEO 媒体资源后台逻辑。
 */
class GeoMediaLogic extends BaseLogic
{
    /**
     * 分类统计：每个资源类型的在架数量与价格区间。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function types(): array
    {
        // 全局作用域已按当前租户过滤，这里不再重复写 tenant_id。
        // 售价不落库，按成本 × 倍率在内存里算区间。
        $rows = GeoMedia::where(['flag' => 1, 'deleted' => 1, 'local_hidden' => 0])
            ->field('resource_type, costprice, price_ratio')
            ->select()
            ->toArray();
        $defaultRatio = GeoMediaSyncService::markup();
        $counts = [];
        foreach ($rows as $row) {
            $type = (int)$row['resource_type'];
            $ratio = (float)($row['price_ratio'] ?? 0);
            $ratio = $ratio > 0 ? $ratio : $defaultRatio;
            $sale = GeoMediaSyncService::suggestSalePrice((float)($row['costprice'] ?? 0), $ratio);
            if (!isset($counts[$type])) {
                $counts[$type] = ['total' => 0, 'min_price' => $sale, 'max_price' => $sale];
            }
            $counts[$type]['total']++;
            $counts[$type]['min_price'] = min($counts[$type]['min_price'], $sale);
            $counts[$type]['max_price'] = max($counts[$type]['max_price'], $sale);
        }
        $result = [];
        foreach (GeoMediaSyncService::RESOURCE_TYPES as $type => $name) {
            $row = $counts[$type] ?? [];
            $result[] = [
                'type' => $type,
                'name' => $name,
                'total' => (int)($row['total'] ?? 0),
                'min_price' => (float)($row['min_price'] ?? 0),
                'max_price' => (float)($row['max_price'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * 手动同步指定类型。
     *
     * @param int $tenantId 租户 ID
     * @param int $type 资源类型
     * @return array<string, mixed>
     */
    public static function sync(int $tenantId, int $type): array
    {
        if ($type <= 0) {
            throw new Exception('请选择资源类型');
        }
        return GeoMediaSyncService::syncType($tenantId, $type, ['full' => true]);
    }

    /**
     * 设置单个资源倍率与本地隐藏。售价由成本 × 倍率实时计算，不落库。
     *
     * @param int $tenantId 租户 ID
     * @param array<string, mixed> $params id、price_ratio(0=用租户默认)、local_hidden
     * @return array<string, mixed>
     */
    public static function update(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $row = GeoMedia::where(['id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('资源不存在');
        }
        $data = ['update_time' => time()];
        if (array_key_exists('price_ratio', $params)) {
            $ratio = (float)$params['price_ratio'];
            if ($ratio < 0 || $ratio > 100) {
                throw new Exception('倍率需在 0~100 之间，0 表示使用租户默认倍率');
            }
            $data['price_ratio'] = round($ratio, 3);
        }
        if (array_key_exists('local_hidden', $params)) {
            $data['local_hidden'] = (int)$params['local_hidden'] ? 1 : 0;
        }
        $row->save($data);
        return $row->refresh()->toArray();
    }

    /**
     * 批量校正状态（stateON），用于同步后抽查。
     *
     * @param int $tenantId 租户 ID
     * @param array<int> $ids 本地资源ID
     * @return array<string, mixed>
     */
    public static function refreshStates(int $tenantId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            throw new Exception('请选择资源');
        }
        if (count($ids) > 50) {
            throw new Exception('单次最多校正 50 条');
        }
        $sourceIds = GeoMedia::whereIn('id', $ids)->column('sourceid');
        return GeoMediaSyncService::refreshStates($tenantId, $sourceIds);
    }

    /**
     * 读取当前租户加成倍率。
     *
     * @param int $tenantId 租户 ID
     */
    public static function markup(int $tenantId): float
    {
        return GeoMediaSyncService::markup($tenantId);
    }

    /**
     * 保存加成倍率。仅影响之后同步的成本变化，不批量改写已有售价。
     *
     * @param int $tenantId 租户 ID
     * @param array<string, mixed> $params markup
     * @return float
     */
    public static function saveMarkup(int $tenantId, array $params): float
    {
        $markup = $params['markup'] ?? null;
        if (!is_numeric($markup) || (float)$markup <= 0 || (float)$markup > 100) {
            throw new Exception('加成倍率需在 0~100 之间');
        }
        $markup = round((float)$markup, 3);
        ConfigService::set('geo_manuscript', 'markup', $markup);
        return $markup;
    }
}