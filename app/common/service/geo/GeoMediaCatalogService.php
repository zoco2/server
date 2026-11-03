<?php

namespace app\common\service\geo;

use app\common\model\geo\GeoMedia;

/**
 * 媒体资源广场（前台用户视角）。
 *
 * 只返回售价，不返回成本价与内部镜像字段：
 * 用户看到的价永远是 costprice × 倍率实时算出来的，调倍率不需要回写数据。
 */
class GeoMediaCatalogService
{
    /** 前台可见字段：不含 costprice / price_ratio / sourceid / detail_json 等内部列 */
    public const PUBLIC_FIELDS = 'id,resource_type,name,classify,region,portalmedia,industrystr,'
        . 'searchcoll,entrancelink,caseurl,remark,price_ratio,price_types,'
        . 'linktype,publishtime,newsresource,weekendpublish,searchcollint,pcweight,wapweight,'
        . 'entrlevel,recommend,update_time';

    /** 仅在售 + 未删除 + 未被后台隐藏 */
    public const ON_SALE = [
        'flag' => 1,
        'deleted' => 1,
        'local_hidden' => 0,
    ];

    /**
     * 类型字典 + 各类型在售数量与价格区间。
     *
     * @return array<int, array{type:int, name:string, count:int, min_price:float, max_price:float}>
     */
    public static function types(): array
    {
        $base = GeoMedia::where(self::ON_SALE);
        $counts = (clone $base)
            ->group('resource_type')
            ->column('count(*)', 'resource_type');

        // 价格区间在 SQL 里按 成本 × 行倍率 取极值，再统一换算成售价。
        $markup = GeoMediaSyncService::markup();
        $rows = (clone $base)
            ->field('resource_type,MIN(costprice * IF(price_ratio > 0, price_ratio, ' . (float)$markup . ')) AS min_price,'
                . 'MAX(costprice * IF(price_ratio > 0, price_ratio, ' . (float)$markup . ')) AS max_price')
            ->group('resource_type')
            ->select()
            ->toArray();
        $range = [];
        foreach ($rows as $row) {
            $range[(int)$row['resource_type']] = [(float)$row['min_price'], (float)$row['max_price']];
        }

        $lists = [];
        foreach (AigcGeoManuscriptService::RESOURCE_TYPES as $type => $name) {
            $count = (int)($counts[$type] ?? 0);
            if ($count <= 0) {
                continue;
            }
            [$min, $max] = $range[$type] ?? [0, 0];
            $lists[] = [
                'type' => $type,
                'name' => $name,
                'count' => $count,
                'min_price' => round($min, 2),
                'max_price' => round($max, 2),
            ];
        }
        return $lists;
    }

    /**
     * 资源广场分页。
     *
     * @param array<string, mixed> $params resource_type / region / keyword / newsresource /
     *                                     searchcollint / weekendpublish / min_price / max_price / sort
     * @return array{count:int, lists:array<int, array<string, mixed>>}
     */
    public static function lists(array $params): array
    {
        $pageSize = min(48, max(1, (int)($params['page_size'] ?? 20)));
        $pageNo = max(1, (int)($params['page_no'] ?? 1));

        $query = GeoMedia::where(self::ON_SALE);
        self::applyFilters($query, $params);

        $count = (int)(clone $query)->count();
        $rows = $query
            ->field(self::PUBLIC_FIELDS)
            ->order(...self::resolveOrder((string)($params['sort'] ?? '')))
            ->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        return ['count' => $count, 'lists' => array_map([self::class, 'publicRow'], $rows)];
    }

    /**
     * 资源详情。
     *
     * @param int $id 资源 ID
     * @return array<string, mixed>
     */
    public static function detail(int $id): array
    {
        $row = GeoMedia::where(self::ON_SALE)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new \Exception('媒体资源不存在或已下架');
        }
        return self::publicRow($row->toArray());
    }

    /**
     * 行数据转前台视图：补售价、去掉成本价与内部字段。
     *
     * @param array<string, mixed> $row 原始行
     * @return array<string, mixed>
     */
    public static function publicRow(array $row): array
    {
        $ratio = GeoMedia::ratio($row);
        $row['sale_price'] = GeoMediaSyncService::suggestSalePrice((float)($row['costprice'] ?? 0), $ratio);
        unset($row['costprice'], $row['price_ratio'], $row['tenant_id'], $row['sourceid']);

        // price_types 的 getter 已带 sale_price；这里只去掉成本价 price，避免前端推算倍率。
        foreach ((array)($row['price_types'] ?? []) as $index => $item) {
            $row['price_types'][$index]['sale_price'] = $item['sale_price']
                ?? GeoMediaSyncService::suggestSalePrice((float)($item['price'] ?? 0), $ratio);
            unset($row['price_types'][$index]['price']);
        }
        if (isset($row['price_types']) && $row['price_types'] === []) {
            unset($row['price_types']);
        }
        return $row;
    }

    /**
     * 通用筛选条件。
     *
     * @param \think\db\BaseQuery $query 查询对象
     * @param array<string, mixed> $params 请求参数
     */
    private static function applyFilters($query, array $params): void
    {
        foreach (['resource_type', 'newsresource', 'searchcollint', 'weekendpublish', 'linktype', 'entrlevel'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $query->where($key, (int)$params[$key]);
            }
        }
        $region = trim((string)($params['region'] ?? ''));
        if ($region !== '') {
            $query->whereLike('region', '%' . $region . '%');
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('classify', 'like', '%' . $keyword . '%')
                    ->whereOr('industrystr', 'like', '%' . $keyword . '%')
                    ->whereOr('portalmedia', 'like', '%' . $keyword . '%');
            });
        }
        // 价格区间按最终售价比较：成本 × 行倍率（0 用租户默认），与列表展示口径一致。
        $markup = (float)GeoMediaSyncService::markup();
        if (isset($params['min_price']) && $params['min_price'] !== '') {
            $query->whereRaw('costprice * IF(price_ratio > 0, price_ratio, ?) >= ?', [$markup, (float)$params['min_price']]);
        }
        if (isset($params['max_price']) && $params['max_price'] !== '') {
            $query->whereRaw('costprice * IF(price_ratio > 0, price_ratio, ?) <= ?', [$markup, (float)$params['max_price']]);
        }
    }

    /**
     * 排序白名单。
     *
     * @param string $sort 前端传入的排序键
     * @return array{0:string, 1:string}
     */
    private static function resolveOrder(string $sort): array
    {
        return match ($sort) {
            'price_asc' => ['costprice', 'asc'],
            'price_desc' => ['costprice', 'desc'],
            'recommend' => ['recommend', 'desc'],
            default => ['id', 'desc'],
        };
    }
}