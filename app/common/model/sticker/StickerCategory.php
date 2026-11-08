<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 贴纸分类。
 *
 * 表里 delete_time 是 int NOT NULL DEFAULT 0，不能用 SoftDelete（框架默认写 null），
 * 删除统一由业务写时间戳。租户条件走 BaseModel 全局 scope。
 */
class StickerCategory extends BaseModel
{
    protected $name = 'sticker_category';
}
