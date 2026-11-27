<?php

declare(strict_types=1);

namespace app\tenantapi\controller\merchant;

use app\common\service\merchant\NfcCodeService;
use app\tenantapi\controller\BaseAdminController;
use DomainException;

class NfcCodeController extends BaseAdminController
{
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::tenantLists($this->request->get()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
