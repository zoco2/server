<?php

namespace app\tenantapi\controller\geo;

use app\common\service\geo\GeoDiagConfigService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;
use Throwable;

class DiagnosisConfigController extends BaseAdminController
{
    /** @notes 查看 GEO 检测配置 */
    public function detail()
    {
        if (!$this->request->isGet()) return $this->fail('请使用 GET 请求');
        try {
            return $this->data(GeoDiagConfigService::get($this->tenantId));
        } catch (Throwable) {
            return $this->fail('读取 GEO 检测配置失败');
        }
    }

    /** @notes 保存 GEO 检测配置 */
    public function save()
    {
        if (!$this->request->isPost()) return $this->fail('请使用 POST 请求');
        try {
            GeoDiagConfigService::save($this->tenantId, $this->request->post());
            return $this->success('GEO 检测配置已保存', [], 1, 1);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable) {
            return $this->fail('保存 GEO 检测配置失败');
        }
    }
}
