<?php

return [
    // 网站应用凭据只保存在服务端；测试环境请使用独立的抖音应用。
    'client_key' => env('douyin.client_key', ''),
    'client_secret' => env('douyin.client_secret', ''),
    // 留空沿用默认缓存。多实例部署必须使用所有实例共享的 Redis。
    'cache_store' => env('douyin.cache_store', ''),
];
