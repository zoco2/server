<?php

namespace app\common\service\smart_edit;

use Exception;
use app\common\model\TenantConfig;

/**
 * 智能剪辑的火山引擎语音合成配置。
 *
 * 与阿里云那套一样独立：appid / access_token / 音色集群 都在这里配，
 * 不读取、不依赖任何其他语音设置。
 *
 * 存在 la_tenant_config（type=smart_edit, name=volcengine），不新建表。
 *
 * 火山大模型语音合成 2.0 只认 V3 接口（/api/v3/tts/unidirectional），
 * 鉴权走 X-Api-Key，旧的 /api/v1/tts 不支持 2.0 音色。
 */
class SmartEditVoiceConfigService
{
    private const TYPE = 'smart_edit';
    private const NAME = 'volcengine';

    /** 2.0 音色对应的资源 ID；用 1.0 音色时是 seed-tts-1.0 */
    public const RESOURCE_ID_2_0 = 'seed-tts-2.0';
    public const RESOURCE_ID_1_0 = 'seed-tts-1.0';

    /** 默认接口地址 */
    private const DEFAULT_ENDPOINT = 'https://openspeech.bytedance.com/api/v3/tts/unidirectional';

    /**
     * 读取原始配置（不做校验）。
     *
     * @return array{api_key:string,endpoint:string,resource_id:string,appid:string,default_speed:float}
     */
    public static function get(int $tenantId): array
    {
        $raw = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->value('value');

        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $resourceId = trim((string)($data['resource_id'] ?? ''));
        return [
            'api_key' => trim((string)($data['api_key'] ?? '')),
            'endpoint' => trim((string)($data['endpoint'] ?? '')) ?: self::DEFAULT_ENDPOINT,
            'resource_id' => $resourceId !== '' ? $resourceId : self::RESOURCE_ID_2_0,
            'appid' => trim((string)($data['appid'] ?? '')),
            // 语速倍数，1 表示原速；留给后续「配音语速」配置
            'default_speed' => isset($data['default_speed']) && is_numeric($data['default_speed'])
                ? (float)$data['default_speed']
                : 1.0,
        ];
    }

    /**
     * 读取并校验，缺项时抛出可读提示。
     *
     * @throws Exception
     */
    public static function require(int $tenantId): array
    {
        $config = self::get($tenantId);

        $missing = [];
        if ($config['api_key'] === '') {
            $missing[] = 'API Key';
        }
        if ($config['endpoint'] === '') {
            $missing[] = '接口地址';
        }
        if ($missing !== []) {
            throw new Exception('请先在「智能剪辑 → 火山语音配置」中填写：' . implode('、', $missing));
        }

        return $config;
    }

    public static function save(int $tenantId, array $params): void
    {
        $payload = json_encode([
            'api_key' => trim((string)($params['api_key'] ?? '')),
            'endpoint' => trim((string)($params['endpoint'] ?? '')) ?: self::DEFAULT_ENDPOINT,
            'resource_id' => trim((string)($params['resource_id'] ?? '')) ?: self::RESOURCE_ID_2_0,
            'appid' => trim((string)($params['appid'] ?? '')),
            'default_speed' => isset($params['default_speed']) && is_numeric($params['default_speed'])
                ? (float)$params['default_speed']
                : 1.0,
        ], JSON_UNESCAPED_UNICODE);

        $row = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->findOrEmpty();

        if ($row->isEmpty()) {
            TenantConfig::create([
                'tenant_id' => $tenantId,
                'type' => self::TYPE,
                'name' => self::NAME,
                'value' => $payload,
            ]);
            return;
        }
        $row->save(['value' => $payload]);
    }

    /**
     * 前端回显：API Key 不回明文，只说有没有配。
     */
    public static function forDisplay(int $tenantId): array
    {
        $config = self::get($tenantId);
        $config['api_key'] = '';
        $config['api_key_set'] = self::get($tenantId)['api_key'] !== '';
        return $config;
    }
}
