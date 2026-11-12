<?php

use think\facade\Route;

Route::post('publish_task/create', 'PublishTask/create')->completeMatch();
Route::get('publish_task/lists', 'PublishTask/lists')->completeMatch();
