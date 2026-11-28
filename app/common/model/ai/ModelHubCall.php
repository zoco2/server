<?php

declare(strict_types=1);

namespace app\common\model\ai;

use app\common\model\BaseModel;
use think\db\Query;

/** 新 Runtime 的调用记录，由当前租户上下文隔离。 */
final class ModelHubCall extends BaseModel
{
    protected $name = 'model_hub_call';
    protected $json = ['result'];
    protected $jsonAssoc = true;
    protected $autoWriteTimestamp = 'int';

    // 调用表由所有租户共用。查询始终使用当前租户，租户分表也使用这张调用表。
    public static function scopeTenantId($query): void
    {
        $query->where('tenant_id', (int)(request()->tenantId ?? 0));
    }

    public function db($scope = []): Query
    {
        // ThinkORM 实例保存时传入 null，仍保留租户作用域。
        return parent::db($scope ?? []);
    }
}
