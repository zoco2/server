<?php

namespace app\tenantapi\lists\geo;

use app\common\model\geo\GeoMedia;
use app\common\service\geo\GeoMediaSyncService;
use app\tenantapi\lists\BaseAdminDataLists;
use app\common\lists\ListsSearchInterface;
use app\common\lists\ListsSortInterface;

/**
 * GEO 媒体资源列表（租户后台）。
 * 海讯列表接口无服务端搜索，筛选全部在本地镜像表完成。
 */
class GeoMediaLists extends BaseAdminDataLists implements ListsSearchInterface, ListsSortInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name', 'classify', 'region'],
            '=' => ['resource_type', 'region', 'searchcollint', 'newsresource', 'weekendpublish', 'recommend', 'flag', 'local_hidden'],
        ];
    }

    public function setSortFields(): array
    {
        return ['id' => 'id', 'costprice' => 'costprice', 'price_ratio' => 'price_ratio', 'update_time' => 'update_time'];
    }

    public function setDefaultOrder(): array
    {
        return ['id' => 'desc'];
    }

    public function lists(): array
    {
        $query = GeoMedia::where($this->searchWhere);
        $this->applyExtra($query);
        $rows = $query
            ->field(GeoMedia::OUTPUT_FIELDS)
            ->limit($this->limitOffset, $this->limitLength)
            ->order($this->sortOrder)
            ->select()
            ->toArray();
        // sale_price / price_types 是模型 getter 实时计算的，只读结果里再挂上租户默认倍率方便前端展示。
        $markup = GeoMediaSyncService::markup();
        foreach ($rows as &$row) {
            $row['effective_ratio'] = (float)($row['price_ratio'] ?? 0) > 0 ? (float)$row['price_ratio'] : $markup;
        }
        unset($row);
        return $rows;
    }

    public function count(): int
    {
        $query = GeoMedia::where($this->searchWhere);
        $this->applyExtra($query);
        return (int)$query->count();
    }

    public function extend()
    {
        return [
            'resource_types' => GeoMediaSyncService::RESOURCE_TYPES,
        ];
    }

    /**
     * 价格区间、是否仅在售等额外筛选。
     *
     * @param \think\db\BaseQuery $query 查询对象
     */
    private function applyExtra($query): void
    {
        // 售价不落库，价格区间直接按 成本×行倍率（0 用租户默认） 计算。
        $markup = GeoMediaSyncService::markup();
        $minPrice = $this->params['min_price'] ?? '';
        $maxPrice = $this->params['max_price'] ?? '';
        if (is_numeric($minPrice) && (float)$minPrice > 0) {
            $query->whereRaw('costprice * IF(price_ratio > 0, price_ratio, ?) >= ?', [$markup, (float)$minPrice]);
        }
        if (is_numeric($maxPrice) && (float)$maxPrice > 0) {
            $query->whereRaw('costprice * IF(price_ratio > 0, price_ratio, ?) <= ?', [$markup, (float)$maxPrice]);
        }
        // 默认只显示海讯在架、未删除且未被本地隐藏的资源。
        if (($this->params['show_all'] ?? '0') !== '1') {
            $query->where(['flag' => 1, 'deleted' => 1, 'local_hidden' => 0]);
        }
    }
}