<?php

namespace app\common\service\wenan;

use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

/**
 * 企业知识库 / 智能剪辑文案的美化、优化、强化与帮写。
 * 走 aigc_llm 文本模型，同步一次返回，不做会话。
 */
class WenanEnhanceService
{
    public const LLM_APP_CODE = 'aigc_llm';

    public const SOURCE_APP_CODE = 'wenan_enhance';

    /** @var array<string, string> */
    private const MODES = [
        'beautify' => '美化',
        'optimize' => '优化',
        'enhance' => '强化',
        'write' => '帮写',
    ];

    /** @var array<string, array{label:string,max:int,hint:string}> */
    private const SCENES = [
        'knowledge_title' => [
            'label' => '企业知识库标题',
            'max' => 120,
            'hint' => '短标题，信息完整、便于检索，不堆砌口号。',
        ],
        'knowledge_summary' => [
            'label' => '企业知识库摘要',
            'max' => 500,
            'hint' => '概括要点，方便检索与展示，不写成正文。',
        ],
        'knowledge_body' => [
            'label' => '企业知识库正文',
            'max' => 6000,
            'hint' => '结构清晰、事实准确、可直接入库；保留关键实体与数据。',
        ],
        'smart_edit_title' => [
            'label' => '智能剪辑标题',
            'max' => 30,
            'hint' => '短促有力，适合成片叠字，避免标点堆砌与空泛形容词。',
        ],
        'smart_edit_subtitle' => [
            'label' => '智能剪辑字幕/配音文案',
            'max' => 200,
            'hint' => '口语顺畅、适合念白，一句一个意思，便于配音断句。',
        ],
        'general' => [
            'label' => '通用文案',
            'max' => 2000,
            'hint' => '表达清楚、语气专业，可直接用于业务场景。',
        ],
    ];

