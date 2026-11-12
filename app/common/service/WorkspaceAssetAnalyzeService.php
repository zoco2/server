<?php
namespace app\common\service;

use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\power\MarketTextModelRuntimeService;
use InvalidArgumentException;
use think\facade\Log;
use Throwable;

/**
 * 素材库图片 / 视频的视觉分析。
 *
 * 每个素材单独调用一次视觉文本模型：计费、失败归属和错误提示都能落到具体 asset_ref。
 * 文本通道只接受图片块，视频先用 ffmpeg 抽帧转图；抽帧不可用时退回视频封面。
 */
final class WorkspaceAssetAnalyzeService
{
    /** 一次请求最多分析的素材数：逐个调用模型，数量上限同时也是费用上限 */
    private const MAX_ASSETS = 4;
    private const MAX_FRAMES = 3;
    private const MAX_VIDEO_BYTES = 64 * 1024 * 1024;
    private const MAX_TOKENS = 1200;
    private const MAX_CAPTION_LENGTH = 300;
    private const MAX_OCR_LENGTH = 2000;
    private const MAX_TAGS = 10;
    private const MAX_TAG_LENGTH = 24;
    private const MAX_ISSUES = 6;
    /** 计费与审计用的应用编码，与业务表名一起写入 ai_app_task */
    private const APP_CODE = 'workspace_asset';
    private const ACTION_CODE = 'asset_analyze';
    private const BUSINESS_TABLE = 'workspace_asset_analyze';
    private const ASPECTS = ['caption', 'tags', 'ocr', 'usability'];
    private const DEFAULT_ASPECTS = ['caption', 'tags'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
你是素材库的视觉分析助手。只描述画面中真实可见的内容，不推测人物身份、品牌归属，也不补写画面里没有的文字。
严格按用户给出的 JSON 结构输出，JSON 之外不输出任何解释或标记。
PROMPT;

    /**
     * @param int $tenant
     * @param int $user
     * @param array $params ref 或 refs、可选 aspects、可选 prompt
     * @return array{items:array<int,array<string,mixed>>,aspects:array<int,string>}
     */
    public static function analyze(int $tenant, int $user, array $params): array
    {
        [$refs, $aspects] = self::normalize($params);
        $items = [];
        $failure = null;
        foreach ($refs as $ref) {
            try {
                $items[] = self::analyzeOne($tenant, $user, $ref, $aspects, (string)($params['prompt'] ?? ''));
            } catch (InvalidArgumentException $e) {
                // 批量分析时单个素材失败不连累其它素材，全部失败才整体报错。
                $failure ??= $e;
                $items[] = ['ref' => $ref, 'error' => $e->getMessage()];
            }
        }
        $succeeded = array_filter($items, static fn(array $item): bool => !isset($item['error']));
        if ($failure !== null && $succeeded === []) {
            throw $failure;
        }
        return ['items' => $items, 'aspects' => $aspects];
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private static function normalize(array $params): array
    {
        $refs = $params['refs'] ?? ($params['ref'] ?? []);
        if (is_string($refs)) {
            $refs = [$refs];
        }
        if (!is_array($refs)) {
            throw new InvalidArgumentException('请选择要分析的素材');
        }
        $clean = [];
        foreach ($refs as $ref) {
            $ref = is_string($ref) ? trim($ref) : '';
            if ($ref === '') {
                continue;
            }
            if (!preg_match('/^upload:result:[1-9][0-9]{0,10}$/D', $ref)) {
                throw new InvalidArgumentException('只支持分析素材库中上传的图片或视频');
            }
            $clean[$ref] = true;
        }
        $refs = array_keys($clean);
        if ($refs === []) {
            throw new InvalidArgumentException('请选择要分析的素材');
        }
        if (count($refs) > self::MAX_ASSETS) {
            throw new InvalidArgumentException('一次最多分析 ' . self::MAX_ASSETS . ' 个素材');
        }
        $aspects = $params['aspects'] ?? self::DEFAULT_ASPECTS;
        if (is_string($aspects)) {
            $aspects = [$aspects];
        }
        $aspects = is_array($aspects) ? array_intersect(self::ASPECTS, array_map('strval', $aspects)) : [];
        $aspects = array_values($aspects);
        if ($aspects === []) {
            throw new InvalidArgumentException('请选择分析维度');
        }
        return [$refs, $aspects];
    }

    /**
     * @param array<int,string> $aspects
     * @return array<string,mixed>
     */
    private static function analyzeOne(int $tenant, int $user, string $ref, array $aspects, string $prompt): array
    {
        $media = WorkspaceAssetService::media($tenant, $user, $ref);
        if (!in_array($media['kind'], ['image', 'video'], true)) {
            throw new InvalidArgumentException('只能分析图片或视频素材');
        }
        $images = self::images($media);
        if ($images === []) {
            throw new InvalidArgumentException('没有读取到可分析的画面');
        }
        try {
            $result = MarketTextModelRuntimeService::generate($tenant, $user, [
                'app_code' => self::APP_CODE,
                'source_app_code' => self::APP_CODE,
                'action_code' => self::ACTION_CODE,
                'business_table' => self::BUSINESS_TABLE,
                'business_id' => 0,
                'content' => self::instruction($media, $aspects, count($images), $prompt),
                'system_prompt' => self::SYSTEM_PROMPT,
                'reference_images' => $images,
                'max_tokens' => self::MAX_TOKENS,
                'request_timeout_seconds' => 120,
            ]);
        } catch (InvalidArgumentException $e) {
            // 算力不足、模型不可用等准入错误本身已是给用户看的文案。
            throw $e;
        } catch (Throwable $e) {
            Log::error('素材分析失败 ' . $ref . ' ' . get_class($e) . ' ' . $e->getMessage());
            // 「没有可用视觉模型」这类配置问题要让用户看到原文，否则只能盯着兜底文案猜。
            $message = $e->getMessage();
            if (str_contains($message, '视觉文本模型') || str_contains($message, '文本模型 API')) {
                throw new InvalidArgumentException($message);
            }
            throw new InvalidArgumentException('素材分析失败，请稍后重试');
        }
        $analysis = self::normalizeResult(self::decode($result), $aspects);
        $analysis['ref'] = $ref;
        $analysis['kind'] = $media['kind'];
        $analysis['image_count'] = count($images);
        $analysis['model'] = [
            'code' => (string)($result['model_code'] ?? ''),
            'name' => (string)($result['model_name'] ?? ''),
        ];
        $analysis['user_charge_points'] = (float)($result['billing']['user_charge_points'] ?? 0);
        // 分析结果直接写回素材表（la_tenant_file），素材库刷新即可带上标签。
        $persisted = ['caption' => $analysis['caption'] ?? '', 'tags' => $analysis['tags'] ?? []];
        if (isset($analysis['ocr'])) {
            $persisted['ocr'] = $analysis['ocr'];
        }
        if (isset($analysis['usability'])) {
            $persisted['usability'] = $analysis['usability'];
        }
        try {
            WorkspaceAssetService::saveUploadAnalysis($tenant, $user, (int)$media['id'], $persisted, (string)$analysis['model']['code']);
            $analysis['saved'] = true;
        } catch (Throwable $e) {
            // 模型调用已经计费完成，写库失败不能把结果丢掉，交给前端提示重存。
            Log::error('素材分析写库失败 ' . $ref . ' ' . get_class($e) . ' ' . $e->getMessage());
            $analysis['saved'] = false;
        }
        return $analysis;
    }

    /**
     * 图片直接用原图；视频优先按时间顺序抽帧，抽帧失败退回封面。
     *
     * @return array<int,string>
     */
    private static function images(array $media): array
    {
        if ($media['kind'] === 'image') {
            return [$media['url']];
        }
        $frames = self::frames((string)$media['url']);
        if ($frames !== []) {
            return $frames;
        }
        return $media['cover_url'] !== '' ? [(string)$media['cover_url']] : [];
    }

    /** @param array<int,string> $aspects */
    private static function instruction(array $media, array $aspects, int $imageCount, string $prompt): string
    {
        $lines = [];
        $lines[] = $media['kind'] === 'video'
            ? sprintf('这是一个视频素材，下列 %d 张图片是按时间顺序抽取的画面（第 1 张最早）。请把画面串起来理解内容与镜头变化。', $imageCount)
            : '这是一个图片素材，请分析这张图片。';
        if ($prompt !== '') {
            $lines[] = '额外要求：' . mb_substr(trim($prompt), 0, 300, 'UTF-8');
        }
        $lines[] = '只描述看得见的事实；无法确认的品牌、地点、人物身份不要猜测，看不清的字段留空。';
        $lines[] = '严格按下面的 JSON 结构输出，不要增减字段，JSON 之外不要输出任何文字：';
        $schema = [];
        $notes = [];
        if (in_array('caption', $aspects, true)) {
            $schema[] = '"caption": "..."';
            $notes[] = 'caption 是不超过 60 字的中文画面描述。';
        }
        if (in_array('tags', $aspects, true)) {
            $schema[] = '"tags": ["..."]';
            $notes[] = 'tags 给 3 到 8 个中文标签，名词优先，不要标点。';
        }
        if (in_array('ocr', $aspects, true)) {
            $schema[] = '"ocr": "..."';
            $notes[] = 'ocr 抄写画面中完整可见的文字，多行用 \n 分隔；没有文字就填空字符串。';
        }
        if (in_array('usability', $aspects, true)) {
            $schema[] = '"usability": {"score": 0, "issues": ["..."]}';
            $notes[] = 'usability.score 用 0 到 100 的整数表示作为设计素材的可用性，issues 列出模糊、水印、构图拥挤等问题，没有问题就给空数组。';
        }
        $lines[] = '{' . implode(', ', $schema) . '}';
        $lines[] = '字段说明：' . implode(' ', $notes);
        return implode("\n", $lines);
    }

    /**
     * 宽松解析模型输出：容忍 Markdown 围栏、JSON 之外的说明文字。
     *
     * @return array<string,mixed>
     */
    private static function decode(array $result): array
    {
        $text = trim((string)($result['content'] ?? ''));
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $text, $match)) {
            $text = trim($match[1]);
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($data)) {
            // 与 GEO 写稿不同，这里记录原始片段，否则格式问题永远无法定位。
            Log::warning('素材分析返回非 JSON：finish=' . (string)($result['finish_reason'] ?? '')
                . ' source=' . (string)($result['content_source'] ?? '')
                . ' length=' . mb_strlen($text, 'UTF-8')
                . ' head=' . mb_substr($text, 0, 500, 'UTF-8'));
            throw new InvalidArgumentException('模型没有返回可解析的分析结果，请重试');
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $aspects
     * @return array<string,mixed>
     */
    private static function normalizeResult(array $data, array $aspects): array
    {
        $output = [];
        if (in_array('caption', $aspects, true)) {
            $output['caption'] = self::text($data['caption'] ?? '', self::MAX_CAPTION_LENGTH);
        }
        if (in_array('tags', $aspects, true)) {
            $tags = [];
            foreach ((array)($data['tags'] ?? []) as $tag) {
                $tag = is_array($tag) ? ($tag['name'] ?? '') : $tag;
                $tag = trim((string)$tag);
                if ($tag === '' || in_array($tag, $tags, true)) {
                    continue;
                }
                $tags[] = self::text($tag, self::MAX_TAG_LENGTH);
                if (count($tags) >= self::MAX_TAGS) {
                    break;
                }
            }
            $output['tags'] = $tags;
        }
        if (in_array('ocr', $aspects, true)) {
            $output['ocr'] = self::text($data['ocr'] ?? '', self::MAX_OCR_LENGTH);
        }
        if (in_array('usability', $aspects, true)) {
            $usability = is_array($data['usability'] ?? null) ? $data['usability'] : [];
            $issues = [];
            foreach ((array)($usability['issues'] ?? []) as $issue) {
                $issue = trim((string)(is_array($issue) ? '' : $issue));
                if ($issue !== '') {
                    $issues[] = self::text($issue, 100);
                }
            }
            $output['usability'] = [
                'score' => max(0, min(100, (int)($usability['score'] ?? 0))),
                'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            ];
        }
        $filled = array_filter($output, static fn($value): bool => $value !== '' && $value !== []);
        if ($filled === []) {
            throw new InvalidArgumentException('模型没有返回所需的分析字段，请重试');
        }
        return $output;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $value = $value['text'] ?? ($value['content'] ?? '');
        }
        return mb_substr(trim((string)$value), 0, $limit, 'UTF-8');
    }

    /**
     * 抽帧失败一律返回空数组：上层退回封面，转码问题不该挡住视频分析。
     *
     * @return array<int,string>
     */
    private static function frames(string $url): array
    {
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg === '' || !function_exists('proc_open')) {
            return [];
        }
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'asset-analyze-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0775, true)) {
            return [];
        }
        $input = $dir . DIRECTORY_SEPARATOR . 'input';
        try {
            try {
                $body = OpenSafeHttp::get($url, self::MAX_VIDEO_BYTES, 90)['body'];
            } catch (Throwable $e) {
                Log::warning('素材分析读取视频失败 ' . $e->getMessage());
                return [];
            }
            if ($body === '' || @file_put_contents($input, $body) !== strlen($body)) {
                return [];
            }
            unset($body);
            $pattern = $dir . DIRECTORY_SEPARATOR . 'frame%02d.jpg';
            $command = [$ffmpeg, '-v', 'error', '-y', '-i', $input,
                '-vf', 'fps=1/2,scale=720:-2',
                '-frames:v', (string)self::MAX_FRAMES,
                '-q:v', '5', $pattern];
            if (!self::run($command, 45)) {
                return [];
            }
            $frames = [];
            for ($index = 1; $index <= self::MAX_FRAMES; $index++) {
                $file = sprintf($dir . DIRECTORY_SEPARATOR . 'frame%02d.jpg', $index);
                if (!is_file($file)) {
                    continue;
                }
                $bytes = (string)file_get_contents($file);
                if ($bytes !== '') {
                    $frames[] = 'data:image/jpeg;base64,' . base64_encode($bytes);
                }
            }
            return $frames;
        } finally {
            foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private static function run(array $command, int $timeout): bool
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        try {
            while (true) {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (microtime(true) - $start > $timeout) {
                    proc_terminate($process, 9);
                    return false;
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return $status['exitcode'] === 0;
                }
                usleep(50000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private static function ffmpeg(): string
    {
        foreach ([
            root_path() . 'runtime/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/www/server/ffmpeg/bin/ffmpeg',
        ] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }
}
