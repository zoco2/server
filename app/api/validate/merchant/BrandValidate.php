<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/**
 * 品牌表单校验。
 */
class BrandValidate extends BaseValidate
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
        'page' => 'integer|gt:0',
        'limit' => 'integer|between:1,50',
        'keyword' => 'max:100',
    ];

    protected $message = [
        'id.require' => '请选择品牌',
        'name.require' => '请填写品牌名称',
        'name.length' => '品牌名称不能超过100个字',
        'contact_email.email' => '联系邮箱格式不正确',
        'website.checkUrl' => '官方网站需以 http:// 或 https:// 开头',
        'logo_url.checkUrl' => '品牌 Logo 需为 http:// 或 https:// 地址',
        'cover_url.checkUrl' => '品牌封面需为 http:// 或 https:// 地址',
    ];

    /**
     * @return BrandValidate
     */
    public function sceneAdd()
    {
        return $this->remove('id', 'require|integer|gt:0');
    }

    /**
     * @return BrandValidate
     */
    public function sceneEdit()
    {
        return $this;
    }

    /**
     * @return BrandValidate
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @return BrandValidate
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    /**
     * @return BrandValidate
     */
    public function sceneLists()
    {
        return $this->only(['page', 'limit', 'keyword']);
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
}
