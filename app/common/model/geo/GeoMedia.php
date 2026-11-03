<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;
use app\common\service\geo\GeoMediaSyncService;

/**
 * GEO 媒体资源（海讯社镜像）。
 *
 * 售价不落库：sale_price 由 costprice × 租户加成倍率（price_ratio）实时计算，
 * 否则调倍率要全表回写。价格类别同理，读出来时补上实时价格。
 *
 * @property float $costprice
 * @property float $price_ratio
 * @property float $sale_price
 */
class GeoMedia extends BaseModel
{
    protected $name = 'geo_media';
    protected $json = ['price_type_json'];
    protected $jsonAssoc = true;
    protected $type = [
        'costprice' => 'float',
        'price_ratio' => 'float',
    ];

    /** 返回给前端的字段，sale_price / price_types 为运行时计算。 */
    public const OUTPUT_FIELDS = 'id,resource_type,name,classify,region,portalmedia,industrystr,searchcoll,'
        . 'entrancelink,caseurl,remark,costprice,price_ratio,sale_price,price_types,'
        . 'linktype,publishtime,newsresource,weekendpublish,searchcollint,pcweight,wapweight,'
        . 'entrlevel,recommend,flag,deleted,local_hidden,sync_status,last_sync_time,update_time';

    /**
     * 售价 = 成本 × 倍率。
     *
     * @param mixed $value 数据库原始值（恒为 0，不落库）
     * @param array<string, mixed> $data 当前行
     */
    public function getSalePriceAttr($value, array $data): float
    {
        return GeoMediaSyncService::suggestSalePrice((float)($data['costprice'] ?? 0), (float)($data['price_ratio'] ?? 1));
    }

    /**
     * 多价格类别，价格同样按倍率实时换算。
     *
     * @param mixed $value price_type_json
     * @param array<string, mixed> $data 当前行
     * @return array<int, array{type:int, price:float, sale_price:float}>
     */
    public function getPriceTypesAttr($value, array $data): array
    {
        $items = is_array($value) ? $value : [];
        $ratio = (float)($data['price_ratio'] ?? 1);
        $result = [];
        foreach ($items as $item) {
            $type = (int)($item['type'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            if ($type <= 0) {
                continue;
            }
            $result[] = [
                'type' => $type,
                'price' => round($price, 2),
                'sale_price' => GeoMediaSyncService::suggestSalePrice($price, $ratio),
            ];
        }
        if ($result === []) {
            $result[] = ['type' => 1, 'price' => 0.0, 'sale_price' => 0.0];
        }
        return $result;
    }
}