<?php

use think\facade\Route;

// /api/v1/* 已由开放平台处理。这里走普通用户站点的登录和租户中间件。
Route::post('model-hub/v1/chat/test', 'v1.ModelHub/chatTest')->completeMatch();
