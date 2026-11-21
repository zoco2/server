<?php

namespace app\tenantapi\controller\setting;

use app\common\service\pricing\BusinessPriceService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;

class BusinessPriceController extends BaseAdminController
{
    public function detail()
    {
        if (!$this->request->isGet()) return $this->fail('请使用 GET 请求');
        return $this->data(BusinessPriceService::get($this->tenantId));
    }

    public function save()
    {
        if (!$this->request->isPost()) return $this->fail('请使用 POST 请求');
        try {
            BusinessPriceService::save($this->tenantId, $this->request->post());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('价格设置已保存', [], 1, 1);
    }
}
