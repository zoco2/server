<?php

namespace app\platformapi\controller\merchant;

use app\common\service\merchant\NfcCodeService;
use app\platformapi\controller\BaseAdminController;
use DomainException;

/**
 * 平台后台的 NFC 码牌入口，负责请求和响应，业务校验及库存操作由服务处理。
 *
 * 继承平台控制器取得登录管理员 ID，访问仍受平台登录和菜单权限中间件约束。
 * 支持库存列表、批量生成及租户分配，不接收客户端指定的操作管理员 ID。
 */
class NfcCodeController extends BaseAdminController
{
    /**
     * GET /platformapi/merchant.nfc_code/lists
     *
     * 查询参数包含 code、batch_no、page_no、page_size；返回筛选后的总数和分页记录。
     * 参数校验失败转换为项目统一失败响应，数据库等其他异常由框架统一处理。
     *
     * @return \think\response\Json
     */
    public function lists()
    {
        try {
            return $this->data(NfcCodeService::adminLists($this->request->get()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /**
     * POST /platformapi/merchant.nfc_code/generate
     *
     * 请求 quantity 为 1 至 1000 的整数；成功返回 batch_no 和实际生成数量 quantity。
     * 使用当前登录态中的 adminId 记录来源，保留 quantity 原始类型交给服务严格校验。
     *
     * @return \think\response\Json
     */
    public function generate()
    {
        // 生成会写入库存，拒绝通过 GET 等只读请求方式触发。
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            // 平台请求层会解包响应中的 data，前端可直接读取 batch_no 和 quantity。
            return $this->success('生成成功', NfcCodeService::generate($this->adminId, $this->request->post('quantity')));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }

    /**
     * GET /platformapi/merchant.nfc_code/tenantChoices
     *
     * 通过 keyword 搜索有效租户的名称或 ID，只返回分配弹窗所需的 ID 和名称。
     * 此接口通过菜单 SQL 登记独立的租户选项权限，沿用原有权限中间件。
     *
     * @return \think\response\Json
     */
    public function tenantChoices()
    {
        return $this->data(NfcCodeService::tenantChoices($this->request->get('keyword/s', '')));
    }

    /**
     * POST /platformapi/merchant.nfc_code/assignTenant
     *
     * 提交 ids 数组和 tenant_id，成功返回 quantity；租户/库存校验由服务事务处理。
     * 管理员 ID 来自平台登录态，不信任请求体中的操作人字段。
     *
     * @return \think\response\Json
     */
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
