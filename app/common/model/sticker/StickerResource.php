<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 贴纸资源。tenant_id = 0 为平台预置，租户自建写自己的 tenant_id。
 *
 * delete_time 为 int NOT NULL DEFAULT 0，不使用 SoftDelete。
 */
class StickerResource extends BaseModel
{
    protected $name = 'sticker_resource';

    protected $globalScope = [];

    protected $type = [
        'width' => 'integer',
        'height' => 'integer',
        'file_size' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];
}
