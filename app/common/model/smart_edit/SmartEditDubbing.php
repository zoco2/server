<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;

/**
 * 智能剪辑 TTS 口播。
 *
 * 一个镜头组下，每个配音音色一条：逐句合成后拼接成整段口播，
 * lines_json 保存逐句时间轴，字幕的 TimelineIn/Out 直接取自这里。
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $batch_no
 * @property int    $voice_index
 * @property string $status
 */
class SmartEditDubbing extends BaseModel
{
    protected $json = ['lines_json'];
    protected $jsonAssoc = true;

    protected $type = [
        'voice_index' => 'integer',
        'duration' => 'float',
    ];

    /** 待生成 */
    public const STATUS_PENDING = 'pending';
    /** 合成中 */
    public const STATUS_PROCESSING = 'processing';
    /** 已完成 */
    public const STATUS_SUCCESS = 'success';
    /** 失败 */
    public const STATUS_FAILED = 'failed';

    public const RUNNING_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];
}
