<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;

/**
 * GEO 品牌诊断平台采样结果。
 */
class GeoDiagSample extends BaseModel
{
    protected $name = 'geo_diag_sample';
    protected $autoWriteTimestamp = 'int';
}
