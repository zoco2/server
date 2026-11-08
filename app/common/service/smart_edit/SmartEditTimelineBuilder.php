<?php

namespace app\common\service\smart_edit;

use Exception;

/**
 * Timeline 构建。
 *
 * 输入是已经归一化、校验过的结构，这里只负责翻译成 IMS 的 Timeline：
 * - 视频轨：封面图片片段 + 正片视频片段，顺序相接
 * - 图片轨：贴纸，独占一条 VideoTrack 叠在视频轨之上
 * - 字幕轨：标题与前端传入的文字图层
 * - 音频轨：背景音乐
 *
 * 位置/尺寸/音量等取值一律原样透传，由前端控制；这里只决定落在哪条轨道。
 */
class SmartEditTimelineBuilder
{
    /** 封面在成片开头占用的固定时长（秒） */
    public const COVER_SECONDS = 0.5;

    /** 标题文案默认展示时长（秒） */
    public const TITLE_SECONDS = 3.0;

    /** 标题默认字号（像素） */
    public const TITLE_FONT_SIZE = 48;

    /** 标题默认颜色 */
    public const TITLE_FONT_COLOR = '#FFFFFF';

    /** 标题文字距成片顶部的距离，按成片高度占比计算 */
    private const TITLE_TOP_RATIO = 0.08;

    /** 成片高度未知时的标题顶部像素值（1080 高度下的近似 8%） */
    private const TITLE_TOP_FALLBACK_PX = 86;

    /**
     * 多视频合并，并按需叠加贴纸与背景音乐。
     *
     * @param array  $items        [['media_url'=>string,'in'=>float,'out'=>float], ...]
     * @param string $coverUrl     封面图片的公网地址；为空表示不加封面
     * @param array  $titles       标题组（多条配置）；空数组表示不加标题。兼容旧单对象。
     * @param int    $outputHeight 成片高度（像素），用于换算标题的纵向位置
     * @param array  $stickers     贴纸片段，元素见 stickerClip()
     * @param array  $audioClips   背景音乐片段，元素见 audioClip()
     */
    public static function buildMerge(
        array $items,
        string $coverUrl = '',
        array $titles = [],
        int $outputHeight = 0,
        array $stickers = [],
        array $audioClips = []
    ): array {
        $clips = [];
        foreach ($items as $item) {
            $url = (string)($item['media_url'] ?? '');
            if ($url === '') {
                throw new Exception('素材地址缺失');
            }
            // Width/Height=1 + AdaptMode=Cover：素材等比铺满成片画布（按 OutputMediaConfig 宽高），
            // 多出部分裁切。不设这两项时 IMS 默认 Fill 拉伸，选择 9:16/16:9/1:1 看起来像「比例没生效」。
            $clip = self::fitClipToCanvas(['MediaURL' => $url]);
            $in = (float)($item['in'] ?? 0);
            $out = (float)($item['out'] ?? 0);
            if ($in > 0) {
                $clip['In'] = round($in, 4);
            }
            if ($out > 0 && $out > $in) {
                $clip['Out'] = round($out, 4);
            }
            // 消除原声：该片段音量置 0，成片里只保留其它片段/背景音乐的声音
            if (!empty($item['mute'])) {
                $clip['Effects'] = [
                    ['Type' => 'Volume', 'Gain' => 0],
                ];
            }
            $clips[] = $clip;
        }
        if (count($clips) < 2) {
            throw new Exception('至少选择 2 段视频才能合并');
        }

        // 封面作为轨首图片片段：后续视频自然顺次后移，无需逐段计算时间轴。
        // Duration 让图片撑住 0.5s；TimelineIn/Out 显式给绝对区间，避免不同 IMS 版本对
        // 图片片段时长推导不一致。
        if ($coverUrl !== '') {
            array_unshift($clips, self::coverClip($coverUrl));
        }

        // 正片轨固定是第 0 条，贴纸与它分开，绝不复用这条轨。
        $timeline = [
            'VideoTracks' => [
                ['VideoTrackClips' => $clips],
            ],
        ];

        // 贴纸独占一条图片轨：VideoTracks 数组顺序即叠放顺序（先底后顶），
        // 放在正片轨之后就能压在画面之上，不需要逐帧计算。
        if ($stickers !== []) {
            $timeline['VideoTracks'][] = [
                'VideoTrackClips' => array_map(
                    static fn(array $sticker): array => self::stickerClip($sticker),
                    $stickers
                ),
            ];
        }

        // 标题组走独立文字轨：多条 clip 叠加，起止/字体/颜色/对齐都由前端决定。
        $titleClips = self::titleClips($titles, $outputHeight);
        if ($titleClips !== []) {
            $timeline['SubtitleTracks'] = [
                ['SubtitleTrackClips' => $titleClips],
            ];
        }

        // 背景音乐独占音频轨；Volume 由前端给出，这里不做业务假设。
        if ($audioClips !== []) {
            $timeline['AudioTracks'] = [
                ['AudioTrackClips' => array_map(
                    static fn(array $audio): array => self::audioClip($audio),
                    $audioClips
                )],
            ];
        }

        return $timeline;
    }

