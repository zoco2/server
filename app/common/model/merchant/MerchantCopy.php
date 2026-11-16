<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;

class MerchantCopy extends BaseModel
{
    protected $name = 'merchant_copy';
    protected $autoWriteTimestamp = 'int';
    protected $type = ['create_time' => 'integer', 'update_time' => 'integer'];
}
