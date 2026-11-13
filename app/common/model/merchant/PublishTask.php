<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 商家发布任务，按租户和创建人隔离。 */
final class PublishTask extends BaseModel
{
    protected $name = 'publish_task';

    // 已排队的任务使用同一张任务表，由 BaseModel 自动按租户隔离。
    protected const USE_TENANT_SUBTABLE = false;

    protected $autoWriteTimestamp = false;
}
