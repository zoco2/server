<?php

return [
    // 留空沿用默认缓存；多实例必须使用同一个 Redis 缓存及前缀。
    'cache_store' => env('xiaohongshu.cache_store', ''),
];
