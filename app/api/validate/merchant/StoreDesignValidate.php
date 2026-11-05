<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/**
 * 门店装修参数校验。
 */
class StoreDesignValidate extends BaseValidate
{
    protected $rule = [
        'store_id' => 'require|integer|gt:0',
        'brand_id' => 'integer|egt:0',
        'config' => 'require',
    ];

    protected $message = [
        'store_id.require' => '请选择门店',
        'config.require' => '请提交装修配置',
    ];

    /**
     * @return StoreDesignValidate
     */
    public function sceneDetail()
    {
        return $this->only(['store_id']);
    }

    /**
     * @return StoreDesignValidate
     */
    public function sceneSave()
    {
        return $this->only(['store_id', 'config']);
    }

    /**
     * @return StoreDesignValidate
     */
    public function sceneSummaries()
    {
        return $this->only(['brand_id']);
    }
}
