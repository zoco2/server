<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;

/**
 * 智能剪辑任务的素材明细。item_type 目前有 video 与 cover，
 * 后续字幕/贴纸/音频沿用同一张表，只增加 item_type。
 */
class SmartEditTaskItem extends BaseModel
{
    public const TYPE_VIDEO = 'video';

    /** 封面：轨首图片片段，固定占 0.5s */
    public const TYPE_COVER = 'cover';

    /** 贴纸：独占一条图片轨，叠在正片之上 */
    public const TYPE_STICKER = 'sticker';

    /** 背景音乐：独占音频轨 */
    public const TYPE_AUDIO = 'audio';

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