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
    /**
     * 多视频合并。
     *
     * @param array $items [['media_url'=>string,'in'=>float,'out'=>float], ...]
     */
    public static function buildMerge(array $items): array
    {
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

        return [
            'VideoTracks' => [
                ['VideoTrackClips' => $clips],
            ],
        ];
    }
}