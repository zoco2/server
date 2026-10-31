<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\logic\merchant\BrandLogic;
use app\api\validate\merchant\BrandValidate;

/**
 * 商家品牌。
 */
class BrandController extends BaseApiController
{
    /**
     * @return \think\response\Json
     */
    public function lists()
    {
        $params = (new BrandValidate())->get()->goCheck('lists');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        return $this->data(BrandLogic::lists($tenantId, $userId, $params));
    }

    /**
     * @return \think\response\Json
     */
    public function choices()
    {
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        return $this->data(BrandLogic::choices($tenantId, $userId));
    }

    /**
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new BrandValidate())->get()->goCheck('detail');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        $result = BrandLogic::detail($tenantId, $userId, (int)$params['id']);
        if ($result === [] && BrandLogic::hasError()) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new BrandValidate())->post()->goCheck('add');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!BrandLogic::add($tenantId, $userId, $params)) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('创建成功');
    }

    /**
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new BrandValidate())->post()->goCheck('edit');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!BrandLogic::edit($tenantId, $userId, $params)) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('保存成功');
    }

    /**
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new BrandValidate())->post()->goCheck('delete');
        [$tenantId, $userId] = $this->owner();
        if ($tenantId <= 0) {
            return $this->fail('请先登录');
        }
        if (!BrandLogic::delete($tenantId, $userId, (int)$params['id'])) {
            return $this->fail(BrandLogic::getError());
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
