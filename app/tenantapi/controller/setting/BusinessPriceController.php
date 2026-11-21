<?php

namespace app\tenantapi\controller\setting;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\setting\BusinessPriceLogic;
use app\tenantapi\validate\setting\BusinessPriceValidate;

class BusinessPriceController extends BaseAdminController
{
    public function detail()
    {
        (new BusinessPriceValidate())->get();
        return $this->data(BusinessPriceLogic::getConfig());
    }

    public function save()
    {
        $params = (new BusinessPriceValidate())->post()->goCheck();
        BusinessPriceLogic::setConfig($params);
        return $this->success('价格设置已保存', [], 1, 1);
    }
}
