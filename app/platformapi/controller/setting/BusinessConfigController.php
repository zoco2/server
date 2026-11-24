<?php

namespace app\platformapi\controller\setting;

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
            $ims = SmartEditImsConfigService::forDisplay(0);
            $voice = SmartEditVoiceConfigService::forDisplay(0);
            $media = AigcGeoManuscriptService::configDetail(0);
            // 管理页只回显凭据状态，留空保存时沿用现有值。
            unset($ims['secret_key'], $voice['api_key'], $media['token'], $media['key']);

            return $this->data([
                'prices' => ConfigService::getPlatform('business_price', 'settings', []) + self::PRICES,
                'ims' => $ims,
                'voice' => $voice,
                'voices' => SmartEditVoiceService::custom(0),
                'voice_options' => SmartEditVoiceService::options(0),
                'wenan' => WenanConfigService::forDisplay(0),
                'geo' => ['api_key_set' => GeoDiagConfigService::get(0)['api_key'] !== ''],
                'media' => $media,
                'share' => PlatformShareConfigService::display(),
            ])->header(['Cache-Control' => 'private, no-store']);
        } catch (Throwable) {
            return $this->fail('读取平台基础配置失败');
        }
    }

    /** @notes 保存平台基础配置 */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        $params = $this->request->post();
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
                        ?: SmartEditImsConfigService::get(0)['secret_key'];
                    foreach (['region', 'access_key', 'secret_key', 'bucket', 'domain'] as $field) {
                        if (trim((string)($params[$field] ?? '')) === '') {
                            throw new InvalidArgumentException('请填写完整的智能剪辑阿里云配置');
                        }
                    }
                    SmartEditImsConfigService::savePlatform($params);
                    break;
                case 'voice':
                    $params['api_key'] = trim((string)($params['api_key'] ?? ''))
                        ?: SmartEditVoiceConfigService::get(0)['api_key'];
                    if ($params['api_key'] === '') {
                        throw new InvalidArgumentException('请填写火山语音 API Key');
                    }
                    SmartEditVoiceConfigService::savePlatform($params);
                    break;
                case 'voices':
                    if (!is_array($params['voices'] ?? null)) {
                        throw new InvalidArgumentException('请提交有效的自定义音色列表');
                    }
                    SmartEditVoiceService::savePlatform($params['voices']);
                    break;
                case 'wenan':
                    WenanConfigService::save(0, $params);
                    break;
                case 'geo':
                    $params['api_key'] = trim((string)($params['api_key'] ?? ''))
                        ?: GeoDiagConfigService::get(0)['api_key'];
                    GeoDiagConfigService::savePlatform($params);
                    break;
                case 'media':
                    AigcGeoManuscriptService::saveConfig(0, $params);
                    break;
                case 'share':
                    PlatformShareConfigService::save($params);
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

        return $this->success('配置已保存', [], 1, 1);
    }

    /** @notes 试听平台剪辑音色 */
    public function voicePreview()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        try {
            $result = SmartEditDubbingService::preview(0, (string)$this->request->post('voice_key', ''));
            return $this->data($result);
        } catch (Throwable) {
            return $this->fail('音色试听失败，请核对阿里云和火山语音配置');
        }
    }
}
