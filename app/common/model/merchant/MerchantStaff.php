<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/**
 * 门店员工档案。员工不是登录账号。
 */
class MerchantStaff extends BaseModel
{
    protected $name = 'merchant_staff';

    protected $autoWriteTimestamp = false;
}
