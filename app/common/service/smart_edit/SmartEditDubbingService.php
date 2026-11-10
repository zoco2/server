<?php

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditDubbing;
use app\common\service\MediaDurationService;
use Exception;
use think\facade\Log;

/**
 * 智能剪辑 TTS 口播编排。
 *
 * 流程：每个音色 × 每条字幕 → 火山合成单句 → 上传到智能剪辑那套 OSS →
 * 本地测时长 → 按「句时长 + 句间间隔」排时间轴 → 合并成整段 MP3 → 落库。
 *
 * IMS 只能拉 OSS 上的在线资源，所以产物必须写进智能剪辑配置指向的 Bucket，
 * 不能落到租户通用存储里。
 *
 * 时间轴是字幕的唯一来源：字幕不自己填时间，全部取自这里。
 */
class SmartEditDubbingService
{
    /** 默认句间间隔（秒） */
    public const DEFAULT_GAP = 0.3;

    /** 单句最长等待轮询次数（2.0 非流式是一次性返回，这里只留少量重试） */
    private const MAX_SYNTHESIS_ATTEMPTS = 2;

    /** 口播产物在 OSS 上的目录前缀 */
    private const OUTPUT_PREFIX = 'dubbing';

    /**
     * 试听单个音色：合成一句短文本，返回可直接播放的地址。
     *
     * 只用于后台配置页确认 API Key / 音色 ID 是否可用，不落配音表。
     * 音频同样写进智能剪辑的 Bucket，返回可访问地址。
     *
     * @param int    $tenantId 租户 ID
     * @param string $voiceKey 音色 key
     * @param string $text     试听文本，为空时用默认短句
     * @param float|null $speed  语速倍数，null 用租户默认值
     * @param float|null $volume 音量倍数，null 按原声
     * @return array{url:string,duration:float}
     */
    public static function preview(
        int $tenantId,
        string $voiceKey,
        string $text = '',
        ?float $speed = null,
        ?float $volume = null
    ): array {
        $voice = SmartEditVoiceService::get($tenantId, $voiceKey);
        $text = trim($text);
        if ($text === '') {
            $text = SmartEditVoiceService::previewText();
        }
        // 试听没必要念长文，截断避免误点就产生大额计费
        $text = mb_substr($text, 0, 60);

        $config = SmartEditImsConfigService::require($tenantId);
        $voiceConfig = SmartEditVoiceConfigService::require($tenantId);
        $client = new VolcTtsClient(
            $voiceConfig['api_key'],
            $voiceConfig['endpoint'],
            $voiceConfig['resource_id']
        );

        $speed = self::clampSpeed($speed ?? (float)$voiceConfig['default_speed']);
        $volume = self::clampVolume($volume ?? 1.0);
        $audio = $client->synthesize($text, (string)$voice['provider_asset_id'], [
            'speed' => $speed,
            'volume' => $volume,
            'uid' => 'smart_edit_preview',
        ]);
        $duration = self::detectAudioDuration($audio);

        // 按「音色 + 参数」固定文件名：同参数反复试听只覆盖同一个对象，
        // 参数不同则换 key，避免浏览器命中上一组音量/语速的缓存音频。
        $key = SmartEditImsConfigService::generatedKey(
            $config,
            self::OUTPUT_PREFIX,
            'preview/' . self::previewFileStem($voiceKey, $speed, $volume) . '.mp3'
        );
        SmartEditImsConfigService::putObject($config, $key, $audio, 'audio/mpeg');

        return [
            'url' => SmartEditImsConfigService::objectUrl($config, $key),
            'duration' => round($duration, 2),
        ];
    }

