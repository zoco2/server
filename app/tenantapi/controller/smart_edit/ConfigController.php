<?php

namespace app\tenantapi\controller\smart_edit;

use app\common\service\smart_edit\SmartEditImsConfigService;
use Exception;
use app\tenantapi\controller\BaseAdminController;

/**
 * 租户后台：智能剪辑的阿里云配置。
 *
 * 完全独立的一套配置，不读取、不依赖任何其他存储设置。
 */
class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', SmartEditImsConfigService::forDisplay($this->tenantId));
    }

    public function setup()
    {
        try {
            $params = $this->request->post();
            // 未回填 secret_key 时保留原值，避免前端脱敏回显把密钥覆盖成空
            if (!array_key_exists('secret_key', $params) || (string)$params['secret_key'] === '') {
                $current = SmartEditImsConfigService::get($this->tenantId);
                $params['secret_key'] = $current['secret_key'];
            }
            SmartEditImsConfigService::save($this->tenantId, $params);
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
