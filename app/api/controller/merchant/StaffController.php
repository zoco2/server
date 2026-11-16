<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\StaffLists;
use app\api\logic\merchant\StaffLogic;
use app\api\logic\merchant\StaffWechatBindingLogic;
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
        return $this->dataLists(new StaffLists());
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
     * @notes 测试发送员工绑定成功模板
     */
    public function testBindNotice()
    {
        $params = (new StaffValidate())->post()->goCheck('testBindNotice');
        try {
            return $this->data(StaffWechatBindingLogic::testSend((int)$params['id']));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
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

    /**
     * @notes 创建员工公众号绑定二维码
     */
    public function bindQrcode()
    {
        $params = (new StaffValidate())->get()->goCheck('bindQrcode');
        try {
            return $this->data(StaffWechatBindingLogic::createQrcode((int)$params['id']));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 解除员工档案的公众号绑定。 */
    public function unbindWechat()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        $params = (new StaffValidate())->post()->goCheck('unbindWechat');
        if (!StaffLogic::unbindWechat((int)$params['id'])) {
            return $this->fail(StaffLogic::getError());
        }
        return $this->success('解绑成功');
    }

    /**
     * @notes 同步员工绑定成功模板
     */
    public function syncBindTemplate()
    {
        try {
            return $this->data(StaffWechatBindingLogic::syncTemplate());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
