<?php

declare(strict_types=1);

namespace app\common\service\share;

use app\common\service\wechat\WechatCredentialService;
use app\common\service\ConfigService;
use InvalidArgumentException;
use RuntimeException;

/** 平台后台管理抖音与小红书分享凭据。 */
final class PlatformShareConfigService
{
    private const PLATFORMS = [
        'douyin' => ['key' => 'client_key', 'secret' => 'client_secret', 'name' => '抖音'],
        'xiaohongshu' => ['key' => 'app_key', 'secret' => 'app_secret', 'name' => '小红书'],
    ];

    public static function display(): array
    {
        $result = [];
        foreach (self::PLATFORMS as $platform => $fields) {
            $value = ConfigService::getPlatform('social_share', $platform, []);
            $secret = self::decryptSecret((string)($value[$fields['secret']] ?? ''));
            $result[$platform] = self::publicConfig($value, $fields);
            if ($secret === '') {
                $result[$platform]['configured'] = false;
            }
        }
        return $result;
    }

    /** 一次保存一个应用的凭据，密钥留空时保留该应用已有的值。 */
    public static function save(array $params): array
    {
        $platform = $params['platform'] ?? null;
        if (!is_string($platform) || !isset(self::PLATFORMS[$platform])) {
            throw new InvalidArgumentException('请选择抖音或小红书');
        }
        $fields = self::PLATFORMS[$platform];
        $key = self::credential($params[$fields['key']] ?? null, 128, '应用标识');
        $secret = self::credential($params[$fields['secret']] ?? '', 256, '应用密钥');

        $old = ConfigService::getPlatform('social_share', $platform, []);
        $value = self::savedValue($old, $fields, $key, $secret);
        ConfigService::set('social_share', $platform, $value);
        return self::publicConfig($value, $fields);
    }

    private static function savedValue(array $old, array $fields, string $key, string $secret): array
    {
        if ($secret === '' && $key !== (string)($old[$fields['key']] ?? '')
            && (string)($old[$fields['secret']] ?? '') !== '') {
            throw new InvalidArgumentException('更换应用标识时请同时填写对应的新密钥');
        }
        $encrypted = (string)($old[$fields['secret']] ?? '');
        if ($secret !== '') {
            $cipher = WechatCredentialService::encrypt($secret);
            if ($cipher === '') {
                throw new RuntimeException('分享密钥加密失败');
            }
            $encrypted = 'enc:v1:' . $cipher;
        }
        if ($key === '' || $encrypted === '') {
            throw new InvalidArgumentException('请填写分享应用标识和密钥');
        }
        return ['enabled' => 1, $fields['key'] => $key, $fields['secret'] => $encrypted];
    }

    public static function runtime(string $platform): array
    {
        if (!isset(self::PLATFORMS[$platform])) {
            throw new InvalidArgumentException('不支持的分享平台');
        }
        $fields = self::PLATFORMS[$platform];
        $value = ConfigService::getPlatform('social_share', $platform, []);
        $cipher = (string)($value[$fields['secret']] ?? '');
        $secret = self::decryptSecret($cipher);
        if (empty($value[$fields['key']]) || $secret === '') {
            throw new ShareServiceException($fields['name'] . '分享凭据不可用，请平台管理员在「基础配置 → 分享配置」重新填写并保存应用标识和密钥');
        }
        return [
            $fields['key'] => $value[$fields['key']],
            $fields['secret'] => $secret,
            // 缓存驱动由部署配置控制，不允许租户传入连接名。
            'cache_store' => (string)config($platform . '.cache_store', ''),
        ];
    }

    private static function decryptSecret(string $cipher): string
    {
        return str_starts_with($cipher, 'enc:v1:') ? WechatCredentialService::decrypt(substr($cipher, 7)) : '';
    }

    private static function publicConfig(array $value, array $fields): array
    {
        $key = (string)($value[$fields['key']] ?? '');
        $secretSet = (string)($value[$fields['secret']] ?? '') !== '';
        return [

            $fields['key'] => $key,
            $fields['secret'] . '_set' => $secretSet,
            'configured' => $key !== '' && $secretSet,
        ];
    }

    private static function credential(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . '格式错误');
        }
        $value = trim($value);
        if (strlen($value) > $maxLength || preg_match('/[\x00-\x20\x7f*]/', $value)) {
            throw new InvalidArgumentException($label . '格式错误，请填写真实凭据');
        }
        return $value;
    }
}
