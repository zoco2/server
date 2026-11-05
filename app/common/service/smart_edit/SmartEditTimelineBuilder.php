<?php

namespace app\common\service\smart_edit;

use Exception;

/**
 * Timeline 构建。
 *
 * 每个 edit_type 一个分支，分支之间互不影响：
 * - merge（本轮）：单条视频轨，素材顺序相接
 * - subtitle / sticker / bgm（后续）：在已有 Timeline 基础上追加对应轨道
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
     * 多视频合并。
     *
     * @param array  $items        [['media_url'=>string,'in'=>float,'out'=>float], ...]
     * @param string $coverUrl     封面图片的公网地址；为空表示不加封面
     * @param string $titleText    标题文案；为空表示不加标题
     * @param float  $titleSeconds 标题展示时长（成片开头起算）
     * @param int    $outputHeight 成片高度（像素），用于换算标题的纵向位置
     */
    public static function buildMerge(
        array $items,
        string $coverUrl = '',
        string $titleText = '',
        float $titleSeconds = self::TITLE_SECONDS,
        int $outputHeight = 0
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

        $timeline = [
            'VideoTracks' => [
                ['VideoTrackClips' => $clips],
            ],
        ];

        // 标题走独立的文字轨，从成片 0 秒开始展示。不依赖任何素材时长，
        // 因此 out=0（用完整时长）的片段也不影响。
        if ($titleText !== '') {
            $timeline['SubtitleTracks'] = [
                ['SubtitleTrackClips' => [self::titleClip($titleText, $titleSeconds, $outputHeight)]],
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
}