    /**
     * 为一个镜头组生成全部口播。
     *
     * @param int   $tenantId 租户 ID
     * @param int   $userId   用户 ID
     * @param string $batchNo  组号
     * @param array $subtitles 字幕列表 [['text'=>...], ...]
     * @param array $voiceKeys 音色 key 列表
     * @param float $gap       句间间隔（秒）
     * @param float|null $speed  语速倍数，null 用租户默认值
     * @param float|null $volume 音量倍数，null 按原声
     * @return int 成功生成的口播套数
     * @throws Exception 任一音色失败时抛出，由调用方决定整组失败还是跳过
     */
    public static function generate(
        int $tenantId,
        int $userId,
        string $batchNo,
        array $subtitles,
        array $voiceKeys,
        float $gap = self::DEFAULT_GAP,
        ?float $speed = null,
        ?float $volume = null
    ): int {
        if ($batchNo === '') {
            throw new Exception('缺少组号，无法生成口播');
        }
        if ($voiceKeys === []) {
            return 0;
        }

        // 只保留有文案的字幕，空行不参与配音，也不占时间轴
        $lines = [];
        foreach ($subtitles as $item) {
            $text = trim((string)(is_array($item) ? ($item['text'] ?? '') : ''));
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        if ($lines === []) {
            return 0;
        }

        // 超过最大合成长度的单句先切分，避免整句被上游拒绝
        $lines = self::splitLongLines($lines);
        $gap = max(0.0, min(5.0, $gap));

        $config = SmartEditImsConfigService::require($tenantId);
        $voiceConfig = SmartEditVoiceConfigService::require($tenantId);
        $client = new VolcTtsClient(
            $voiceConfig['api_key'],
            $voiceConfig['endpoint'],
            $voiceConfig['resource_id']
        );

        // 语速优先用本次提交的值，缺省回落租户配置；音量缺省按原声
        $effectiveSpeed = self::clampSpeed($speed ?? (float)$voiceConfig['default_speed']);
        $effectiveVolume = self::clampVolume($volume ?? 1.0);

        $done = 0;
        foreach (array_values($voiceKeys) as $voiceIndex => $voiceKey) {
            $voice = SmartEditVoiceService::get($tenantId, $voiceKey);
            $row = self::findOrCreateRow($tenantId, $userId, $batchNo, $voiceIndex, $voice);
            // 已成功的不重复合成，避免重跑整组时重复计费
            if ((string)$row['status'] === SmartEditDubbing::STATUS_SUCCESS && (string)$row['audio_uri'] !== '') {
                $done++;
                continue;
            }

            $row->save([
                'status' => SmartEditDubbing::STATUS_PROCESSING,
                'error_message' => '',
                'update_time' => time(),
            ]);

            try {
                $result = self::synthesizeVoice(
                    $client,
                    $config,
                    $batchNo,
                    $voiceIndex,
                    (string)$voice['provider_asset_id'],
                    $lines,
                    $gap,
                    $effectiveSpeed,
                    $effectiveVolume
                );
                $row->save([
                    'audio_uri' => $result['audio_uri'],
                    'duration' => $result['duration'],
                    'lines_json' => $result['lines'],
                    'status' => SmartEditDubbing::STATUS_SUCCESS,
                    'error_message' => '',
                    'update_time' => time(),
                ]);
                $done++;
            } catch (\Throwable $e) {
                Log::channel('mns')->error(sprintf(
                    '[smart_edit] dubbing failed batch=%s voice=%s %s',
                    $batchNo,
                    $voiceKey,
                    $e->getMessage()
                ));
                $row->save([
                    'status' => SmartEditDubbing::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 480),
                    'update_time' => time(),
                ]);
                throw $e;
            }
        }

        return $done;
    }

