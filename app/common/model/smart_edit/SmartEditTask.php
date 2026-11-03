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

    protected $name = 'smart_edit_task';
    protected $deleteTime = 'delete_time';
    protected $defaultSoftDelete = null;
    protected $autoWriteTimestamp = 'int';
    protected $json = ['params_json', 'timeline_json'];
    protected $jsonAssoc = true;

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