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
        $params = (new StoreDesignValidate())->post()->goCheck('save');
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