    /**
     * 合成一个音色的整套口播。
     *
     * 逐句单独上传并各自保留时间点，不在这里做二进制拼接：
     * 句间间隔是靠 TimelineIn 排开的，拼成一条音频反而会在音频里凭空多出/缺少静音，
     * 导致字幕和声音对不上。最终由 Timeline 构建按 lines 排到音频轨上。
     *
     * @return array{audio_uri:string,duration:float,lines:array<int,array>}
     */
    private static function synthesizeVoice(
        VolcTtsClient $client,
        array $config,
        string $batchNo,
        int $voiceIndex,
        string $speaker,
        array $lines,
        float $gap,
        float $speed,
        float $volume
    ): array {
        $timeline = [];
        $cursor = 0.0;

        foreach ($lines as $lineIndex => $text) {
            $audio = self::synthesizeWithRetry($client, $text, $speaker, $speed, $volume);
            $seconds = self::detectAudioDuration($audio);
            if ($seconds <= 0) {
                throw new Exception('无法识别合成音频时长：' . mb_substr($text, 0, 20));
            }

            $key = SmartEditImsConfigService::generatedKey(
                $config,
                self::OUTPUT_PREFIX,
                $batchNo . '/' . $voiceIndex . '-' . $lineIndex . '.mp3'
            );
            SmartEditImsConfigService::putObject($config, $key, $audio, 'audio/mpeg');

            $timeline[] = [
                'text' => $text,
                'audio_uri' => $key,
                'duration' => round($seconds, 4),
                'timeline_in' => round($cursor, 4),
                'timeline_out' => round($cursor + $seconds, 4),
            ];
            $cursor += $seconds;
            // 最后一句后面不加间隔，否则成片末尾会多一段静音
            if ($lineIndex < count($lines) - 1) {
                $cursor += $gap;
            }
        }

        if ($timeline === []) {
            throw new Exception('没有可合成的配音文案');
        }

        // 总时长按时间轴末句结束点算：中间含间隔，末尾不含
        $total = (float)$timeline[count($timeline) - 1]['timeline_out'];

        return [
            'audio_uri' => (string)$timeline[0]['audio_uri'],
            'duration' => round($total, 4),
            'lines' => $timeline,
        ];
    }

    /**
     * 合成单句，失败重试一次。
     */
    private static function synthesizeWithRetry(
        VolcTtsClient $client,
        string $text,
        string $speaker,
        float $speed,
        float $volume
    ): string {
        $lastError = null;
        for ($attempt = 0; $attempt < self::MAX_SYNTHESIS_ATTEMPTS; $attempt++) {
            try {
                return $client->synthesize($text, $speaker, [
                    'speed' => $speed,
                    'volume' => $volume,
                ]);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }
        throw new Exception('配音合成失败：' . ($lastError ? $lastError->getMessage() : '未知错误'));
    }

    /**
     * 语速倍数收口到 0.5 ~ 2，和上游 speech_rate 的 [-50, 100] 对齐。
     */
    private static function clampSpeed(float $speed): float
    {
        return max(0.5, min(2.0, $speed));
    }

    /**
     * 音量倍数收口到 0.5 ~ 3：上游 loudness_rate 最低 -50（0.5 倍），无法真正静音。
     */
    private static function clampVolume(float $volume): float
    {
        return max(0.5, min(3.0, $volume));
    }

    /**
     * 试听对象文件名：音色 + 语速 + 音量，参数参与命名以区分浏览器缓存。
     */
    private static function previewFileStem(string $voiceKey, float $speed, float $volume): string
    {
        $stem = preg_replace('/[^a-zA-Z0-9_-]/', '', $voiceKey);
        return sprintf(
            '%s-%s-%s',
            $stem,
            str_replace('.', '_', (string)$speed),
            str_replace('.', '_', (string)$volume)
        );
    }

    /**
     * 把写进临时文件的音频交给 ffprobe 测时长。
     *
     * MP3 的帧头估算在 MediaDurationService 里已经实现，这里只需要给它一个临时文件。
     */
    private static function detectAudioDuration(string $binary): float
    {
        if ($binary === '') {
            return 0.0;
        }
        $path = tempnam(sys_get_temp_dir(), 'smart_edit_tts_');
        if ($path === false) {
            return 0.0;
        }
        $target = $path . '.mp3';
        @rename($path, $target);
        if (@file_put_contents($target, $binary) === false) {
            @unlink($target);
            return 0.0;
        }
        try {
            return MediaDurationService::detect($target);
        } finally {
            @unlink($target);
        }
    }

    /**
     * 顺序拼接多个 MP3 文件内容。
     *
     * 目前 Timeline 按句排布，不再走拼接；保留这段是为了后续做「整段口播」单文件输出
     * （例如允许用户下载配音）时复用，避免重复实现 ID3 处理。
     */
    private static function concatMp3(array $clips): string
    {
        if (count($clips) === 1) {
            return $clips[0];
        }
        $merged = '';
        foreach ($clips as $index => $clip) {
            // 除第一段外剥掉 ID3v2 头，避免中段出现多余的标签块
            if ($index > 0) {
                $clip = self::stripId3v2($clip);
            }
            $merged .= $clip;
        }
        return $merged;
    }

    /**
     * 去掉 MP3 开头的 ID3v2 标签。
     */
    private static function stripId3v2(string $content): string
    {
        if (strlen($content) < 10 || substr($content, 0, 3) !== 'ID3') {
            return $content;
        }
        // ID3v2 头 10 字节，之后 4 字节是 syncsafe 长度
        $size = ((ord($content[6]) & 0x7f) << 21)
            | ((ord($content[7]) & 0x7f) << 14)
            | ((ord($content[8]) & 0x7f) << 7)
            | (ord($content[9]) & 0x7f);
        return substr($content, 10 + $size);
    }

    /**
     * 超长单句切分。
     *
     * 上游对单次请求有长度限制，这里按标点切成不超过上限的片段，
     * 保证用户写长文案时不会整组失败。
     */
    private static function splitLongLines(array $lines, int $maxLength = 200): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (mb_strlen($line) <= $maxLength) {
                $result[] = $line;
                continue;
            }
            // 优先在句末标点处断开
            $buffer = '';
            $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($chars as $char) {
                $buffer .= $char;
                $isBreak = in_array($char, ['。', '！', '？', '；', '，', '.', '!', '?', ';', ','], true);
                if (mb_strlen($buffer) >= $maxLength || ($isBreak && mb_strlen($buffer) >= $maxLength / 2)) {
                    $result[] = trim($buffer);
                    $buffer = '';
                }
            }
            if (trim($buffer) !== '') {
                $result[] = trim($buffer);
            }
        }

