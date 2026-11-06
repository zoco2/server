<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 商家门店。
 */
class MerchantStore extends BaseModel
{
    use SoftDelete;

    protected $name = 'merchant_store';

    protected $deleteTime = 'delete_time';
}
