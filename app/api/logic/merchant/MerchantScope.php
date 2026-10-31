<?php

namespace app\api\logic\merchant;

use app\common\model\BaseModel;

/**
 * 按当前登录商家查询未删除记录。
 * tenant_id 由 BaseModel 全局作用域处理。
 */
class MerchantScope
{
    /**
     * @param class-string<BaseModel> $model
     * @return \think\db\Query
     */
    public static function query(string $model)
    {
        return $model::where('user_id', (int)request()->userId)
            ->where('delete_time', 0);
    }
}
