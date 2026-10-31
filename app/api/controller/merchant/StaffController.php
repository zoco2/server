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
     * @notes 员工列表
     * @return \think\response\Json
     */
    public function lists()
    {
        $params = (new StaffValidate())->get()->goCheck('lists');
        return $this->data(StaffLogic::lists($params));
    }

    /**
     * @notes 员工详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StaffValidate())->get()->goCheck('detail');
        $result = StaffLogic::detail((int)$params['id']);
        if ($result === [] && StaffLogic::hasError()) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 添加员工
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new StaffValidate())->post()->goCheck('add');
        if (!StaffLogic::add($params)) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('添加成功', [], 1, 1);
    }

    /**
     * @notes 编辑员工
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new StaffValidate())->post()->goCheck('edit');
        if (!StaffLogic::edit($params)) {
            return $this->fail(StaffLogic::getError());
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
        if (!StaffLogic::delete((int)$params['id'])) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
