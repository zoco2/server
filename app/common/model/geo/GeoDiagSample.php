<?php

namespace app\common\model\geo;

use app\common\model\TenantScopedWriteModel;

/**
 * GEO 品牌诊断平台采样结果。
 */
class GeoDiagSample extends TenantScopedWriteModel
{
    protected $name = 'geo_diag_sample';
    protected $autoWriteTimestamp = 'int';
}
