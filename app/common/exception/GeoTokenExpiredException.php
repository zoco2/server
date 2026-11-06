<?php

namespace app\common\exception;

use Exception;

/**
 * 海讯社访问令牌失效。
 *
 * 与普通业务异常区分开，让长任务（全量资源同步、批量订单轮询）能识别
 * 「这次失败是令牌过期，不是数据有问题」，从而换令牌重试而不是整批判定失败。
 */
class GeoTokenExpiredException extends Exception
{
}
