<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/**
 * 门店装修草稿。主键是 tenant_id + user_id + store_id，没有软删除和时间戳字段约定。
 */
class MerchantStoreDesign extends BaseModel
{
    protected $name = 'merchant_store_design';

    protected $pk = 'store_id';

    protected $autoWriteTimestamp = false;
}
