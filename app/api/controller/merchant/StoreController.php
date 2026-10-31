<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\StoreLists;
use app\api\logic\merchant\StoreLogic;
use app\api\validate\merchant\StoreValidate;

/**
 * 商家门店。
 */
class StoreController extends BaseApiController
{
    /**
     * @notes 门店列表
     * @return \think\response\Json
     */
    public function lists()
    {
        return $this->dataLists(new StoreLists());
    }

    /**
     * @notes 门店下拉
     * @return \think\response\Json
     */
    public function choices()
    {
        $params = (new StoreValidate())->get()->goCheck('choices');
        return $this->data(StoreLogic::choices((int)($params['brand_id'] ?? 0)));
    }

    /**
     * @notes 门店详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StoreValidate())->get()->goCheck('detail');
        $result = StoreLogic::detail((int)$params['id']);
        if ($result === [] && StoreLogic::hasError()) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 创建门店
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new StoreValidate())->post()->goCheck('add');
        if (!StoreLogic::add($params)) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('创建成功', [], 1, 1);
    }

    /**
     * @notes 编辑门店
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new StoreValidate())->post()->goCheck('edit');
        if (!StoreLogic::edit($params)) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    /**
     * @notes 删除门店
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new StoreValidate())->post()->goCheck('delete');
        if (!StoreLogic::delete((int)$params['id'])) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