    /**
     * 美化文案。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function beautify(int $tenantId, int $userId, array $params): array
    {
        $params['mode'] = 'beautify';
        return self::rewrite($tenantId, $userId, $params);
    }

    /**
     * 优化文案。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function optimize(int $tenantId, int $userId, array $params): array
    {
        $params['mode'] = 'optimize';
        return self::rewrite($tenantId, $userId, $params);
    }

    /**
     * 强化文案。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function enhance(int $tenantId, int $userId, array $params): array
    {
        $params['mode'] = 'enhance';
        return self::rewrite($tenantId, $userId, $params);
    }

    /**
     * 根据提示帮写文案。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function write(int $tenantId, int $userId, array $params): array
    {
        $params['mode'] = 'write';
        return self::rewrite($tenantId, $userId, $params);
    }

    /**
     * 统一改写入口。
     *
     * @param array<string, mixed> $params text/prompt、mode、scene、brand_id、max_length、tone、hint
     * @return array{text:string,prompt:string,mode:string,scene:string,charge_points:mixed,model_code:string,model_name:string}
     */
    public static function rewrite(int $tenantId, int $userId, array $params): array
    {
        if (AppAccessService::assertTenantAvailable($tenantId, self::LLM_APP_CODE) !== null) {
            throw new Exception('对话应用未开通，暂无法处理文案');
        }

        $mode = self::normalizeMode((string)($params['mode'] ?? 'optimize'));
        $scene = self::normalizeScene((string)($params['scene'] ?? 'general'));
        $sceneMeta = self::SCENES[$scene];
        $maxLength = self::resolveMaxLength($params, $sceneMeta['max']);
        $tone = mb_substr(trim((string)($params['tone'] ?? '')), 0, 64);
        $hint = trim((string)($params['hint'] ?? ''));
        $text = trim((string)($params['text'] ?? $params['prompt'] ?? $params['content'] ?? ''));

        if ($mode === 'write') {
            if ($text === '' && $hint === '') {
                throw new Exception('请输入创作提示或草稿');
            }
        } elseif ($text === '') {
            throw new Exception('请输入需要处理的文案');
        }

        if ($text !== '' && mb_strlen($text) > 8000) {
            throw new Exception('原文过长，请控制在 8000 字以内');
        }

        $brandContext = self::resolveBrandContext($params);
        $result = AigcLlmService::generateText($tenantId, $userId, [
            'system_prompt' => self::systemPrompt($mode, $scene, $sceneMeta, $maxLength, $tone),
            'content' => self::userContent($mode, $scene, $sceneMeta['label'], $text, $hint, $brandContext, $maxLength),
            'source_app_code' => self::SOURCE_APP_CODE,
            'source_type' => 'wenan_helper',
            'request_timeout_seconds' => 90,
            // 文案改写只要最终正文；思考链泄漏会污染字幕/标题输入框
            'enable_thinking' => false,
            '_require_final_content' => true,
            'max_tokens' => self::resolveMaxTokens($scene, $maxLength),
        ]);

        $output = self::validateResult(
            (string)($result['content'] ?? ''),
            $maxLength,
            $mode,
            $text,
            $scene
        );

        return [
            'text' => $output,
            'prompt' => $output,
            'mode' => $mode,
            'scene' => $scene,
            'charge_points' => $result['billing']['user_charge_points']
                ?? $result['charge_points']
                ?? 0,
            'model_code' => (string)($result['model_code'] ?? ''),
            'model_name' => (string)($result['model_name'] ?? $result['model_code'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    public static function modeOptions(): array
    {
        $items = [];
        foreach (self::MODES as $value => $label) {
            $items[] = ['value' => $value, 'label' => $label];
        }
        return $items;
    }

    /**
     * @return array<int, array{value:string,label:string,max_length:int}>
     */
    public static function sceneOptions(): array
    {
        $items = [];
        foreach (self::SCENES as $value => $meta) {
            $items[] = [
                'value' => $value,
                'label' => $meta['label'],
                'max_length' => $meta['max'],
            ];
        }
        return $items;
    }

    private static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $aliases = [
            'beautify' => 'beautify',
            '美化' => 'beautify',
            'polish' => 'beautify',
            'optimize' => 'optimize',
            '优化' => 'optimize',
            'enhance' => 'enhance',
            '强化' => 'enhance',
            'strengthen' => 'enhance',
            'write' => 'write',
            '帮写' => 'write',
            'generate' => 'write',
        ];
        if (!isset($aliases[$mode])) {
            throw new Exception('不支持的处理模式');
        }
        return $aliases[$mode];
    }

    private static function normalizeScene(string $scene): string
    {
        $scene = strtolower(trim($scene));
        if ($scene === '' || $scene === 'default') {
            return 'general';
        }
        if (!isset(self::SCENES[$scene])) {
            throw new Exception('不支持的文案场景');
        }
        return $scene;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function resolveMaxLength(array $params, int $sceneMax): int
    {
        $max = (int)($params['max_length'] ?? $params['maxLength'] ?? 0);
        if ($max <= 0) {
            return $sceneMax;
        }
        return max(8, min($sceneMax, $max));
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function resolveBrandContext(array $params): string
    {
        $brandName = trim((string)($params['brand_name'] ?? ''));
        if ($brandName === '') {
            return '';
        }
        return '品牌：' . mb_substr($brandName, 0, 64);
    }

    /**
     * 按场景限制生成长度，避免模型把思考过程写很长。
     */
    private static function resolveMaxTokens(string $scene, int $maxLength): int
    {
        $byScene = match ($scene) {
            'smart_edit_title' => 64,
            'smart_edit_subtitle' => 256,
            'knowledge_title' => 160,
            'knowledge_summary' => 640,
            default => 0,
        };
        if ($byScene > 0) {
            return $byScene;
        }
        return max(128, min(2048, (int)ceil($maxLength * 1.6) + 32));
    }

    /**
     * @param array{label:string,max:int,hint:string} $sceneMeta
     */
    private static function systemPrompt(
        string $mode,
        string $scene,
        array $sceneMeta,
        int $maxLength,
        string $tone
    ): string {
        $modeGoal = match ($mode) {
            'beautify' => '润色语句，更顺、更专业，不改原意。',
            'optimize' => '改得更清楚好用，删空话，不编造事实。',
            'enhance' => '强化记忆点和行动感，不夸张承诺。',
            default => '按提示写出可直接使用的文案。',
        };
        $toneLine = $tone !== '' ? "语气：{$tone}。" : '语气专业克制。';
        $shortRule = in_array($scene, ['smart_edit_title', 'smart_edit_subtitle'], true)
            ? '原文很短时输出也要短，禁止扩写成说明、分析或自我对话。'
            : '不要把任务说明写进结果。';

        return <<<PROMPT
你只做「{$sceneMeta['label']}」的文案改写/生成。
目标：{$modeGoal}
要求：{$sceneMeta['hint']}
字数：最多 {$maxLength} 字（含标点）。
{$toneLine}
{$shortRule}
输出铁律：
- 只输出最终文案本身，一个字符都不能多。
- 禁止输出思考过程、分析、自我解释、任务复述、Markdown、标题、引号包裹、前后缀。
- 禁止出现「用户」「我需要」「首先」「原文」「任务」等元叙述。
- 保留品牌名、产品名、数据；不虚构事实。
- 输出语言与原文一致。
PROMPT;
    }

    private static function userContent(
        string $mode,
        string $scene,
        string $sceneLabel,
        string $text,
        string $hint,
        string $brandContext,
        int $maxLength
    ): string {
        $parts = [];
        if ($brandContext !== '') {
            $parts[] = $brandContext;
        }
        if ($hint !== '') {
            $parts[] = '补充：' . mb_substr($hint, 0, 500);
        }
        if ($mode === 'write') {
            $parts[] = $text !== ''
                ? "请写一条「{$sceneLabel}」，直接给正文，最多{$maxLength}字。提示：\n{$text}"
                : "请写一条「{$sceneLabel}」，直接给正文，最多{$maxLength}字。";
            return implode("\n", $parts);
        }

        // 字幕/标题：用户消息尽量短，减少诱发长篇分析
        if (in_array($scene, ['smart_edit_title', 'smart_edit_subtitle'], true)) {
            $parts[] = '原文：' . $text;
            $parts[] = '请' . self::MODES[$mode] . "后只返回文案，最多{$maxLength}字。";
            return implode("\n", $parts);
        }

        $parts[] = "场景：{$sceneLabel}（最多{$maxLength}字）";
        $parts[] = '原文：' . $text;
        $parts[] = '请' . self::MODES[$mode] . '后只返回文案正文。';
        return implode("\n", $parts);
    }

    private static function validateResult(
        string $result,
        int $maxLength,
        string $mode,
        string $source,
        string $scene
    ): string {
        $result = trim($result);
        $result = preg_replace('/^```[\w]*\s*/u', '', $result) ?? $result;
        $result = preg_replace('/\s*```$/u', '', $result) ?? $result;
        $result = trim($result, " \t\n\r\0\x0B\"'“”‘’");

        // 去掉常见思考标签
        $result = preg_replace('/<\/?think>/iu', '', $result) ?? $result;
        $result = preg_replace('/<\/?reasoning>/iu', '', $result) ?? $result;
        $result = trim($result);

        if ($result === '') {
            throw new Exception('生成结果为空，请稍后重试，原文已保留');
        }

        if (self::looksLikeMetaLeak($result, $source)) {
            $extracted = self::extractPlainCandidate($result, $maxLength, $source);
            if ($extracted === null) {
                throw new Exception('模型返回了无效内容，请重试，原文已保留');
            }
            $result = $extracted;
        }

        // 短视频字幕/标题：结果不应远长于原文太多（除非原文极短允许略扩）
        if (in_array($scene, ['smart_edit_title', 'smart_edit_subtitle'], true) && $source !== '') {
            $sourceLen = mb_strlen($source);
            $softCap = max($sourceLen * 3, $scene === 'smart_edit_title' ? 16 : 24);
            $softCap = min($maxLength, $softCap);
            if (mb_strlen($result) > $softCap && self::looksLikeMetaLeak($result, $source)) {
                throw new Exception('模型返回了无效内容，请重试，原文已保留');
            }
        }

        if (mb_strlen($result) > $maxLength) {
            $result = mb_substr($result, 0, $maxLength);
            $result = rtrim($result, " \t\n\r\0\x0B，,。.!！?？;；:：、");
        }
        if ($result === '') {
            throw new Exception(($mode === 'write' ? '帮写' : '处理') . '结果异常，请稍后重试');
        }
        return $result;
    }

    /**
     * 识别把任务说明/思考过程当正文返回的结果。
     */
    private static function looksLikeMetaLeak(string $result, string $source): bool
    {
        if (preg_match('/<(think|reasoning)>/iu', $result)) {
            return true;
        }
        $needles = [
            '用户让我', '用户要求', '我需要', '首先，我', '首先我',
            '任务描述', '场景约束', '字数上限', '原文是', '占位符',
            '根据任务', '仔细阅读', '硬性规则', '输出铁律',
            '嗯，用户', '好的，用户', '让我优化', '让我美化',
        ];
        foreach ($needles as $needle) {
            if (mb_strpos($result, $needle) !== false) {
                return true;
            }
        }
        // 多段分析腔：多句「首先/其次/然后」且明显长于原文
        if (
            $source !== ''
            && mb_strlen($result) > max(40, mb_strlen($source) * 4)
            && preg_match_all('/(首先|其次|然后|接下来|总之)/u', $result) >= 2
        ) {
            return true;
        }
        return false;
    }

    /**
     * 尝试从泄漏文本里抠最后一句可用文案；抠不到返回 null。
     */
    private static function extractPlainCandidate(string $result, int $maxLength, string $source): ?string
    {
        $lines = preg_split('/\R/u', $result) ?: [];
        $candidates = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = trim($line, " \t\n\r\0\x0B\"'“”‘’*-•");
            if ($line === '' || self::looksLikeMetaLeak($line, $source)) {
                continue;
            }
            if (mb_strlen($line) > $maxLength) {
                continue;
            }
            // 跳过明显的说明句
            if (preg_match('/^(原文|输出|结果|最终文案|改写后)[:：]/u', $line)) {
                $line = trim(preg_replace('/^(原文|输出|结果|最终文案|改写后)[:：]\s*/u', '', $line) ?? $line);
            }
            if ($line === '' || self::looksLikeMetaLeak($line, $source)) {
                continue;
            }
            $candidates[] = $line;
        }
        if ($candidates === []) {
            return null;
        }
        // 优先取与原文接近的短句；否则取最后一句非分析行
        foreach (array_reverse($candidates) as $line) {
            if ($source !== '' && (mb_strpos($line, $source) !== false || mb_strlen($line) <= max(8, mb_strlen($source) * 2))) {
                return $line;
            }
        }
        return $candidates[count($candidates) - 1];
    }
}
