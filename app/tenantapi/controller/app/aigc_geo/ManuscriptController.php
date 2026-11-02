<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoManuscriptService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

/**
 * 租户后台文稿代发配置。
 */
class ManuscriptController extends BaseAdminController
{
    /**
     * 查看海讯社账号是否已配置。不返回 token、key 和访问令牌。
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
