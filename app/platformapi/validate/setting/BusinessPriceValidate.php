<?php

namespace app\platformapi\validate\setting;

use app\common\validate\BaseValidate;

class BusinessPriceValidate extends BaseValidate
{
    private const MARKUP_RULE = 'require|number|regex:/^\d+(?:\.\d{1,2})?$/D|between:0,9900';

    protected $rule = [
        'smart_edit_points' => 'require|number|regex:/^\d+$/D|between:0,99999999',
        'media_markup_percent' => self::MARKUP_RULE,
        'geo_markup_percent' => self::MARKUP_RULE,
    ];

    protected $message = [
        'smart_edit_points' => '智能剪辑每条算力点须为 0 至 99999999 的整数',
        'media_markup_percent' => '媒体发稿溢价率须为 0 至 9900 的数值，最多两位小数',
        'geo_markup_percent' => 'GEO 检测溢价率须为 0 至 9900 的数值，最多两位小数',
    ];
}
