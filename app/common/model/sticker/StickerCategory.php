<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 贴纸分类。列表查询不按租户过滤。
 *
 * 表里 delete_time 是 int NOT NULL DEFAULT 0，不能用 SoftDelete（框架默认写 null），
 * 删除统一由 StickerService 写时间戳。
 */
class StickerCategory extends BaseModel
{
    protected $name = 'sticker_category';

    /** 不做租户全局作用域 */
    protected $globalScope = [];
}