    /**
     * 把标题组（或旧单对象）转成字幕 clip 列表。
     *
     * @param array $titles       标题组 / 旧单对象
     * @param int   $outputHeight 成片高度
     * @return array<int, array<string, mixed>>
     */
    private static function titleClips(array $titles, int $outputHeight = 0): array
    {
        if ($titles === []) {
            return [];
        }
        // 旧协议：单对象 {text: ...}
        if (isset($titles['text']) && is_string($titles['text'])) {
            return [self::titleClip($titles, $outputHeight)];
        }
        $clips = [];
        foreach ($titles as $title) {
            if (!is_array($title) || trim((string)($title['text'] ?? '')) === '') {
                continue;
            }
            $clips[] = self::titleClip($title, $outputHeight);
        }
        return $clips;
    }

    /**
     * 标题文字片段。
     *
     * IMS 的坐标语义（见官方字幕示例）：
     * - X/Y 可传 0~0.9999 的比例，也可传 ≥8 的像素值，IMS 按数值区间自行识别。
     * - Alignment 决定 X/Y 指向文本框的哪个基准点。Center 是「上边界中点」，
     *   X=0 会把文本框左半边推到画面外，文案前几个字被裁掉；
     *   Left/Right 分别是左上角顶点、右上角顶点。
     *   横向对齐交给 Alignment，无需前端推算 X。
     *
     * 字体：Font 是系统字体名，FontURL 是自定义字体文件地址，两者同时给时以 FontURL 为准。
     *
     * @param array $title        归一化后的单条标题配置
     * @param int   $outputHeight 成片高度（像素）；0 表示未知，退回固定像素值
     */
    private static function titleClip(array $title, int $outputHeight = 0): array
    {
        $clip = [
            'Type' => 'Text',
            'Content' => (string)$title['text'],
            'TimelineIn' => round((float)($title['timeline_in'] ?? 0), 4),
            'TimelineOut' => round((float)($title['timeline_out'] ?? self::TITLE_SECONDS), 4),
            'X' => 0,
            // 纵向高度：前端给的是 0~1 的比例时按成片高度换算，否则视为像素值。
            'Y' => self::titleY($title['y'] ?? null, $outputHeight),
            'FontSize' => (int)($title['font_size'] ?? self::TITLE_FONT_SIZE),
            'FontColor' => (string)($title['font_color'] ?? self::TITLE_FONT_COLOR),
            'Alignment' => (string)($title['alignment'] ?? 'TopCenter'),
        ];
        $font = trim((string)($title['font'] ?? ''));
        if ($font !== '') {
            $clip['Font'] = $font;
        }
        $fontUrl = trim((string)($title['font_url'] ?? ''));
        if ($fontUrl !== '') {
            // 文档里正文写作 FontURL、示例写作 FontUrl，两个都带上以兼容不同版本。
            $clip['FontURL'] = $fontUrl;
            $clip['FontUrl'] = $fontUrl;
        }
        return $clip;
    }

