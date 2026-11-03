<?php

namespace app\common\service\smart_edit;

use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\StorageSignedUrlService;
use Exception;

/**
 * 智能剪辑的素材解析。
 *
 * IMS 云剪辑要求 MediaURL 是公网可访问的地址，所以这里只接受阿里云 OSS 上的素材：
 * 读取租户已配置的 OSS（设置 → 存储），拼出可拉取 URL，并从 OSS 域名解析出 region。
 */
class SmartEditMaterialService
{
    /**
     * 读取租户/平台生效的阿里云 OSS 配置。
     *
     * @return array{access_key:string,secret_key:string,bucket:string,domain:string,region:string,scope:string}
     */
    public static function ossConfig(int $tenantId): array
    {
        try {
            $storage = StorageConfigService::getConfiguredStorageConfig($tenantId, 'aliyun');
        } catch (\Throwable $e) {
            throw new Exception('请先在「设置 → 存储」中配置阿里云 OSS 后再使用智能剪辑');
        }
        $oss = (array)($storage['engine']['aliyun'] ?? []);
        $bucket = trim((string)($oss['bucket'] ?? ''));
        $domain = trim((string)($oss['domain'] ?? ''));
        if ($bucket === '' || $domain === '') {
            throw new Exception('阿里云 OSS 配置不完整，请到「设置 → 存储」补全 Bucket 与域名');
        }
        $region = self::parseRegion($domain);
        if ($region === '') {
            throw new Exception('无法从 OSS 域名识别地域，请检查 OSS 域名（如 oss-cn-shanghai.aliyuncs.com）');
        }
        return [
            'access_key' => (string)($oss['access_key'] ?? ''),
            'secret_key' => (string)($oss['secret_key'] ?? ''),
            'bucket' => $bucket,
            'domain' => $domain,
            'region' => $region,
            'scope' => (string)($storage['scope'] ?? 'platform'),
        ];
    }

    /**
     * 素材 uri -> IMS 能拉取的公网 URL。
     * 只接受本租户在 OSS 上的文件；私有 bucket 走短期签名。
     */
    public static function mediaUrl(int $tenantId, string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            throw new Exception('素材地址不能为空');
        }
        if (preg_match('#^https?://#i', $uri)) {
            // 已经是完整地址：只允许与租户 OSS 同域的资源
            $oss = self::ossConfig($tenantId);
            if (stripos($uri, $oss['domain']) === false) {
                throw new Exception('只支持使用本账号阿里云 OSS 中的素材');
            }
            $path = (string)parse_url($uri, PHP_URL_PATH);
            $uri = ltrim($path, '/');
        }
        $uri = ltrim($uri, '/');
        if ($uri === '' || str_contains($uri, '..')) {
            throw new Exception('素材地址非法');
        }

        $file = TenantFile::where(['tenant_id' => $tenantId, 'uri' => $uri])->findOrEmpty();
        if ($file->isEmpty()) {
            throw new Exception('素材不存在：' . $uri);
        }
        $engine = (string)($file['storage_engine'] ?? '');
        if ($engine !== 'aliyun') {
            throw new Exception('智能剪辑仅支持阿里云 OSS 存储的素材，请检查该素材的存储方式');
        }

        $scope = (string)($file['storage_scope'] ?? '');
        $domain = (string)($file['storage_domain'] ?? '');
        $signed = StorageSignedUrlService::resolve($uri, $scope, $engine, $domain, $tenantId);
        if ($signed !== null) {
            return $signed;
        }
        return FileService::getFileUrlByStorage($uri, $scope, $engine, $domain);
    }

    /**
     * 输出成品的 OSS 地址（让 IMS 直接写回同一个 bucket）。
     *
     * @return array{uri:string,url:string}
     */
    public static function outputTarget(int $tenantId, string $taskNo): array
    {
        $oss = self::ossConfig($tenantId);
        $uri = 'smart-edit-output/' . date('Ym') . '/' . $taskNo . '.mp4';
        return [
            'uri' => $uri,
            'url' => FileService::format($oss['domain'], $uri),
        ];
    }

    private static function parseRegion(string $domain): string
    {
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        if (preg_match('#^oss-([a-z0-9-]+)\.aliyuncs\.com#i', $domain, $m)) {
            return strtolower($m[1]);
        }
        // 自定义域名 / 内网域名时无法识别，交给调用方提示
        return '';
    }
}