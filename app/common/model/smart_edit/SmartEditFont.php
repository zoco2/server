<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;

/**
 * 智能剪辑标题字体。
 *
 * delete_time 为 int NOT NULL DEFAULT 0，不使用 SoftDelete。
 * 租户条件走 BaseModel 全局 scope。
 */
class SmartEditFont extends BaseModel
{
    protected $name = 'smart_edit_font';

    protected $type = [
        'file_size' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];
}
