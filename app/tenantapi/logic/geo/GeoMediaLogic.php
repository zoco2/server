<?php

namespace app\tenantapi\logic\geo;

use app\common\logic\BaseLogic;
use app\common\model\geo\GeoMedia;
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
            ->field('resource_type, costprice')
            ->select()
            ->toArray();

        $counts = [];
        $ratio = GeoMediaSyncService::markup();
        foreach ($rows as $row) {
            $type = (int)$row['resource_type'];
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
     * @param int $limit 最多处理条数，0 表示不限（后台接口默认 1000，避免请求超时）
     * @return array<string, mixed>
     */
    public static function sync(int $tenantId, int $type, int $limit = 1000): array
    {
        if ($type <= 0) {
            throw new Exception('请选择资源类型');
        }
        return GeoMediaSyncService::syncType($tenantId, $type, true, $limit);
    }

    /**
     * 设置单个资源的本地隐藏状态。
     *
     * @param int $tenantId 租户 ID
     * @param array<string, mixed> $params id、local_hidden
     * @return array<string, mixed>
     */
    public static function update(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $row = GeoMedia::where(['id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('资源不存在');
        }
        if (!array_key_exists('local_hidden', $params)) {
            throw new Exception('请提供本地隐藏状态');
        }
        $row->save([
            'local_hidden' => (int)$params['local_hidden'] ? 1 : 0,
            'update_time' => time(),
        ]);
        return $row->refresh()->hidden(['price_ratio'])->toArray();
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

    /** 读取平台发稿加成倍率。 */
    public static function markup(): float
    {
        return GeoMediaSyncService::markup();
    }
}