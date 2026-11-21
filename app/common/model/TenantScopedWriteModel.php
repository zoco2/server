<?php

namespace app\common\model;

use think\db\BaseQuery;

abstract class TenantScopedWriteModel extends BaseModel
{
    public function db($scope = []): BaseQuery
    {
        // ThinkORM 的实例 save() 用 db(null) 更新。保留租户作用域，
        // 确保更新与加锁读取都落在同一个租户的物理表。
        return parent::db($scope ?? []);
    }
}
