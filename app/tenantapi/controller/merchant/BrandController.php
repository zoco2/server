<?php

namespace app\tenantapi\controller\merchant;

use app\common\validate\merchant\BrandValidate;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\merchant\BrandLists;
use app\tenantapi\logic\merchant\BrandAdminLogic;
use app\tenantapi\logic\merchant\BrandLogic;
use app\tenantapi\validate\merchant\BrandAdminValidate;

class BrandController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new BrandLists());
    }

    public function detail()
    {
        $params = (new BrandAdminValidate())->goCheck('detail');
        $brand = BrandAdminLogic::detail((int)$params['id']);
        return $brand === [] ? $this->fail(BrandAdminLogic::getError()) : $this->data($brand);
    }

    public function edit()
    {
        $params = (new BrandAdminValidate())->post()->goCheck('edit');
        if (!BrandAdminLogic::edit($params)) {
            return $this->fail(BrandAdminLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }


    public function add()
    {
        $params = (new BrandValidate())->post()->goCheck('add');
        $brand = BrandLogic::add($params, $this->tenantId);
        if ($brand === null) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('品牌创建成功', $brand, 1, 1);
    }

    /**
     * 删除用户品牌
     */
    public function delete()
    {
        $params = (new BrandValidate())->post()->goCheck('tenantDelete');
        if (!BrandLogic::delete($this->tenantId, (int)$params['user_id'], (int)$params['id'])) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('品牌删除成功', [], 1, 1);
    }
}
