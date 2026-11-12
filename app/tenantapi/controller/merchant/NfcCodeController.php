<?php

namespace app\tenantapi\controller\merchant;

use app\common\service\merchant\NfcCodeService;
use app\tenantapi\controller\BaseAdminController;
use DomainException;

class NfcCodeController extends BaseAdminController
{
    /** 查询租户NFC码牌 */
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::adminLists($this->request->get(), $this->tenantId));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /** 查询可分配用户 */
    public function userChoices()
    {
        return $this->data(NfcCodeService::userChoices($this->tenantId, trim($this->request->get('keyword/s', ''))));
    }

    /** 分配NFC码牌给用户 */
    public function assignUser()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('分配成功', NfcCodeService::assignUser($this->tenantId, $this->adminId, $this->request->post()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
