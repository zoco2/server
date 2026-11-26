<?php

namespace app\tenantapi\validate\merchant;

use app\common\model\merchant\MerchantBrand;
use app\common\validate\BaseValidate;

/**
 * 品牌表单校验。
 */
class BrandAdminValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'name' => 'require|length:1,100',
        'industry' => 'max:120',
        'contact_name' => 'max:60',
        'contact_phone' => 'max:32',
        'contact_email' => 'email|max:120',
        'website' => 'max:255|checkUrl',
        'address' => 'max:255',
        'logo_url' => 'max:500|checkUrl',
        'cover_url' => 'max:500|checkUrl',
        'company_intro' => 'checkContent',
        'products_services' => 'checkContent',
        'target_customers' => 'checkContent',
        'competitor_facts' => 'checkContent',
    ];

    protected $message = [
        'id.require' => '请选择品牌',
        'id.integer' => '品牌编号无效',
        'id.gt' => '品牌编号无效',
        'name.require' => '请填写品牌名称',
        'name.length' => '品牌名称不能超过100个字',
        'contact_email.email' => '联系邮箱格式不正确',
        'website.checkUrl' => '官方网站需以 http:// 或 https:// 开头',
        'logo_url.checkUrl' => '品牌 Logo 需为 http:// 或 https:// 地址',
        'cover_url.checkUrl' => '品牌封面需为 http:// 或 https:// 地址',
    ];

    /**
     * @return BrandAdminValidate
     */
    public function sceneEdit()
    {
        return $this->only(['id', ...MerchantBrand::PROFILE_FIELDS, ...array_keys(MerchantBrand::SECTION_LABELS)]);
    }

    /**
     * @return BrandAdminValidate
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkUrl($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return true;
        }
        if (!preg_match('#^https?://#i', $value)) {
            return false;
        }
        return true;
    }

    /**
     * 允许空字符串，用来清空这一段。
     * @param mixed $value
     * @return bool|string
     */
    protected function checkContent($value)
    {
        if (!is_string($value)) {
            return '内容格式无效';
        }
        if (mb_strlen($value) > 5000) {
            return '内容不能超过5000个字';
        }
        return true;
    }
}
