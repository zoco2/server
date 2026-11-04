<?php

/**
 * 阿里云轻量消息队列（原 MNS）配置。
 *
 * 全局一套，不区分租户；AccessKey 未单独配置时回退到平台的阿里云存储配置。
 */
return [
    // Endpoint，例如 https://1234567890123456.mns.cn-hangzhou.aliyuncs.com
    'endpoint' => env('mns.endpoint', ''),
    // AccessKey ID；为空时回退到 storage.aliyun 配置
    'access_key_id' => env('mns.access_key_id', ''),
    // AccessKey Secret；为空时回退到 storage.aliyun 配置
    'access_key_secret' => env('mns.access_key_secret', ''),
    // 默认队列名
    'default_queue' => env('mns.default_queue', 'zoco'),
    // 消费失败的最大接收次数，达到后记入 failed_jobs 并移出队列
    'max_retry' => (int)env('mns.max_retry', 3),
];
