<?php

declare(strict_types=1);

namespace app\common\service\douyin;

use InvalidArgumentException;

/** 接收 PHP 数组，集中校验并序列化官方 Schema 字段。 */
final class DouyinSharePayload
{
    public static function normalize(array $input): array
    {
        $allowed = ['video_path', 'image_path', 'image_list_path', 'title', 'short_title', 'state', 'poi_id',
            'hashtag_list', 'title_hashtag_list', 'micro_app_info', 'share_to_publish', 'share_to_type',
            'download_type', 'private_status', 'feature'];
        if (array_diff(array_keys($input), $allowed)) {
            throw new InvalidArgumentException('包含不支持的抖音分享字段');
        }
        $params = [];
        // 与官方规则一致：视频优先，其次图集，最后单图。
        if (isset($input['video_path']) && $input['video_path'] !== '') {
            $params['video_path'] = self::mediaUrl($input['video_path']);
        } elseif (isset($input['image_list_path']) && $input['image_list_path'] !== []) {
            $images = self::listValue($input['image_list_path'], 'image_list_path');
            if ($images === []) {
                throw new InvalidArgumentException('图集不能为空');
            }
            $params['image_list_path'] = self::json(array_map([self::class, 'mediaUrl'], $images));
        } elseif (isset($input['image_path']) && $input['image_path'] !== '') {
            $params['image_path'] = self::mediaUrl($input['image_path']);
        } else {
            throw new InvalidArgumentException('抖音分享至少需要一个视频、单图或图集');
        }

        foreach (['title', 'short_title', 'state', 'poi_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $params[$field] = self::stringValue($input[$field], $field);
            }
        }
        foreach (['hashtag_list', 'title_hashtag_list'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $tags = self::listValue($input[$field], $field);
            foreach ($tags as $tag) {
                if ($field === 'hashtag_list') {
                    if (trim(self::stringValue($tag, $field)) === '') {
                        throw new InvalidArgumentException('抖音话题不能为空');
                    }
                } elseif (!is_array($tag) || array_diff(array_keys($tag), ['name', 'start'])
                    || trim(self::stringValue($tag['name'] ?? null, $field)) === ''
                    || !is_int($tag['start'] ?? null) || $tag['start'] < 0) {
                    throw new InvalidArgumentException('title_hashtag_list 需要 name 和非负整数 start');
                }
            }
            $params[$field] = self::json($tags);
        }

        if (array_key_exists('micro_app_info', $input)) {
            $microApp = $input['micro_app_info'];
            if (!is_array($microApp) || array_diff(array_keys($microApp), ['appId', 'appTitle', 'description', 'appUrl'])
                || trim(self::stringValue($microApp['appId'] ?? null, 'appId')) === ''
                || trim(self::stringValue($microApp['appUrl'] ?? null, 'appUrl')) === '') {
                throw new InvalidArgumentException('micro_app_info 需要 appId 和 appUrl');
            }
            foreach ($microApp as $key => $value) {
                self::stringValue($value, $key);
            }
            if (preg_match('/[^\x21-\x7e]/', $microApp['appUrl'])) {
                throw new InvalidArgumentException('小程序 appUrl 中的中文和空格请先进行 URL 编码');
            }
            $params['micro_app_info'] = self::json($microApp);
        }

        $params['share_to_publish'] = self::enumValue($input['share_to_publish'] ?? (isset($params['video_path']) ? 1 : 0), [0, 1], 'share_to_publish');
        $params['share_to_type'] = self::enumValue($input['share_to_type'] ?? 0, [0, 1], 'share_to_type');
        if ($params['share_to_publish'] === 1 && !isset($params['video_path'])) {
            throw new InvalidArgumentException('直接进入抖音发布页仅支持单视频');
        }
        foreach (['download_type' => [1, 2], 'private_status' => [0, 1, 2]] as $field => $values) {
            if (array_key_exists($field, $input)) {
                if ($params['share_to_type'] === 1) {
                    throw new InvalidArgumentException('转发到日常不支持 ' . $field);
                }
                $params[$field] = self::enumValue($input[$field], $values, $field);
            }
        }
        if (array_key_exists('feature', $input)) {
            if ($input['feature'] !== 'note' || $params['share_to_type'] !== 0
                || !isset($params['image_list_path']) || count($input['image_list_path']) < 2) {
                throw new InvalidArgumentException('feature=note 仅适用于普通投稿的多图笔记');
            }
            $params['feature'] = 'note';
        }
        return $params;
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new InvalidArgumentException($field . ' 必须是有效的 UTF-8 字符串');
        }
        return $value;
    }

    private static function mediaUrl(mixed $value): string
    {
        $url = self::stringValue($value, '素材 URL');
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[^\x21-\x7e]|\\\\/', $url)) {
            throw new InvalidArgumentException('素材须为完整的 HTTP(S) URL，中文和空格请先进行 URL 编码');
        }
        return $url;
    }

    private static function listValue(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' 必须是数组列表，无需预先 JSON 编码');
        }
        return $value;
    }

    private static function enumValue(mixed $value, array $values, string $field): int
    {
        if (!is_int($value) || !in_array($value, $values, true)) {
            throw new InvalidArgumentException($field . ' 取值无效');
        }
        return $value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
