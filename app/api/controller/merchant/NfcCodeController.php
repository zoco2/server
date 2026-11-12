<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\common\service\merchant\NfcCodeService;
use DomainException;

class NfcCodeController extends BaseApiController
{
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::storeLists(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->get()
            ));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage(), [], 0, 0);
        }
    }

    public function activate()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('激活成功', NfcCodeService::activate(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage(), [], 0, 0);
        }
    }
}
