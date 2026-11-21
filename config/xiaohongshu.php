<?php

return [
    // 小红书分享开放平台审核通过后分配的应用凭据，仅在服务端保存。
    'app_key' => env('xiaohongshu.app_key', ''),
    'app_secret' => env('xiaohongshu.app_secret', ''),
    // 留空沿用默认缓存；多实例必须使用同一个 Redis 缓存及前缀。
    'cache_store' => env('xiaohongshu.cache_store', ''),
];
