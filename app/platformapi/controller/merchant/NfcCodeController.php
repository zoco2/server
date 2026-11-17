<?php

namespace app\platformapi\controller\merchant;

use app\common\service\merchant\NfcCodeService;
use app\platformapi\controller\BaseAdminController;
use DomainException;

class NfcCodeController extends BaseAdminController
{
    /** 查询平台 NFC 码牌库存 */
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::adminLists($this->request->get()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /** 批量生成 NFC 码牌 */
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
}
