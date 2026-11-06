<?php

namespace app\common\service\geo;

use Exception;

/**
 * GEO 诊断服务商开放接口 v1.11。
 */
class GeoDiagProvider
{
    public const BASE_URL = 'https://118.89.28.187/api/v1';

    /** @var string[] */
    public const TERMINAL_STATUS = ['completed', 'partial_failed', 'failed', 'cancelled'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function platforms(): array
    {
        $result = self::request('GET', '/platforms');
        return is_array($result['data']) ? $result['data'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function regions(): array
    {
        return self::request('GET', '/regions')['data'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usage(): array
    {
        return self::request('GET', '/usage')['data'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function createRun(array $payload, string $idempotencyKey): array
    {
        return self::request('POST', '/runs', $payload, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function runStatus(string $runId): array
    {
        return self::request('GET', '/runs/' . rawurlencode($runId) . '/status');
    }

    /**
     * @return array<string, mixed>
     */
    public static function runDetail(string $runId): array
    {
        return self::request('GET', '/runs/' . rawurlencode($runId));
    }

    /**
     * @return array<string, mixed>
     */
    public static function runReport(string $runId): array
    {
        return self::request('GET', '/runs/' . rawurlencode($runId) . '/report');
    }

    /**
     * @return array<string, mixed>
     */
    public static function batchStatus(string $batchId): array
    {
        return self::request('GET', '/batches/' . rawurlencode($batchId) . '/status');
    }

    /**
     * @return array<string, mixed>
     */
    public static function batchDetail(string $batchId, int $page = 1): array
    {
        $path = '/batches/' . rawurlencode($batchId) . '?page=' . max(1, $page);
        return self::request('GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     * @param string[] $extraHeaders
     * @return array{data:mixed,status:int,request_id:string,charged_cents:int,balance_cents:int}
     */
    private static function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $apiKey = self::apiKey();
        $headers = array_merge([
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ], $extraHeaders);
        $method = strtoupper($method);
        $url = rtrim((string)env('geo_diag.base_url', self::BASE_URL), '/') . $path;
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => filter_var(env('geo_diag.ssl_verify', false), FILTER_VALIDATE_BOOLEAN),
            CURLOPT_SSL_VERIFYHOST => filter_var(env('geo_diag.ssl_verify', false), FILTER_VALIDATE_BOOLEAN) ? 2 : 0,
        ];
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($errno) {
            throw new Exception($error !== '' ? $error : 'GEO诊断服务请求失败');
        }
        $headerText = substr((string)$raw, 0, $headerSize);
        $bodyText = substr((string)$raw, $headerSize);
        $headersMap = self::headers($headerText);
        $payload = json_decode($bodyText, true);
        if (!is_array($payload)) {
            throw new Exception('GEO诊断服务返回无法解析');
        }
        if ($status < 200 || $status >= 300) {
            $message = trim((string)($payload['error'] ?? '')) ?: 'GEO诊断服务调用失败';
            $code = trim((string)($payload['code'] ?? ''));
            $requestId = trim((string)($payload['requestId'] ?? ($headersMap['x-request-id'] ?? '')));
            throw new GeoDiagProviderException($message, $status, $code, $requestId, $payload, $headersMap);
        }
        return [
            'data' => $payload,
            'status' => $status,
            'request_id' => (string)($headersMap['x-request-id'] ?? ''),
            'charged_cents' => (int)($headersMap['x-billing-charged-cents'] ?? 0),
            'balance_cents' => (int)($headersMap['x-balance-cents'] ?? 0),
        ];
    }

    private static function apiKey(): string
    {
        $apiKey = trim((string)env('geo_diag.api_key', ''));
        if ($apiKey === '') {
            $apiKey = trim((string)getenv('GEO_DIAG_API_KEY'));
        }
        if ($apiKey === '') {
            throw new Exception('请先配置GEO诊断API Key');
        }
        return $apiKey;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $raw): array
    {
        $map = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if ($key !== '') {
                $map[$key] = $value;
            }
        }
        return $map;
    }
}

class GeoDiagProviderException extends Exception
{
    /** @var array<string, mixed> */
    private array $payload;
    /** @var array<string, string> */
    private array $headers;
    private string $providerCode;
    private string $requestId;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function __construct(string $message, int $status, string $providerCode, string $requestId, array $payload, array $headers)
    {
        parent::__construct($message, $status);
        $this->providerCode = $providerCode;
        $this->requestId = $requestId;
        $this->payload = $payload;
        $this->headers = $headers;
    }

    public function providerCode(): string
    {
        return $this->providerCode;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, string> */
    public function responseHeaders(): array
    {
        return $this->headers;
    }
}
