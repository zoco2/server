<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;

/**
 * GEO 投稿单：我方 order_no 即海讯社 ordernum。
 *
 * status 本地用 -1 表示还没推送到海讯，其余与海讯 flag 一致：
 * 0 待安排 / 1 已安排 / 2 拒稿 / 3 发布成功待确认 / 4 反馈处理中 / 5 已完成 / 100 订单不存在。
 */
class GeoOrder extends BaseModel
{
    protected $name = 'geo_order';
    protected $type = [
        'cost_price' => 'float',
        'sale_price' => 'float',
        'status' => 'integer',
        'remote_flag' => 'integer',
    ];

    public const STATUS_LOCAL = -1;
    public const STATUS_PENDING = 0;
    public const STATUS_ARRANGED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_PUBLISHED = 3;
    public const STATUS_FEEDBACK = 4;
    public const STATUS_DONE = 5;
    public const STATUS_MISSING = 100;

    public const STATUS_TEXT = [
        self::STATUS_LOCAL => '待推送',
        self::STATUS_PENDING => '待安排',
        self::STATUS_ARRANGED => '已安排',
        self::STATUS_REJECTED => '拒稿',
        self::STATUS_PUBLISHED => '发布成功待确认',
        self::STATUS_FEEDBACK => '反馈处理中',
        self::STATUS_DONE => '已完成',
        self::STATUS_MISSING => '订单不存在',
    ];

    /** 还需要轮询的状态 */
    public const POLL_STATUS = [self::STATUS_PENDING, self::STATUS_ARRANGED, self::STATUS_PUBLISHED, self::STATUS_FEEDBACK];
}