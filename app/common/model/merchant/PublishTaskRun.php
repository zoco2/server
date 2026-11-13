<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 发布任务的单次执行记录。 */
final class PublishTaskRun extends BaseModel
{
    protected $name = 'publish_task_run';

    protected $autoWriteTimestamp = false;
}
