<?php

declare(strict_types=1);

namespace app\common\service\douyin;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\cache\Driver;
use think\facade\Cache;

/** 抖音 H5 投稿：生成 Schema，不代表用户已发布成功。 */
final class DouyinH5ShareService
{
    private string $clientKey;
    private string $clientSecret;
    private ClientInterface $http;
    private DouyinCredentialCache $credentials;

    public function __construct(?array $config = null, ?ClientInterface $http = null, ?Driver $cache = null)
    {
        $config ??= config('douyin', []);
        $this->clientKey = trim((string)($config['client_key'] ?? ''));
        $this->clientSecret = trim((string)($config['client_secret'] ?? ''));
        if ($this->clientKey === '' || $this->clientSecret === '') {
            throw new RuntimeException('请先配置抖音网站应用的 client_key 和 client_secret');
        }
        $this->http = $http ?? new Client();
        $store = trim((string)($config['cache_store'] ?? ''));
        $this->credentials = new DouyinCredentialCache(
            $cache ?? Cache::store($store === '' ? null : $store), $this->clientKey, $this->clientSecret
        );
    }

    /**
     * 业务层须先验证公开门店及素材可用性，再传入可信的分享内容。
     * @return array{schema:string, params:array, ticket_expires_at:int}
     */
    public function createShare(array $content): array
    {
        // 先验证内容，无效请求不消耗抖音接口配额。
        $params = DouyinSharePayload::normalize($content);
        $ticket = $this->openTicket();
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)time();
        $params = [
            'share_type' => 'h5',
            'client_key' => $this->clientKey,
            'nonce_str' => $nonce,
            'timestamp' => $timestamp,
            'signature' => self::signature($nonce, $ticket['value'], $timestamp),
        ] + $params;

        return [
            'schema' => 'snssdk1128://openplatform/share?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'params' => $params,
            // 这是提前刷新后的 ticket 缓存截止时间，不是抖音承诺的 Schema 有效期。
            'ticket_expires_at' => $ticket['expires_at'],
        ];
    }

    public static function signature(string $nonce, string $ticket, string $timestamp): string
    {
        if ($nonce === '' || $ticket === '' || !preg_match('/^[1-9][0-9]*$/D', $timestamp)) {
            throw new InvalidArgumentException('抖音签名参数不完整或时间戳无效');
        }
        // 签名原文不能 urlencode，ticket 中的 +、/、= 必须保持原样。
        return md5('nonce_str=' . $nonce . '&ticket=' . $ticket . '&timestamp=' . $timestamp);
    }

    private function openTicket(): array
    {
        $cached = $this->credentials->get('open_ticket');
        if ($cached !== null) {
            return $cached;
        }
        return $this->credentials->synchronized(function (): array {
            // 等待锁的请求必须再次检查缓存。
            $cached = $this->credentials->get('open_ticket');
            if ($cached !== null) {
                return $cached;
            }
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $token = $this->clientToken();
                $startedAt = time();
                try {
                    $data = $this->request('GET', '/open/getticket/', [
                        'headers' => ['access-token' => $token['value']],
                    ]);
                } catch (RuntimeException $error) {
                    if (!in_array($error->getCode(), [2190002, 2190008], true)) {
                        throw $error;
                    }
                    $this->credentials->delete('client_token');
                    if ($attempt === 1) {
                        throw $error;
                    }
                    continue;
                }
                $entry = $this->credential($data, 'ticket', $startedAt);
                $this->credentials->set('open_ticket', $entry);
                return $entry;
            }
            throw new RuntimeException('获取抖音 open_ticket 失败');
        });
    }

    private function clientToken(): array
    {
        $cached = $this->credentials->get('client_token');
        if ($cached !== null) {
            return $cached;
        }
        $startedAt = time();
        $data = $this->request('POST', '/oauth/client_token/', [
            'json' => [
                'client_key' => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credential',
            ],
        ]);
        $entry = $this->credential($data, 'access_token', $startedAt);
        $this->credentials->set('client_token', $entry);
        return $entry;
    }

    private function credential(array $data, string $field, int $startedAt): array
    {
        $expires = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($data[$field] ?? null) || trim($data[$field]) === '' || $expires === false || $expires <= 0) {
            throw new RuntimeException('抖音返回的凭据或有效期无效');
        }
        // 严格使用接口有效期，短 TTL 不得被最小缓存时长延长。
        $margin = min(120, (int)floor($expires / 10));
        return ['value' => $data[$field], 'expires_at' => $startedAt + $expires - $margin];
    }

    private function request(string $method, string $path, array $options): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Content-Type' => 'application/json'];
        $options += ['timeout' => 10, 'connect_timeout' => 4, 'http_errors' => false, 'allow_redirects' => false, 'verify' => true];
        try {
            $response = $this->http->request($method, 'https://open.douyin.com' . $path, $options);
        } catch (\Throwable) {
            // 不传播携带请求体、token 或 client_secret 的底层异常。
            throw new RuntimeException('抖音服务连接失败，请稍后重试');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('抖音服务 HTTP 异常（' . $response->getStatusCode() . '）');
        }
        try {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('抖音服务返回的数据格式无效');
        }
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if (!is_array($data) || !array_key_exists('error_code', $data)) {
            throw new RuntimeException('抖音服务返回的数据结构无效');
        }
        $code = filter_var($data['error_code'], FILTER_VALIDATE_INT);
        if ($code === false) {
            throw new RuntimeException('抖音服务返回的错误码无效');
        }
        if ($code !== 0) {
            // 保留错误码用于排查，不透传可能包含凭据的 description 或原始响应。
            throw new RuntimeException('抖音接口调用失败（错误码 ' . $code . '）', $code);
        }
        return $data;
    }
}
