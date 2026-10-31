<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\logic\merchant\StoreLogic;
use app\api\validate\merchant\StoreValidate;

/**
 * 商家门店。
 */
class StoreController extends BaseApiController
{
    /**
     * @return \think\response\Json
     */
    public function lists()
    {
        $params = (new StoreValidate())->get()->goCheck('lists');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        return $this->data(StoreLogic::lists($tenantId, $userId, $params));
    }

    /**
     * @return \think\response\Json
     */
    public function choices()
    {
        $params = (new StoreValidate())->get()->goCheck('choices');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        return $this->data(StoreLogic::choices($tenantId, $userId, (int)($params['brand_id'] ?? 0)));
    }

    /**
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new StoreValidate())->get()->goCheck('detail');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        $result = StoreLogic::detail($tenantId, $userId, (int)$params['id']);
        if ($result === [] && StoreLogic::hasError()) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new StoreValidate())->post()->goCheck('add');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StoreLogic::add($tenantId, $userId, $params)) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('创建成功');
    }

    /**
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new StoreValidate())->post()->goCheck('edit');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StoreLogic::edit($tenantId, $userId, $params)) {
            return $this->fail(StoreLogic::getError());
        }
        return $this->success('保存成功');
    }

    /**
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new StoreValidate())->post()->goCheck('delete');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!StoreLogic::delete($tenantId, $userId, (int)$params['id'])) {
            return $this->fail(StoreLogic::getError());
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
