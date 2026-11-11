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

    public const PROFILE_FIELDS = [
        'name', 'industry', 'contact_name', 'contact_phone', 'contact_email', 'website',
        'address', 'logo_url', 'cover_url',
    ];

    public const SECTION_LABELS = [
        'company_intro' => '企业介绍',
        'products_services' => '产品与服务',
        'target_customers' => '目标客户与地区',
        'competitor_facts' => '竞品与差异事实',
    ];

    public static function profileFields(array $params): array
    {
        $fields = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $fields[$field] = trim((string)($params[$field] ?? ''));
        }
        return $fields;
    }

    protected $name = 'merchant_brand';

    protected $deleteTime = 'delete_time';
}
