<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;

/**
 * GEO 媒体资源（海讯社镜像）
 */
class GeoMedia extends BaseModel
{
    protected $name = 'geo_media';
    protected $json = ['price_type_json'];
    protected $jsonAssoc = true;
    protected $type = [
        'costprice' => 'float',
        'sale_price' => 'float',
    ];
}