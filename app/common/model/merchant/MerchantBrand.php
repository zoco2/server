<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 商家品牌。
 */
class MerchantBrand extends BaseModel
{
    use SoftDelete;

    protected $name = 'merchant_brand';

    protected $deleteTime = 'delete_time';
}
