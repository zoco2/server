<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;

/**
 * GEO 投稿单状态流转日志。
 */
class GeoOrderLog extends BaseModel
{
    protected $name = 'geo_order_log';
    protected $type = [
        'from_status' => 'integer',
        'to_status' => 'integer',
    ];

    /**
     * 记一笔状态变更。
     *
     * @param GeoOrder $order 投稿单
     * @param int $from 原状态
     * @param int $to 新状态
     * @param string $action push/query/confirm/feedback/auto
     * @param string $message 说明
     */
    public static function record(GeoOrder $order, int $from, int $to, string $action, string $message = ''): void
    {
        self::create([
            'tenant_id' => (int)$order['tenant_id'],
            'order_id' => (int)$order['id'],
            'order_no' => (string)$order['order_no'],
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'message' => mb_substr($message, 0, 1000),
            'create_time' => time(),
        ]);
    }
}