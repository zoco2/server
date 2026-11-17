<?php

declare(strict_types=1);

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\common\service\merchant\NfcCodeService;
use DomainException;

/** 用户门店 NFC 入口，租户沿用中间件和模型全局作用域，不手动传入业务查询。 */
class NfcCodeController extends BaseApiController
{
    /**
     * GET /api/merchant.nfc_code/lists
     * 查询参数：store_id、page_no、page_size；仅返回本人门店的已绑定码牌。
     */
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::storeLists(
                $this->userId, $this->request->get()
            ));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /**
     * POST /api/merchant.nfc_code/activate
     * 请求体：store_id、code；同一门店可连续绑定多个不同编号。
     */
    public function activate()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('激活成功', NfcCodeService::activate(
                $this->userId, $this->request->post()
            ));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
