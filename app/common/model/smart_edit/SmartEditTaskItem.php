<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;

/**
 * 智能剪辑任务的素材明细。item_type 目前只有 video，
 * 后续字幕/贴纸/音频沿用同一张表，只增加 item_type。
 */
class SmartEditTaskItem extends BaseModel
{
    public const TYPE_VIDEO = 'video';

    public function getInSecAttr($value): float
    {
        return (float)$value;
    }

    public function getOutSecAttr($value): float
    {
        return (float)$value;
    }

    public function getDurationAttr($value): float
    {
        return (float)$value;
    }
}