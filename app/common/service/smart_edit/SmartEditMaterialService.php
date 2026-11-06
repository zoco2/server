<?php

namespace app\common\service\smart_edit;

use app\common\model\file\TenantFile;
use Exception;

/**
 * 智能剪辑的素材与输出地址解析。
 *
 * 全部基于 SmartEditImsConfigService 里那套独立的阿里云配置，
 * 不读取任何其他存储设置。
 */
class SmartEditMaterialService
{
    /**
     * 素材 uri -> IMS 能拉取的公网地址。
     *
     * @param array $config SmartEditImsConfigService::require() 的结果
     */
    public static function mediaUrl(array $config, int $tenantId, string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            throw new Exception('素材地址不能为空');
        }

        // 允许直接传完整地址：只接受本配置域名下的对象
        if (preg_match('#^https?://#i', $uri)) {
            $host = (string)parse_url($uri, PHP_URL_HOST);
            if ($host === '' || stripos((string)$config['domain'], $host) === false) {
                throw new Exception('只支持使用本租户阿里云配置中的素材');
            }
            $uri = ltrim((string)parse_url($uri, PHP_URL_PATH), '/');
        }

        $uri = ltrim($uri, '/');
        if ($uri === '' || str_contains($uri, '..')) {
            throw new Exception('素材地址非法');
        }

        // 校验素材确实属于本租户，避免任意 key 被拿去合成
        $exists = TenantFile::where(['tenant_id' => $tenantId, 'uri' => $uri])->findOrEmpty();
        if ($exists->isEmpty()) {
            throw new Exception('素材不存在：' . $uri);
        }

        return SmartEditImsConfigService::objectUrl($config, $uri);
    }

    /**
     * 成片输出目标。
     *
     * @return array{uri:string,url:string}
     */
    public static function outputTarget(array $config, string $taskNo): array
    {
        $prefix = trim((string)($config['output_prefix'] ?? '')) ?: 'smart-edit-output';
        $uri = trim($prefix, '/') . '/' . date('Ym') . '/' . $taskNo . '.mp4';
        return [
            'uri' => $uri,
            'url' => SmartEditImsConfigService::joinUrl((string)$config['domain'], $uri),
        ];
    }
}
