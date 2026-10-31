<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\logic\merchant\StaffLogic;
use app\api\validate\merchant\StaffValidate;

/**
 * 门店员工。
 */
class StaffController extends BaseApiController
{
    /**
     * @return \think\response\Json
     */
    public function lists()
    {
        $params = (new StaffValidate())->get()->goCheck('lists');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        return $this->data(StaffLogic::lists($tenantId, $userId, $params));
    }

    /**
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StaffValidate())->get()->goCheck('detail');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        $result = StaffLogic::detail($tenantId, $userId, (int)$params['id']);
        if ($result === [] && StaffLogic::hasError()) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new StaffValidate())->post()->goCheck('add');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StaffLogic::add($tenantId, $userId, $params)) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('添加成功');
    }

    /**
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new StaffValidate())->post()->goCheck('edit');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StaffLogic::edit($tenantId, $userId, $params)) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('保存成功');
    }

    /**
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new StaffValidate())->post()->goCheck('delete');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StaffLogic::delete($tenantId, $userId, (int)$params['id'])) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('删除成功');
    }

    /**
     * @return array{0:int,1:int}
     */
    private function owner(): array
    {
        return [(int)($this->request->tenantId ?? 0), $this->userId];
    }
}
