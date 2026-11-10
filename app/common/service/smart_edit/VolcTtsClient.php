<?php

namespace app\common\service\smart_edit;

use Exception;

/**
 * 火山引擎大模型语音合成 2.0 客户端。
 *
 * 走 V3 HTTP 非流式接口：POST /api/v3/tts/unidirectional。
 * 鉴权用 X-Api-Key；X-Api-Resource-Id 决定用 1.0 还是 2.0 的音色。
 *
 * 响应是 NDJSON（每行一个 JSON 对象），音频以 base64 分片放在 data 字段里，
 * 需要按行拼接所有分片才能得到完整音频。遇到 code !== 0 的分片视为错误。
 *
 * 不依赖任何第三方应用包，只依赖 curl。
 */
class VolcTtsClient
{
    /** 单次合成最长等待秒数 */
    private const TIMEOUT = 120;

    private string $apiKey;
    private string $endpoint;
    private string $resourceId;

    public function __construct(string $apiKey, string $endpoint, string $resourceId)
    {
        if ($apiKey === '') {
            throw new Exception('火山 API Key 未配置，请到「智能剪辑 → 火山语音配置」填写');
        }
        if ($endpoint === '') {
            throw new Exception('火山接口地址未配置，请到「智能剪辑 → 火山语音配置」填写');
        }
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
        $this->resourceId = $resourceId !== '' ? $resourceId : SmartEditVoiceConfigService::RESOURCE_ID_2_0;
    }

    /**
     * 合成一段文本，返回 MP3 二进制内容。
     *
     * @param string $text     待朗读文本
     * @param string $voiceId  音色 ID（设计稿里那些演员的 voice_type）
     * @param array  $options  speed / volume / pitch 等音频参数，非必需
     * @return string MP3 二进制
     * @throws Exception
     */
    public function synthesize(string $text, string $voiceId, array $options = []): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new Exception('配音文本不能为空');
        }
        if ($voiceId === '') {
            throw new Exception('请选择配音音色');
        }

        $audioParams = array_filter([
            'format' => 'mp3',
            'sample_rate' => 24000,
            // 语速 / 音量都是 [-50, 100] 的整数百分比，100 表示 2.0 倍、-50 表示 0.5 倍
            'speech_rate' => isset($options['speed']) && is_numeric($options['speed'])
                ? self::speedToRate((float)$options['speed'])
                : null,
            'loudness_rate' => isset($options['volume']) && is_numeric($options['volume'])
                ? self::volumeToRate((float)$options['volume'])
                : null,
        ], static fn($value) => $value !== null);

        $payload = [
            'user' => [
                'uid' => (string)($options['uid'] ?? 'smart_edit'),
            ],
            'req_params' => [
                'text' => $text,
                'speaker' => $voiceId,
                'audio_params' => $audioParams,
            ],
        ];

        $raw = $this->request($payload);
        return self::extractAudio($raw);
    }

    /**
     * 倍率转火山原生百分比。
     *
     * 1.0 倍对应 0，2.0 倍对应 100，0.5 倍对应 -50，取值区间 [-50, 100]。
     */
    public static function speedToRate(float $multiplier): int
    {
        if ($multiplier <= 0) {
            return 0;
        }
        $rate = (int)round(($multiplier - 1.0) * 100);
        return max(-50, min(100, $rate));
    }

    /**
     * 音量倍率转火山原生百分比。
     *
     * 与语速共用同一套换算，但上游 loudness_rate 最低只到 -50（0.5 倍），
     * 无法真正静音，所以先把倍率收口到 0.5 以上。
     */
    public static function volumeToRate(float $multiplier): int
    {
        return self::speedToRate(max(0.5, $multiplier));
    }

    /**
     * 逐行解析 NDJSON，把所有分片的 base64 音频拼成完整 MP3。
     *
     * 分片结构形如：
     *   {"code":0,"message":"","data":"<base64>"}
     *   {"code":20000000,"message":"OK","data":null}   // 结束标记
     */
    private static function extractAudio(string $raw): string
    {
        $audio = '';
        $sawChunk = false;
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $chunk = json_decode($line, true);
            if (!is_array($chunk)) {
                // 非 JSON 行直接跳过，避免个别心跳/空行把整段判失败
                continue;
            }
            $code = $chunk['code'] ?? 0;
            $message = (string)($chunk['message'] ?? '');
            // 20000000 是正常的结束标记，不算错误
            if ($code !== 0 && $code !== 20000000) {
                throw new Exception('火山语音合成失败：' . ($message !== '' ? $message : '错误码 ' . $code));
            }
            $data = $chunk['data'] ?? null;
            if (is_string($data) && $data !== '') {
                $decoded = base64_decode($data, true);
                if ($decoded !== false && $decoded !== '') {
                    $audio .= $decoded;
                    $sawChunk = true;
                }
            }
        }

        if (!$sawChunk || $audio === '') {
            throw new Exception('火山语音合成未返回音频数据');
        }
        return $audio;
    }

    /**
     * 发起 V3 合成请求，返回原始响应体。
     */
    private function request(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestId = bin2hex(random_bytes(16));

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $this->apiKey,
                'X-Api-Resource-Id: ' . $this->resourceId,
                // 同一请求的重试要靠它去重，避免重复计费
                'X-Api-Request-Id: ' . $requestId,
                'Accept: */*',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception('火山语音合成网络异常：' . $error);
        }
        if (!is_string($response) || $response === '') {
            throw new Exception('火山语音合成返回为空（HTTP ' . $httpCode . '）');
        }
        if ($httpCode >= 400) {
            throw new Exception('火山语音合成调用失败（HTTP ' . $httpCode . '）：' . mb_substr($response, 0, 200));
        }
        return $response;
    }
}
