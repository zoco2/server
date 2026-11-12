<?php

namespace app\platformapi\controller\merchant;

use app\common\service\merchant\NfcCodeService;
use app\platformapi\controller\BaseAdminController;
use DomainException;

class NfcCodeController extends BaseAdminController
{
    /** 查询NFC码牌 */
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::adminLists($this->request->get()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /** 查询可分配租户 */
    public function tenantChoices()
    {
        return $this->data(NfcCodeService::tenantChoices(trim($this->request->get('keyword/s', ''))));
    }

    /** 批量生成NFC码牌 */
    public function generate()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('生成成功', NfcCodeService::generate($this->adminId, $this->request->post('quantity')));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /** 分配NFC码牌给租户 */
    public function assignTenant()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('分配成功', NfcCodeService::assignTenant($this->adminId, $this->request->post()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
