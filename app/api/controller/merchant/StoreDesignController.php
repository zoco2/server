<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\logic\merchant\StoreDesignLogic;
use app\api\validate\merchant\StoreDesignValidate;

/**
 * 门店装修草稿。
 */
class StoreDesignController extends BaseApiController
{
    public array $notNeedLogin = ['storefront'];

    /** @return \think\response\Json */
    public function storefront()
    {
        $params = (new StoreDesignValidate())->get()->goCheck('detail');
        $result = StoreDesignLogic::storefront((int)$params['store_id']);
        if ($result === []) {
            return $this->fail(StoreDesignLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 读取门店装修
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StoreDesignValidate())->get()->goCheck('detail');
        $result = StoreDesignLogic::detail($params);
        if ($result === [] && StoreDesignLogic::hasError()) {
            return $this->fail(StoreDesignLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 保存门店装修
     * @return \think\response\Json
     */
    public function save()
    {
        // 全局 trim 会把 JSON 布尔值 false 转成空字符串。
        $params = (new StoreDesignValidate())->post()->goCheck('save', [
            'config' => $this->request->post('config', null, null),
        ]);
        $result = StoreDesignLogic::save($params);
        if ($result === [] && StoreDesignLogic::hasError()) {
            return $this->fail(StoreDesignLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 品牌下门店装修摘要
     * @return \think\response\Json
     */
    public function summaries()
    {
        $params = (new StoreDesignValidate())->get()->goCheck('summaries');
        $result = StoreDesignLogic::summaries((int)($params['brand_id'] ?? 0));
        if ($result === [] && StoreDesignLogic::hasError()) {
            return $this->fail(StoreDesignLogic::getError());
        }
        return $this->data($result);
    }
}
