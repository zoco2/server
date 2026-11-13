<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 用于向共用的发布任务表写入新任务。 */
final class PublishTask extends BaseModel
{
    protected $name = 'publish_task';

    // 写入固定任务表，租户字段由 BaseModel::onBeforeInsert 自动填入。
    protected $globalScope = [];

    protected $autoWriteTimestamp = false;
}
