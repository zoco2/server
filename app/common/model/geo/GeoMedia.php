<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;
use app\common\service\geo\GeoMediaSyncService;

/**
 * GEO 媒体资源（海讯社镜像）。
 *
 * 售价不落库：sale_price 由 costprice × 倍率（行级 price_ratio，0 取租户默认）实时计算，
 * 否则调倍率要全表回写。多价格类别同理，读出时补上实时价格。
 *
 * 注意 JSON 字段：
 * - price_types 是 $json 声明的字段名，读时是与 price_type_json 合并后的数组，
 *   写时必须写 price_types（模型按规则转成 price_type_json 再 json_encode）；
 *   直接写 price_type_json 会走 Builder 的删除式更新，数组下标越界报错。
 * - detail_json 是原始行，不声明为 json 字段，由 setDetailJsonAttr 自行编码，
 *   避免把整个海讯原始行也当成 price_types 合并进来。
 *
 * @property float $costprice
 * @property float $price_ratio
 * @property float $sale_price
 * @property array $price_types
 */
class GeoMedia extends BaseModel
{
    protected $name = 'geo_media';
    protected $json = ['price_types'];
    protected $jsonAssoc = true;
    protected $type = [
        'costprice' => 'float',
        'price_ratio' => 'float',
    ];

    /** 返回给前端的字段，sale_price / price_types 为运行时计算。 */
    public const OUTPUT_FIELDS = 'id,resource_type,name,classify,region,portalmedia,industrystr,searchcoll,'
        . 'entrancelink,caseurl,remark,costprice,price_ratio,price_types,'
        . 'linktype,publishtime,newsresource,weekendpublish,searchcollint,pcweight,wapweight,'
        . 'entrlevel,recommend,flag,deleted,local_hidden,sync_status,last_sync_time,update_time';

    /**
     * 售价 = 成本 × 倍率。
     *
     * @param mixed $value 数据库原始值（没有该列，恒为 null）
     * @param array<string, mixed> $data 当前行
     */
    public function getSalePriceAttr($value, array $data): float
    {
        return GeoMediaSyncService::suggestSalePrice((float)($data['costprice'] ?? 0), self::ratio($data));
    }

    /**
     * 多价格类别，价格按同一倍率实时换算。
     *
     * @param mixed $value price_types（json 字段）
     * @param array<string, mixed> $data 当前行
     * @return array<int, array{type:int, price:float, sale_price:float}>
     */
    public function getPriceTypesAttr($value, array $data): array
    {
        $items = is_array($value) ? array_values($value) : [];
        $ratio = self::ratio($data);
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
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
        return $result;
    }

    /**
     * 原始行单独编码，避免被当成 price_types 合并。
     *
     * @param mixed $value detail_json
     */
    public function setDetailJsonAttr($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }

    /**
     * 生效倍率：行级大于 0 用行级，否则用租户默认。
     *
     * @param array<string, mixed> $data 当前行
     */
    public static function ratio(array $data): float
    {
        $ratio = (float)($data['price_ratio'] ?? 0);
        return $ratio > 0 ? $ratio : GeoMediaSyncService::markup();
    }
}