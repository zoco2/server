<?php

namespace app\tenantapi\controller\merchant;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\merchant\StoreLists;
use app\tenantapi\logic\merchant\StoreAdminLogic;
use app\tenantapi\validate\merchant\StoreValidate;

/**
 * 商家门店。
 */
class StoreController extends BaseAdminController
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
     * @notes 门店详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StoreValidate())->get()->goCheck('detail');
        $result = StoreAdminLogic::detail((int)$params['id'], $this->adminInfo['tenant_id']);
        if ($result === [] && StoreAdminLogic::hasError()) {
            return $this->fail(StoreAdminLogic::getError());
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
        if (!StoreAdminLogic::add($params, $this->adminInfo['tenant_id'])) {
            return $this->fail(StoreAdminLogic::getError());
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
        if (!StoreAdminLogic::edit($params, $this->adminInfo['tenant_id'])) {
            return $this->fail(StoreAdminLogic::getError());
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
        if (!StoreAdminLogic::delete((int)$params['id'], $this->adminInfo['tenant_id'])) {
            return $this->fail(StoreAdminLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
