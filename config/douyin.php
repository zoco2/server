<?php

return [
    // 留空沿用默认缓存。多实例部署必须使用所有实例共享的 Redis。
    'cache_store' => env('douyin.cache_store', ''),
];
