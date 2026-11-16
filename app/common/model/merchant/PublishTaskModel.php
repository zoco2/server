<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use InvalidArgumentException;

/** 发布任务和执行记录各用一张共用表，按当前租户隔离。 */
abstract class PublishTaskModel extends BaseModel
{
    protected $autoWriteTimestamp = false;

    /** 共用表按租户过滤，新增时仍由 BaseModel 填写租户字段。 */
    public static function scopeTenantId($query): void
    {
        $tenantId = (int)(request()->tenantId ?? 0);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('缺少租户上下文');
        }
        $query->where($query->getTable(true) . '.tenant_id', $tenantId);
    }
}
