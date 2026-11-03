<?php

namespace app\common\service\smart_edit;

use Exception;
use OSS\OssClient;
use app\common\model\TenantConfig;

/**
 * 智能剪辑的阿里云配置。
 *
 * 完全独立：AccessKey / SecretKey / 地域 / Bucket / 域名 都在这里配置，
 * 不读取、不依赖任何其他存储设置。智能剪辑的素材与成片都放在这套配置指向的 Bucket 里。
 *
 * 存在 la_tenant_config（type=smart_edit, name=aliyun），不新建表。
 */
class SmartEditImsConfigService
{
    private const TYPE = 'smart_edit';
    private const NAME = 'aliyun';

    /** 签名 URL 有效期：提交后要排队才开始拉取素材，放宽到 6 小时 */
    private const SIGNED_URL_TTL = 21600;

    /**
     * 读取原始配置（不做校验）。
     *
     * @return array{region:string,access_key:string,secret_key:string,bucket:string,domain:string,private_access:bool,output_prefix:string}
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

        return [
            'region' => trim((string)($data['region'] ?? '')),
            'access_key' => trim((string)($data['access_key'] ?? '')),
            'secret_key' => trim((string)($data['secret_key'] ?? '')),
            'bucket' => trim((string)($data['bucket'] ?? '')),
            'domain' => trim((string)($data['domain'] ?? '')),
            'private_access' => !empty($data['private_access']),
            'output_prefix' => trim((string)($data['output_prefix'] ?? 'smart-edit-output')),
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
        if ($config['region'] === '') {
            $missing[] = '地域';
        }
        if ($config['access_key'] === '' || $config['secret_key'] === '') {
            $missing[] = 'AccessKey';
        }
        if ($config['bucket'] === '') {
            $missing[] = 'Bucket';
        }
        if ($config['domain'] === '') {
            $missing[] = '访问域名';
        }
        if ($missing !== []) {
            throw new Exception('请先在「智能剪辑 → 阿里云配置」中填写：' . implode('、', $missing));
        }

        return $config;
    }

    public static function save(int $tenantId, array $params): void
    {
        $payload = json_encode([
            'region' => trim((string)($params['region'] ?? '')),
            'access_key' => trim((string)($params['access_key'] ?? '')),
            'secret_key' => trim((string)($params['secret_key'] ?? '')),
            'bucket' => trim((string)($params['bucket'] ?? '')),
            'domain' => trim((string)($params['domain'] ?? '')),
            'private_access' => !empty($params['private_access']),
            'output_prefix' => trim((string)($params['output_prefix'] ?? '')) ?: 'smart-edit-output',
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
     * 前端回显：SecretKey 不回明文。
     */
    public static function forDisplay(int $tenantId): array
    {
        $config = self::get($tenantId);
        $config['secret_key'] = '';
        $config['secret_key_set'] = self::get($tenantId)['secret_key'] !== '';
        return $config;
    }

    /**
     * 对象 key -> 可被 IMS 拉取的完整地址。
     */
    public static function objectUrl(array $config, string $uri): string
    {
        $key = ltrim($uri, '/');
        if (!empty($config['private_access'])) {
            $client = new OssClient(
                (string)$config['access_key'],
                (string)$config['secret_key'],
                (string)$config['domain'],
                true
            );
            return $client->signUrl((string)$config['bucket'], $key, self::SIGNED_URL_TTL, OssClient::OSS_HTTP_GET);
        }
        return self::joinUrl((string)$config['domain'], $key);
    }

    /**
     * 用已落库的域名拼地址（不读当前配置，避免改配置导致历史成片失效）。
     */
    public static function joinUrl(string $domain, string $uri): string
    {
        return rtrim(trim($domain), '/') . '/' . ltrim($uri, '/');
    }
}
