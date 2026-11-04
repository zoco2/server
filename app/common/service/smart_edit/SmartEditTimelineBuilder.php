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

    /**
     * 多视频合并。
     *
     * @param array  $items        [['media_url'=>string,'in'=>float,'out'=>float], ...]
     * @param string $coverUrl     封面图片的公网地址；为空表示不加封面
     * @param string $titleText    标题文案；为空表示不加标题
     * @param float  $titleSeconds 标题展示时长（成片开头起算）
     */
    public static function buildMerge(
        array $items,
        string $coverUrl = '',
        string $titleText = '',
        float $titleSeconds = self::TITLE_SECONDS
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
                ['SubtitleTrackClips' => [self::titleClip($titleText, $titleSeconds)]],
            ];
        }

        return $timeline;
    }

    /**
     * 标题文字片段：顶部居中。
     *
     * 位置与字号集中在这里，后续要放开给用户配置只需改这一处。
     *
     * @param string $text    标题文案
     * @param float  $seconds 展示时长
     */
    private static function titleClip(string $text, float $seconds): array
    {
        return [
            'Type' => 'Text',
            'Content' => $text,
            'TimelineIn' => 0,
            'TimelineOut' => round($seconds, 4),
            'X' => 0,
            'Y' => 0.06,
            'Width' => 1,
            'Height' => 0.12,
            'FontSize' => 48,
            'FontColor' => '#FFFFFF',
            'Alignment' => 'Center',
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
