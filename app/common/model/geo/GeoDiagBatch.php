<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;

/**
 * GEO 品牌诊断批次。
 */
class GeoDiagBatch extends BaseModel
{
    protected $name = 'geo_diag_batch';
    protected $autoWriteTimestamp = 'int';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL_FAILED = 'partial_failed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL_STATUS = [
        self::STATUS_COMPLETED,
        self::STATUS_PARTIAL_FAILED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];
}
