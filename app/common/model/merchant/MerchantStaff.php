<?php

namespace app\common\model\merchant;

use think\model\concern\SoftDelete;

/**
 * 门店员工档案。员工不是登录账号。
 */
class MerchantStaff extends MerchantProfileModel
{
    use SoftDelete;

    protected $name = 'merchant_staff';

    protected $deleteTime = 'delete_time';
}
