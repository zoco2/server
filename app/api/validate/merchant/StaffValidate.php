<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/**
 * 员工表单校验。
 */
class StaffValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'store_id' => 'require|integer|gt:0',
        'name' => 'require|length:1,60',
        'mobile' => 'require|regex:/^1\d{10}$/',
    ];

    protected $message = [
        'id.require' => '请选择员工',
        'store_id.require' => '请选择所属门店',
        'name.require' => '请填写员工姓名',
        'name.length' => '员工姓名不能超过60个字',
        'mobile.require' => '请填写手机号',
        'mobile.regex' => '请填写11位手机号',
    ];

    /**
     * @return StaffValidate
     */
    public function sceneAdd()
    {
        return $this->remove('id', 'require|integer|gt:0');
    }

    /**
     * @return StaffValidate
     */
    public function sceneEdit()
    {
        return $this;
    }

    /**
     * @return StaffValidate
     */
    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    /**
     * @return StaffValidate
     */
    public function sceneDelete()
    {
        return $this->only(['id']);
    }
}
