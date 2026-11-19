<?php

declare(strict_types=1);

namespace app\common\service\xiaohongshu;

use InvalidArgumentException;

/** 只传当前官方支持的媒体字段，不沿用文档旧示例中的文案自动填充字段。 */
final class XiaohongshuSharePayload
{
    public static function normalize(array $input): array
    {
        if (array_intersect(array_keys($input), ['title', 'content', 'hashtags', 'hashtag_list', 'topics'])) {
            throw new InvalidArgumentException('小红书分享不再支持自动填充标题、正文或话题，请由用户在小红书内填写');
        }
        if (array_diff(array_keys($input), ['type', 'images', 'video', 'cover'])) {
            throw new InvalidArgumentException('包含不支持的小红书分享字段');
        }
        $type = $input['type'] ?? null;
        if (!in_array($type, ['normal', 'video'], true)) {
            throw new InvalidArgumentException('小红书笔记类型必须为 normal 或 video');
        }
        if ($type === 'normal') {
            if (array_key_exists('video', $input) || array_key_exists('cover', $input)) {
                throw new InvalidArgumentException('图文笔记不能同时传入视频或视频封面');
            }
            $images = $input['images'] ?? null;
            if (!is_array($images) || !array_is_list($images) || $images === []) {
                throw new InvalidArgumentException('图文笔记需要非空的 images URL 数组');
            }
            return ['type' => 'normal', 'images' => array_map([self::class, 'mediaUrl'], $images)];
        }
        if (array_key_exists('images', $input)) {
            throw new InvalidArgumentException('视频笔记不能同时传入 images');
        }
        $result = ['type' => 'video', 'video' => self::mediaUrl($input['video'] ?? null)];
        if (array_key_exists('cover', $input)) {
            $result['cover'] = self::mediaUrl($input['cover']);
        }
        return $result;
    }

    private static function mediaUrl(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('素材 URL 必须是字符串');
        }
        $parts = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[^\x21-\x7e]|\\\\/', $value)) {
            throw new InvalidArgumentException('素材须为完整的 HTTP(S) URL，中文和空格请先进行 URL 编码');
        }
        return $value;
    }
}
