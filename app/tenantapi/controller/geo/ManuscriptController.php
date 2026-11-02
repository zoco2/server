<?php

namespace app\tenantapi\controller\geo;

use app\common\service\geo\AigcGeoManuscriptService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

/**
 * 租户后台文稿代发配置。
 */
class ManuscriptController extends BaseAdminController
{
    /**
     * 查看已保存的海讯社 token、key。不返回访问令牌。
     */
    public function detail()
    {
        return $this->success('获取成功', AigcGeoManuscriptService::configDetail($this->tenantId));
    }

    /**
     * 保存海讯社 token、key，并立即换取访问令牌。
     */
    public function save()
    {
        try {
            return $this->success('保存成功', AigcGeoManuscriptService::saveConfig($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
