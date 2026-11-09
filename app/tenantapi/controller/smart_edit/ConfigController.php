<?php

namespace app\tenantapi\controller\smart_edit;

use app\common\service\smart_edit\SmartEditDubbingService;
use app\common\service\smart_edit\SmartEditImsConfigService;
use app\common\service\smart_edit\SmartEditVoiceConfigService;
use app\common\service\smart_edit\SmartEditVoiceService;
use Exception;
use app\tenantapi\controller\BaseAdminController;

/**
 * 租户后台：智能剪辑的阿里云配置与火山语音配置。
 *
 * 两边都是完全独立的一套配置，不读取、不依赖任何其他存储设置。
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

    /**
     * 火山语音配置回显；API Key 不回明文。
     */
    public function voiceDetail()
    {
        return $this->success('获取成功', SmartEditVoiceConfigService::forDisplay($this->tenantId));
    }

    public function voiceSetup()
    {
        try {
            $params = $this->request->post();
            // 与阿里云配置同理：前端回显时密钥是空的，不能把已有 Key 覆盖掉
            if (!array_key_exists('api_key', $params) || (string)$params['api_key'] === '') {
                $current = SmartEditVoiceConfigService::get($this->tenantId);
                $params['api_key'] = $current['api_key'];
            }
            SmartEditVoiceConfigService::save($this->tenantId, $params);
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 音色清单：内置 + 租户自定义。后台要编辑音色 ID，所以带 provider_asset_id。
     */
    public function voiceLists()
    {
        return $this->success('获取成功', SmartEditVoiceService::options($this->tenantId, true));
    }

    public function voiceSave()
    {
        try {
            $rows = $this->request->post('voices', []);
            SmartEditVoiceService::saveCustom($this->tenantId, is_array($rows) ? $rows : []);
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 试听一个音色，返回可直接播放的地址。
     *
     * 配置页用来确认 API Key 与音色 ID 是否有效，不产生配音记录。
     */
    public function voicePreview()
    {
        try {
            $result = SmartEditDubbingService::preview(
                $this->tenantId,
                (string)$this->request->post('voice_key', ''),
                (string)$this->request->post('text', '')
            );
            return $this->success('合成成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
