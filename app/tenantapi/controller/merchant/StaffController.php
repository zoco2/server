<?php

namespace app\tenantapi\controller\merchant;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\merchant\StaffLists;
use app\tenantapi\logic\merchant\StaffAdminLogic;
use app\tenantapi\validate\merchant\StaffValidate;

/**
 * 门店员工。
 */
class StaffController extends BaseAdminController
{
    /**
     * @notes 员工列表
     * @return \think\response\Json
     */
    public function lists()
    {
        return $this->dataLists(new StaffLists());
    }

    /**
     * @notes 员工详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StaffValidate())->get()->goCheck('detail');
        $result = StaffAdminLogic::detail((int)$params['id'], $this->adminInfo['tenant_id']);
        if ($result === [] && StaffAdminLogic::hasError()) {
            return $this->fail(StaffAdminLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 创建员工
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new StaffValidate())->post()->goCheck('add');
        if (!StaffAdminLogic::add($params, $this->adminInfo['tenant_id'])) {
            return $this->fail(StaffAdminLogic::getError());
        }
        return $this->success('创建成功', [], 1, 1);
    }

    /**
     * @notes 编辑员工
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new StaffValidate())->post()->goCheck('edit');
        if (!StaffAdminLogic::edit($params, $this->adminInfo['tenant_id'])) {
            return $this->fail(StaffAdminLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    /**
     * @notes 删除员工
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new StaffValidate())->post()->goCheck('delete');
        if (!StaffAdminLogic::delete((int)$params['id'], $this->adminInfo['tenant_id'])) {
            return $this->fail(StaffAdminLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
