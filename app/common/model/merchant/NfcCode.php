<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/** 平台统一生成的 NFC 码牌库存。 */
final class NfcCode extends BaseModel
{
    protected $name = 'merchant_nfc_code';

    protected $autoWriteTimestamp = false;
}
