<?php

namespace app\tenantapi\controller\merchant;

use app\common\validate\merchant\BrandValidate;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\merchant\BrandLogic;

class BrandController extends BaseAdminController
{
    public function add()
    {
        $params = (new BrandValidate())->post()->goCheck('add');
        $brand = BrandLogic::add($params, $this->tenantId);
        if ($brand === null) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('品牌创建成功', $brand, 1, 1);
    }
}
