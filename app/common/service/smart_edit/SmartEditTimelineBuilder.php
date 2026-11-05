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

    /** 标题文字距成片顶部的距离，按成片高度占比计算 */
    private const TITLE_TOP_RATIO = 0.08;

    /** 成片高度未知时的标题顶部像素值（1080 高度下的近似 8%） */
    private const TITLE_TOP_FALLBACK_PX = 86;

    /**
     * 多视频合并，并按需叠加贴纸与背景音乐。
     *
     * @param array  $items        [['media_url'=>string,'in'=>float,'out'=>float], ...]
     * @param string $coverUrl     封面图片的公网地址；为空表示不加封面
     * @param string $titleText    标题文案；为空表示不加标题
     * @param float  $titleSeconds 标题展示时长（成片开头起算）
     * @param int    $outputHeight 成片高度（像素），用于换算标题的纵向位置
     * @param array  $stickers     贴纸片段，元素见 stickerClip()
     * @param array  $audioClips   背景音乐片段，元素见 audioClip()
     */
    public static function buildMerge(
        array $items,
        string $coverUrl = '',
        string $titleText = '',
        float $titleSeconds = self::TITLE_SECONDS,
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
            $clip = ['MediaURL' => $url];
            $in = (float)($item['in'] ?? 0);
            $out = (float)($item['out'] ?? 0);
            if ($in > 0) {
                $clip['In'] = round($in, 4);
            }
            if ($out > 0 && $out > $in) {
                $clip['Out'] = round($out, 4);
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

        // 标题走独立的文字轨，从成片 0 秒开始展示。不依赖任何素材时长，
        // 因此 out=0（用完整时长）的片段也不影响。
        if ($titleText !== '') {
            $timeline['SubtitleTracks'] = [
                ['SubtitleTrackClips' => [self::titleClip($titleText, $titleSeconds, $outputHeight)]],
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
     * 标题文字片段：顶部居中。
     *
     * IMS 的坐标语义（见官方字幕示例）：
     * - X/Y 单位为像素，不是画布百分比；0.06 会被当成 0.06px，贴着顶边。
     * - Alignment 决定 X/Y 指向文本框的哪个基准点。Center 是「上边界中点」，
     *   此时 X=0 会把文本框左半边推到画面外，文案前几个字被裁掉。
     *   TopCenter 表示「视频竖直中轴线上侧」，横向自动居中，只需给 Y。
     * Width/Height 只对 Box/Image 类片段有效，文字片段传了不生效，徒增歧义，故不传。
     *
     * 位置与字号集中在这里，后续要放开给用户配置只需改这一处。
     *
     * @param string $text         标题文案
     * @param float  $seconds      展示时长
     * @param int    $outputHeight 成片高度（像素）；0 表示未知，退回固定像素值
     */
    private static function titleClip(string $text, float $seconds, int $outputHeight = 0): array
    {
        // 纵向取成片高度的 8%，与不同输出比例下保持相近的视觉留白。
        $y = $outputHeight > 0
            ? (int)round($outputHeight * self::TITLE_TOP_RATIO)
            : self::TITLE_TOP_FALLBACK_PX;

        return [
            'Type' => 'Text',
            'Content' => $text,
            'TimelineIn' => 0,
            'TimelineOut' => round($seconds, 4),
            'X' => 0,
            'Y' => max(0, $y),
            'FontSize' => 48,
            'FontColor' => '#FFFFFF',
            'Alignment' => 'TopCenter',
        ];
    }

    /**
     * 封面图片片段。
     *
     * @param string $coverUrl 封面图片公网地址
     */
    private static function coverClip(string $coverUrl): array
    {
        $seconds = self::COVER_SECONDS;
        return [
            'Type' => 'Image',
            'MediaURL' => $coverUrl,
            'Duration' => $seconds,
            'TimelineIn' => 0,
            'TimelineOut' => $seconds,
        ];
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
        // 坚持有起止时间才写时间轴字段：缺省时 IMS 会按整段成片铺满。
        foreach (['TimelineIn', 'TimelineOut'] as $key) {
            if (array_key_exists($key, $sticker) && $sticker[$key] !== null) {
                $clip[$key] = round((float)$sticker[$key], 4);
            }
        }
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
