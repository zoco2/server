<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/**
 * 门店表单校验。
 */
class StoreValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'brand_id' => 'require|integer|gt:0',
        'name' => 'require|length:1,100',
        'status' => 'in:0,1',
        'phone' => 'max:32',
        'contact_name' => 'max:60',
        'business_hours' => 'max:64',
        'province' => 'max:40',
        'city' => 'max:40',
        'district' => 'max:40',
        'address' => 'max:255',
        'location' => 'max:40|checkLocation',
        'latitude' => 'checkLatitude',
        'longitude' => 'checkLongitude',
        'poi_id' => 'max:64',
        'intro' => 'max:1000',
        'logo_url' => 'max:500|checkUrl',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|between:1,50',
        'keyword' => 'max:100',
    ];

    protected $message = [
        'id.require' => '请选择门店',
        'brand_id.require' => '请选择所属品牌',
        'name.require' => '请填写门店名称',
        'name.length' => '门店名称不能超过100个字',
        'status.in' => '营业状态不正确',
        'logo_url.checkUrl' => '门店 Logo 需为 http:// 或 https:// 地址',
    ];

    /**
     * @return StoreValidate
     */
    public function sceneAdd()
    {
        return $this->remove('id', 'require|integer|gt:0');
    }

    /**
     * @return StoreValidate
     */
    public function sceneEdit()
    {
        return $this;
    }

    /**
     * @return StoreValidate
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @return StoreValidate
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }

    /**
     * @return StoreValidate
     */
    public function sceneLists()
    {
        return $this->only(['page_no', 'page_size', 'keyword', 'brand_id', 'status'])
            ->remove('brand_id', 'require');
    }

    /**
     * @return StoreValidate
     */
    public function sceneChoices()
    {
        return $this->only(['brand_id'])->remove('brand_id', 'require');
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
        return (bool)preg_match('#^https?://#i', $value);
    }

    /**
     * 表单里的「纬度,经度」。
     *
     * @param mixed $value
     * @return bool|string
     */
    protected function checkLocation($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return true;
        }
        $parts = array_map('trim', explode(',', $value, 2));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return '位置坐标格式应为纬度,经度';
        }
        if ($parts[0] < -90 || $parts[0] > 90 || $parts[1] < -180 || $parts[1] > 180) {
            return '位置坐标超出范围';
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkLatitude($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        if (!is_numeric($value) || $value < -90 || $value > 90) {
            return '纬度超出范围';
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkLongitude($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        if (!is_numeric($value) || $value < -180 || $value > 180) {
            return '经度超出范围';
        }
        return true;
    }
}
