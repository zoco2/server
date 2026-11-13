<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 商家发布任务，沿用 BaseModel 的租户过滤和自动填充。 */
final class PublishTask extends BaseModel
{
    protected $name = 'publish_task';

    protected $autoWriteTimestamp = false;
}
