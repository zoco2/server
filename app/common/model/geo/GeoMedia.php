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
 * - ThinkPHP 的 `$json` 里必须写**真实列名**，框架不做名称映射。
 *   本表列名是 `price_type_json`，写/查用别的名字（如 price_types）会直接
 *   SQLSTATE[42S22] Unknown column。项目内其他模型同理（如 AigcCanvasProject）。
 * - 对外接口仍叫 price_types，由 GeoMediaCatalogService::publicRow() 映射，
 *   免得前端拿到成本价自己推算倍率。
 * - detail_json 是原始行，不声明为 json 字段，由 setDetailJsonAttr 自行编码。
 *
 * @property float $costprice
 * @property float $price_ratio
 * @property float $sale_price
 * @property array $price_type_json
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

    /** 返回给前端的字段，sale_price / price_type_json 为运行时计算。 */
    public const OUTPUT_FIELDS = 'id,resource_type,name,classify,region,portalmedia,industrystr,searchcoll,'
        . 'entrancelink,caseurl,remark,costprice,price_ratio,price_type_json,'
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
     * 方法名必须与列名对齐：get + studly(price_type_json) + Attr，
     * 叫 getPriceTypesAttr 是取不到的，会原样返回未换算的 json。
     *
     * @param mixed $value price_type_json（json 字段，已解码成数组）
     * @param array<string, mixed> $data 当前行
     * @return array<int, array{type:int, price:float, sale_price:float}>
     */
    public function getPriceTypeJsonAttr($value, array $data): array
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