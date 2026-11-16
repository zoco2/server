<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/** 商家话题入参。 */
class TopicValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|length:1,100',
    ];

    protected $message = [
        'name.require' => '请输入话题名称',
        'name.length' => '话题名称不能超过100个字',
    ];
}
