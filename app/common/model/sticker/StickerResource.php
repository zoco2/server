<?php

namespace app\common\model\sticker;

use app\common\model\BaseModel;

/**
 * 贴纸资源。列表查询不按租户过滤。
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