        return array_values(array_filter($result, static fn($line) => $line !== ''));
    }

    /**
     * 取或建口播行；重跑时沿用同一行，靠唯一键避免重复。
     */
    private static function findOrCreateRow(
        int $tenantId,
        int $userId,
        string $batchNo,
        int $voiceIndex,
        array $voice
    ): SmartEditDubbing {
        $row = SmartEditDubbing::where([
            'batch_no' => $batchNo,
            'voice_index' => $voiceIndex,
        ])->findOrEmpty();

        if (!$row->isEmpty()) {
            return $row;
        }

        return SmartEditDubbing::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'batch_no' => $batchNo,
            'voice_index' => $voiceIndex,
            'voice_key' => (string)$voice['key'],
            'voice_name' => (string)$voice['label'],
            'provider_asset_id' => (string)$voice['provider_asset_id'],
            'audio_uri' => '',
            'duration' => 0,
            'lines_json' => [],
            'status' => SmartEditDubbing::STATUS_PENDING,
            'error_message' => '',
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    /**
     * 读某组已生成的口播，按音色顺序返回。
     *
     * @return array<int, array{voice_key:string,voice_name:string,audio_uri:string,duration:float,lines:array}>
     */
    public static function successes(int $tenantId, string $batchNo): array
    {
        if ($batchNo === '') {
            return [];
        }
        $rows = SmartEditDubbing::where([
            'tenant_id' => $tenantId,
            'batch_no' => $batchNo,
            'status' => SmartEditDubbing::STATUS_SUCCESS,
        ])->order('voice_index', 'asc')->select();

        $result = [];
        foreach ($rows as $row) {
            if ((string)$row['audio_uri'] === '') {
                continue;
            }
            $result[] = [
                'voice_key' => (string)$row['voice_key'],
                'voice_name' => (string)$row['voice_name'],
                'audio_uri' => (string)$row['audio_uri'],
                'duration' => (float)$row['duration'],
                'lines' => (array)($row['lines_json'] ?: []),
            ];
        }
        return $result;
    }
}
