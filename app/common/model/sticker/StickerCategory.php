<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 贴纸分类。tenant_id = 0 为平台预置，租户自建写自己的 tenant_id。
 *
 * 表里 delete_time 是 int NOT NULL DEFAULT 0，不能用 SoftDelete（框架默认写 null），
 * 删除统一由 StickerService 写时间戳。
 */
class StickerCategory extends BaseModel
{
    protected $name = 'sticker_category';

    /** 需要并集平台预置与租户自建，不用租户全局作用域 */
    protected $globalScope = [];
}
