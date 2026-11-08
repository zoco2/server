<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 智能剪辑贴纸资源。
 *
 * delete_time 为 int NOT NULL DEFAULT 0，不使用 SoftDelete。
 * 租户条件走 BaseModel 全局 scope。
 */
class StickerResource extends BaseModel
{
    protected $name = 'smart_edit_sticker_resource';

    protected $type = [
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];
}