    /**
     * 标题纵向位置。
     *
     * 前端传 0~1 的比例时按成片高度换算成像素；传 ≥8 的整数时视为像素直接使用；
     * 未传时按成片高度 8% 的默认留白。
     *
     * @param mixed $value        前端传入的 Y
     * @param int   $outputHeight 成片高度（像素）
     */
    private static function titleY($value, int $outputHeight): int
    {
        if ($value !== null && is_numeric($value)) {
            $value = (float)$value;
            if ($value > 0 && $value < 1) {
                return $outputHeight > 0
                    ? (int)round($outputHeight * $value)
                    : (int)round(self::TITLE_TOP_FALLBACK_PX * $value / self::TITLE_TOP_RATIO);
            }
            if ($value >= 1) {
                return (int)round($value);
            }
        }
        return $outputHeight > 0
            ? (int)round($outputHeight * self::TITLE_TOP_RATIO)
            : self::TITLE_TOP_FALLBACK_PX;
    }

    /**
     * 让素材铺满成片画布：相对宽高均为 1，Cover 等比裁切。
     *
     * @param array $clip 已有字段（至少含 MediaURL）
     */
    private static function fitClipToCanvas(array $clip): array
    {
        $clip['Width'] = 1;
        $clip['Height'] = 1;
        $clip['AdaptMode'] = 'Cover';
        return $clip;
    }

    /**
     * 封面图片片段。
     *
     * @param string $coverUrl 封面图片公网地址
     */
    private static function coverClip(string $coverUrl): array
    {
        $seconds = self::COVER_SECONDS;
        return self::fitClipToCanvas([
            'Type' => 'Image',
            'MediaURL' => $coverUrl,
            'Duration' => $seconds,
            'TimelineIn' => 0,
            'TimelineOut' => $seconds,
        ]);
    }

    /**
     * 贴纸图片片段。
     *
     * 坐标语义与字幕一致：X/Y 既可传 0~0.9999 的比例，也可传 ≥8 的像素值，
     * IMS 按这两种区间自行识别。Width/Height 是相对成片的缩放比例。
     * 这里只做缺省填充，凡是前端给了的都原样透传。
     *
     * @param array $sticker 归一化后的贴纸片段（见 SmartEditService::normalizeStickers）
     */
    private static function stickerClip(array $sticker): array
    {
        $clip = [
            'Type' => 'Image',
            'MediaURL' => (string)($sticker['media_url'] ?? ''),
        ];
        foreach (['X', 'Y', 'Width', 'Height', 'Opacity'] as $key) {
            if (array_key_exists($key, $sticker) && $sticker[$key] !== null) {
                $clip[$key] = $sticker[$key];
            }
        }
        $timelineIn = isset($sticker['TimelineIn']) ? round((float)$sticker['TimelineIn'], 4) : 0.0;
        $timelineOut = isset($sticker['TimelineOut']) ? round((float)$sticker['TimelineOut'], 4) : 0.0;
        if ($timelineOut <= $timelineIn) {
            $timelineOut = round($timelineIn + SmartEditService::STICKER_DEFAULT_SECONDS, 4);
        }
        $clip['TimelineIn'] = $timelineIn;
        $clip['TimelineOut'] = $timelineOut;
        // 图片没有自身时长，IMS 靠 Duration 决定这张图占多久。
        // 只给 TimelineIn/Out 时会被当成“自动推算”，图片可能被排成 0 长度而整段不渲染。
        $clip['Duration'] = round($timelineOut - $timelineIn, 4);
        return $clip;
    }

    /**
     * 背景音乐片段。
     *
     * 素材自身的裁取用 In/Out，放到成片时间轴上的位置用 TimelineIn/Out，
     * 两者互不影响。音量走 Effects 里的 Volume，Gain 取值 0~1。
     *
     * @param array $audio 归一化后的音频片段（见 SmartEditService::normalizeAudios）
     */
    private static function audioClip(array $audio): array
    {
        $clip = [
            'MediaURL' => (string)($audio['media_url'] ?? ''),
        ];
        foreach (['In', 'Out', 'TimelineIn', 'TimelineOut'] as $key) {
            if (array_key_exists($key, $audio) && $audio[$key] !== null) {
                $clip[$key] = round((float)$audio[$key], 4);
            }
        }
        // LoopMode 打开后，音乐比成片短时自动循环，前端不必自己算重复次数。
        if (!empty($audio['loop'])) {
            $clip['LoopMode'] = true;
        }
        if (array_key_exists('gain', $audio) && $audio['gain'] !== null) {
            $clip['Effects'] = [
                ['Type' => 'Volume', 'Gain' => round((float)$audio['gain'], 4)],
            ];
        }
        return $clip;
    }
}
