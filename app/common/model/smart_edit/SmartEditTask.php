<?php

namespace app\common\model\smart_edit;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 智能剪辑任务。第一轮只有 merge（多视频合并），后续按 edit_type 扩展。
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $task_no
 * @property string $edit_type
 * @property string $status
 * @property string $provider_job_id
 * @property string $output_uri
 */
class SmartEditTask extends BaseModel
{
    use SoftDelete;

    protected $json = ['params_json', 'timeline_json'];
    protected $jsonAssoc = true;

    /**
     * decimal 字段默认被序列化成字符串（如 "3.50"），
     * 这里统一转成数值，避免前端拿到字符串后参与运算出错。
     */
    protected $type = [
        'duration' => 'float',
        'input_duration' => 'float',
        'tenant_cost_points' => 'float',
        'user_charge_points' => 'float',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public const TYPE_MERGE = 'merge';

    /** 未终态，可继续轮询 */
    public const RUNNING_STATUSES = [self::STATUS_PENDING, self::STATUS_SUBMITTING, self::STATUS_PROCESSING];
}