<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use think\db\BaseQuery;

abstract class MerchantProfileModel extends BaseModel
{
    public function db($scope = []): BaseQuery
    {
        // ORM 保存模型时传入 null，写入也需要租户作用域选择对应的表。
        return parent::db($scope ?? []);
    }
}
