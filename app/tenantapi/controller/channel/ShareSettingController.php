<?php

declare(strict_types=1);

namespace app\tenantapi\controller\channel;

use app\common\service\share\TenantShareConfigService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;
use Throwable;

class ShareSettingController extends BaseAdminController
{
    /** @notes 查看分享配置 */
    public function getConfig()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }
        try {
            return $this->data((new TenantShareConfigService($this->tenantId))->display())
                ->header(['Cache-Control' => 'private, no-store']);
        } catch (Throwable) {
            return $this->fail('读取分享配置失败，请稍后重试');
        }
    }

    /** @notes 保存分享配置 */
    public function setConfig()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            // tenantId 来自已验证的管理员身份，忽略客户端提交的 tenant_id。
            $result = (new TenantShareConfigService($this->tenantId))->save($this->request->post());
            return $this->success('分享配置已保存', $result, 1, 1);
        } catch (InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        } catch (Throwable) {
            // 不把 SQL、加密信息或异常参数暴露给客户端/操作日志。
            return $this->fail('保存分享配置失败，请稍后重试');
        }
    }
}
