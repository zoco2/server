<?php

namespace app\platformapi\controller\setting;

use app\common\cache\AdminAuthCache;
use app\common\service\ConfigService;
use app\common\service\geo\AigcGeoManuscriptService;
use app\common\service\geo\GeoDiagConfigService;
use app\common\service\share\PlatformShareConfigService;
use app\common\service\smart_edit\SmartEditDubbingService;
use app\common\service\smart_edit\SmartEditImsConfigService;
use app\common\service\smart_edit\SmartEditVoiceConfigService;
use app\common\service\smart_edit\SmartEditVoiceService;
use app\common\service\wenan\WenanConfigService;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\validate\setting\BusinessPriceValidate;
use InvalidArgumentException;
use think\exception\HttpResponseException;
use think\helper\Str;
use Throwable;

class BusinessConfigController extends BaseAdminController
{
    private const PRICES = [
        'smart_edit_points' => 0, 'media_markup_percent' => 0, 'geo_markup_percent' => 0,
    ];

    /** @notes 查看平台基础配置 */
    public function detail()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }

        try {
            $ims = SmartEditImsConfigService::getPlatform();
            $ims['secret_key_set'] = $ims['secret_key'] !== '';
            $voice = SmartEditVoiceConfigService::getPlatform();
            $voice['api_key_set'] = $voice['api_key'] !== '';
            $geo = GeoDiagConfigService::getPlatform();
            $geo['api_key_set'] = $geo['api_key'] !== '';
            $media = AigcGeoManuscriptService::platformConfigDetail();
            $includeSecrets = $this->canEditBusinessConfig();
            if (!$includeSecrets) {
                unset($ims['secret_key'], $voice['api_key'], $geo['api_key'], $media['token'], $media['key']);
            }

            return $this->data([
                'prices' => ConfigService::getPlatform('business_price', 'settings', []) + self::PRICES,
                'ims' => $ims,
                'voice' => $voice,
                'voices' => SmartEditVoiceService::platformCustom(),
                'voice_options' => array_map(
                    static fn(array $voice): array => ['key' => $voice['key'], 'label' => $voice['label']],
                    SmartEditVoiceService::platformCatalog()
                ),
                'wenan' => WenanConfigService::forPlatformDisplay(),
                'geo' => $geo,
                'media' => $media,
                'share' => PlatformShareConfigService::display($includeSecrets),
            ])->header(['Cache-Control' => 'private, no-store']);
        } catch (Throwable) {
            return $this->fail('读取平台基础配置失败');
        }
    }

    /** 基础配置的保存权限同时用于回显已保存凭据。 */
    private function canEditBusinessConfig(): bool
    {
        if ((int)($this->adminInfo['root'] ?? 0) === 1) {
            return true;
        }
        if ($this->adminId <= 0) {
            return false;
        }
        $uris = (new AdminAuthCache($this->adminId))->getAdminUri() ?? [];
        $normalized = array_map(static fn ($uri) => strtolower(Str::camel((string)$uri)), $uris);
        return in_array('setting.businessconfig/save', $normalized, true);
    }

    /** @notes 保存平台基础配置 */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        $params = $this->request->post();
        $saved = [];
        try {
            switch ($params['section'] ?? '') {
                case 'prices':
                    $prices = (new BusinessPriceValidate())->post()->goCheck();
                    ConfigService::set('business_price', 'settings', [
                        'smart_edit_points' => (int)$prices['smart_edit_points'],
                        'media_markup_percent' => (float)$prices['media_markup_percent'],
                        'geo_markup_percent' => (float)$prices['geo_markup_percent'],
                    ]);
                    break;
                case 'ims':
                    $params['secret_key'] = trim((string)($params['secret_key'] ?? ''))
                        ?: SmartEditImsConfigService::getPlatform()['secret_key'];
                    foreach (['region', 'access_key', 'secret_key', 'bucket', 'domain'] as $field) {
                        if (trim((string)($params[$field] ?? '')) === '') {
                            throw new InvalidArgumentException('请填写完整的智能剪辑阿里云配置');
                        }
                    }
                    SmartEditImsConfigService::savePlatform($params);
                    $saved = ['secret_key' => $params['secret_key']];
                    break;
                case 'voice':
                    $params['api_key'] = trim((string)($params['api_key'] ?? ''))
                        ?: SmartEditVoiceConfigService::getPlatform()['api_key'];
                    if ($params['api_key'] === '') {
                        throw new InvalidArgumentException('请填写火山语音 API Key');
                    }
                    SmartEditVoiceConfigService::savePlatform($params);
                    $saved = ['api_key' => $params['api_key']];
                    break;
                case 'voices':
                    if (!is_array($params['voices'] ?? null)) {
                        throw new InvalidArgumentException('请提交有效的自定义音色列表');
                    }
                    SmartEditVoiceService::savePlatform($params['voices']);
                    break;
                case 'wenan':
                    WenanConfigService::savePlatform($params);
                    break;
                case 'geo':
                    $params['api_key'] = trim((string)($params['api_key'] ?? ''))
                        ?: GeoDiagConfigService::getPlatform()['api_key'];
                    GeoDiagConfigService::savePlatform($params);
                    $saved = ['api_key' => $params['api_key']];
                    break;
                case 'media':
                    $saved = AigcGeoManuscriptService::savePlatformConfig($params);
                    break;
                case 'share':
                    $saved = PlatformShareConfigService::save($params, true);
                    break;
                default:
                    throw new InvalidArgumentException('请选择有效的配置区块');
            }
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable) {
            return $this->fail('保存配置失败，请核对配置或稍后重试');
        }

        return $this->success('配置已保存', $saved, 1, 1)->header(['Cache-Control' => 'private, no-store']);
    }

    /** @notes 试听平台剪辑音色 */
    public function voicePreview()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        try {
            $result = SmartEditDubbingService::previewPlatform((string)$this->request->post('voice_key', ''));
            return $this->data($result);
        } catch (Throwable) {
            return $this->fail('音色试听失败，请核对阿里云和火山语音配置');
        }
    }
}
