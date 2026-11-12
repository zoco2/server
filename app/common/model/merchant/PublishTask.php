<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 商家发布任务。租户共用一张表，按租户和创建人隔离。 */
final class PublishTask extends BaseModel
{
    protected $name = 'publish_task';

    protected $autoWriteTimestamp = false;
}
