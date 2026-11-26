<?php

namespace app\common\model\merchant;

use think\model\concern\SoftDelete;

/**
 * 商家门店。
 */
class MerchantStore extends MerchantProfileModel
{
    use SoftDelete;

    protected $name = 'merchant_store';

    protected $deleteTime = 'delete_time';
}
