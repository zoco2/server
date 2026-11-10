<?php

namespace app\tenantapi\controller\wenan;

use app\common\service\wenan\WenanConfigService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

/**
 * 租户后台：文案 LLM 模型配置（知识库 / 智能剪辑等共用）。
 */
class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', WenanConfigService::forDisplay($this->tenantId));
    }

    public function setup()
    {
        try {
            WenanConfigService::save($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
