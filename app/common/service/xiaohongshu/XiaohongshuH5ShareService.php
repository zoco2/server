<?php

declare(strict_types=1);

namespace app\common\service\xiaohongshu;

use app\common\service\share\PlatformShareConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\cache\Driver;
use think\facade\Cache;

/** 为 xhs.share() 准备媒体及服务端鉴权参数，不表示笔记发布成功。 */
final class XiaohongshuH5ShareService
{
    public const SDK_URL = 'https://fe-static.xhscdn.com/biz-static/goten/xhs-1.0.1.js';
    private const TOKEN_URL = 'https://edith.xiaohongshu.com/api/sns/v1/ext/access/token';

    private string $appKey;
    private string $appSecret;
    private ClientInterface $http;
    private XiaohongshuTokenCache $tokens;

    public function __construct(?array $config = null, ?ClientInterface $http = null, ?Driver $cache = null)
    {
        if ($config === null) {
            $config = (int)(request()->tenantId ?? 0) > 0
                ? PlatformShareConfigService::runtime('xiaohongshu')
                : config('xiaohongshu', []);
        }
        $this->appKey = trim((string)($config['app_key'] ?? ''));
        $this->appSecret = trim((string)($config['app_secret'] ?? ''));
        if ($this->appKey === '' || $this->appSecret === '') {
            throw new RuntimeException('请先配置小红书分享应用的 app_key 和 app_secret');
        }
        $this->http = $http ?? new Client();
        $store = trim((string)($config['cache_store'] ?? ''));
        $this->tokens = new XiaohongshuTokenCache(
            $cache ?? Cache::store($store === '' ? null : $store), $this->appKey, $this->appSecret
        );
    }

    public static function forTenant(int $tenantId, ?ClientInterface $http = null, ?Driver $cache = null): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('缺少有效的租户信息');
        }
        return new self(PlatformShareConfigService::runtime('xiaohongshu'), $http, $cache);
    }

    /**
     * 业务层应先验证公开门店状态及素材归属；返回值可直接用于后续的 xhs.share()。
     * @return array{shareInfo:array, verifyConfig:array, sdk_url:string, token_expires_at_ms:int}
     */
    public function createShare(array $content): array
    {
        $shareInfo = XiaohongshuSharePayload::normalize($content);
        $token = $this->accessToken();
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)XiaohongshuTokenCache::nowMs();
        return [
            'shareInfo' => $shareInfo,
            'verifyConfig' => [
                'appKey' => $this->appKey,
                'nonce' => $nonce,
                'timestamp' => $timestamp,
                'signature' => self::signature($this->appKey, $nonce, $timestamp, $token['access_token']),
            ],
            'sdk_url' => self::SDK_URL,
            // token 的真实到期时间，不是 JS 签名的有效期。前端应在点击时重新获取签名。
            'token_expires_at_ms' => $token['expires_at_ms'],
        ];
    }

    public static function signature(string $appKey, string $nonce, string $timestamp, string $secret): string
    {
        if ($appKey === '' || $secret === '' || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $nonce)
            || !preg_match('/^[1-9][0-9]{12}$/D', $timestamp)) {
            throw new InvalidArgumentException('小红书签名需要应用标识、密钥、32位以内随机字符串及毫秒时间戳');
        }
        // 官方 Java 示例：参数按 ASCII 排序，原值拼接，密钥直接追加到末尾。
        // 特别注意 timeStamp 大写 S，密钥前没有 &，不能进行 URL 编码。
        return hash('sha256', 'appKey=' . $appKey . '&nonce=' . $nonce . '&timeStamp=' . $timestamp . $secret);
    }

    private function accessToken(): array
    {
        $cached = $this->tokens->get();
        if ($cached !== null) {
            return $cached;
        }
        return $this->tokens->synchronized(function (): array {
            $cached = $this->tokens->get();
            if ($cached !== null) {
                return $cached;
            }
            $timestamp = XiaohongshuTokenCache::nowMs();
            $nonce = bin2hex(random_bytes(16));
            $requestedExpiry = $timestamp + 86400000;
            $data = $this->requestToken([
                'app_key' => $this->appKey,
                'nonce' => $nonce,
                'timestamp' => $timestamp,
                'signature' => self::signature($this->appKey, $nonce, (string)$timestamp, $this->appSecret),
                'expires_in' => $requestedExpiry,
            ]);
            $expiresAt = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT);
            $now = XiaohongshuTokenCache::nowMs();
            if (!is_string($data['access_token'] ?? null) || trim($data['access_token']) === ''
                || $expiresAt === false || $expiresAt <= $now || $expiresAt > $requestedExpiry) {
                throw new RuntimeException('小红书返回的凭据或毫秒截止时间无效');
            }
            $entry = [
                'access_token' => $data['access_token'],
                'expires_at_ms' => $expiresAt,
                'refresh_at_ms' => $expiresAt - min(120000, (int)floor(($expiresAt - $now) / 10)),
            ];
            $this->tokens->set($entry);
            return $entry;
        });
    }

    private function requestToken(array $payload): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, [
                'headers' => ['Content-Type' => 'application/json'], 'json' => $payload,
                'timeout' => 10, 'connect_timeout' => 4, 'http_errors' => false,
                'allow_redirects' => false, 'verify' => true,
            ]);
        } catch (\Throwable) {
            // 不传播包含请求签名、凭据或响应正文的底层异常。
            throw new RuntimeException('小红书服务连接失败，请稍后重试');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('小红书服务 HTTP 异常（' . $response->getStatusCode() . '）');
        }
        try {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('小红书服务返回的数据格式无效');
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new RuntimeException('小红书服务返回的数据结构无效');
        }
        if (array_key_exists('code', $body)) {
            $code = filter_var($body['code'], FILTER_VALIDATE_INT);
            if ($code === false) {
                throw new RuntimeException('小红书服务返回的错误码无效');
            }
            if ($code !== 0) {
                throw new RuntimeException('小红书接口调用失败（错误码 ' . $code . '）', $code);
            }
        }
        if (array_key_exists('success', $body) && $body['success'] !== true) {
            throw new RuntimeException('小红书接口未返回成功结果');
        }
        // 文档按字段描述 token 响应；兼容直接字段及网关 data 包装，失败状态不能被绕过。
        $data = array_key_exists('data', $body) ? $body['data'] : $body;
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('小红书服务返回的数据结构无效');
        }
        return $data;
    }
}
