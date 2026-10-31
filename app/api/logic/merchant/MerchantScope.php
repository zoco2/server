<?php

namespace app\api\logic\merchant;

use app\common\model\BaseModel;

/**
 * 按商家归属查询未删除记录。
 */
class MerchantScope
{
    /**
     * @param class-string<BaseModel> $model
     * @param int $tenantId
     * @param int $userId
     * @return \think\db\Query
     */
    public static function query(string $model, int $tenantId, int $userId)
    {
        return $model::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0);
    }
}
