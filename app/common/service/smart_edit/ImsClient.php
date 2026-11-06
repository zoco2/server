<?php

namespace app\common\service\smart_edit;

use Exception;

/**
 * 阿里云 ICE（智能媒体服务云剪辑）OpenAPI 客户端。
 *
 * 走 OpenAPI RPC 签名（ACS3-HMAC-SHA256），不额外引入 SDK：
 * 只需 access_key / secret_key / region 即可调用 SubmitMediaProducingJob、GetMediaProducingJob。
 */
class ImsClient
{
    private const ENDPOINT_TEMPLATE = 'https://ice.%s.aliyuncs.com/';
    private const API_VERSION = '2020-11-09';

    private string $accessKey;
    private string $secretKey;
    private string $region;

    public function __construct(string $accessKey, string $secretKey, string $region)
    {
        if ($accessKey === '' || $secretKey === '') {
            throw new Exception('阿里云 AccessKey 未配置，请到「智能剪辑 → 阿里云配置」填写');
        }
        if ($region === '') {
            throw new Exception('阿里云地域未配置，请到「智能剪辑 → 阿里云配置」填写');
        }
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->region = $region;
    }

    /**
     * 提交剪辑合成作业。
     *
     * @param array  $timeline     Timeline 结构（VideoTracks/AudioTracks/SubtitleTracks…）
     * @param string $outputUrl    输出成品的 OSS 公网地址
     * @param int    $width        成片宽度；0 表示不指定，由 IMS 按素材最大宽高决定
     * @param int    $height       成片高度；0 表示不指定
     * @param string $callbackUrl  可选回调地址
     * @return string JobId
     */
    public function submit(array $timeline, string $outputUrl, int $width = 0, int $height = 0, string $callbackUrl = ''): string
    {
        $outputConfig = ['MediaURL' => $outputUrl];
        // 宽高必须成对给出：只给一边时 IMS 的行为不可预期，这里直接忽略。
        if ($width > 0 && $height > 0) {
            $outputConfig['Width'] = $width;
            $outputConfig['Height'] = $height;
        }
        $params = [
            'Timeline' => json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'OutputMediaTarget' => 'oss-object',
            'OutputMediaConfig' => json_encode($outputConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($callbackUrl !== '') {
            $params['UserData'] = json_encode(['NotifyAddress' => $callbackUrl], JSON_UNESCAPED_UNICODE);
        }
        $data = $this->request('POST', 'SubmitMediaProducingJob', $params);
        $jobId = (string)($data['JobId'] ?? '');
        if ($jobId === '') {
            throw new Exception('阿里云未返回剪辑任务 ID');
        }
        return $jobId;
    }

    /**
     * 查询剪辑作业状态。
     *
     * @return array{status:string,code:string,message:string,media_url:string,duration:float}
     */
    public function getJob(string $jobId): array
    {
        $data = $this->request('GET', 'GetMediaProducingJob', ['JobId' => $jobId]);
        $job = (array)($data['MediaProducingJob'] ?? []);
        return [
            'status' => (string)($job['Status'] ?? ''),
            'code' => (string)($job['Code'] ?? ''),
            'message' => (string)($job['Message'] ?? ''),
            'media_url' => (string)($job['MediaURL'] ?? ''),
            'duration' => (float)($job['Duration'] ?? 0),
        ];
    }

    /**
     * ACS3-HMAC-SHA256 签名调用。
     *
     * @throws Exception 带可读错误信息
     */
    private function request(string $method, string $action, array $params): array
    {
        $endpoint = sprintf(self::ENDPOINT_TEMPLATE, $this->region);
        // ACS3 要求 canonical URI 与 canonical query 分开参与签名：
        // GET 的参数放 query（按 key 字典序升序编码），POST 的参数放 body。
        $path = '/';
        $query = $method === 'GET' ? self::canonicalQuery($params) : '';
        $body = $method === 'POST' ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
        $url = rtrim($endpoint, '/') . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        $headers = [
            'host' => parse_url($endpoint, PHP_URL_HOST),
            'x-acs-action' => $action,
            'x-acs-version' => self::API_VERSION,
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => bin2hex(random_bytes(16)),
            'x-acs-content-sha256' => hash('sha256', $body),
        ];
        if ($method === 'POST') {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        $headers['authorization'] = $this->sign($method, $path, $query, $headers, $body);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception('调用阿里云云剪辑失败（网络）：' . $error);
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new Exception('阿里云云剪辑返回异常（HTTP ' . $httpCode . '）');
        }
        if ($httpCode >= 400 || isset($data['Code'])) {
            $code = (string)($data['Code'] ?? '');
            $message = (string)($data['Message'] ?? '');
            throw new ImsApiException($message !== '' ? $message : '阿里云云剪辑调用失败', $code, $httpCode, $data);
        }
        return $data;
    }

    /**
     * ACS3 canonical query string：按 key 字典序升序，key/value 均做 RFC3986 编码。
     */
    private static function canonicalQuery(array $params): string
    {
        if ($params === []) {
            return '';
        }
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        return implode('&', $pairs);
    }

    private function sign(string $method, string $path, string $query, array $headers, string $body): string
    {
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            $canonicalHeaders .= $name . ':' . trim((string)$value) . "\n";
            $signedHeaders[] = $name;
        }
        $hashedBody = hash('sha256', $body);
        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $query,                  // canonical query：GET 参数，POST 为空
            $canonicalHeaders,
            implode(';', $signedHeaders),
            $hashedBody,
        ]);
        $stringToSign = "ACS3-HMAC-SHA256\n" . hash('sha256', $canonicalRequest);
        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $this->secretKey, true));

        return sprintf(
            'ACS3-HMAC-SHA256 Credential=%s,SignedHeaders=%s,Signature=%s',
            $this->accessKey,
            implode(';', $signedHeaders),
            $signature
        );
    }
}

class ImsApiException extends Exception
{
    public string $errorCode;
    public int $httpStatus;
    public array $response;

    public function __construct(string $message, string $errorCode = '', int $httpStatus = 0, array $response = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->response = $response;
    }
}