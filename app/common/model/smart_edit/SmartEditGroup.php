<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 智能剪辑镜头组。
 *
 * 一个组下有多个镜头，每个镜头挂多条候选视频；组内各镜头各取一条做笛卡尔积，
 * 每个组合落成一条普通 la_smart_edit_task（batch_no 指回本组）。
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $user_id
 * @property string $batch_no
 * @property string $title
 * @property string $status
 */
class SmartEditGroup extends BaseModel
{
    use SoftDelete;

    protected $json = ['shots_json', 'config_json'];
    protected $jsonAssoc = true;

    protected $type = [
        'combo_count' => 'integer',
    ];

    /** 组行已落库，正在展开成任务 */
    public const STATUS_EXPANDING = 'expanding';
    /** 全部组合已生成并投递提交 job */
    public const STATUS_SUBMITTED = 'submitted';
    /** 展开阶段失败，没有可用任务 */
    public const STATUS_FAILED = 'failed';

    /** 展开阶段的非终态集合；成片进度另由子任务聚合 */
    public const RUNNING_STATUSES = [self::STATUS_EXPANDING];
}
