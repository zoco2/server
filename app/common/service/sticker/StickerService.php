<?php

namespace app\common\service\sticker;

use app\common\model\sticker\StickerCategory;
use app\common\model\sticker\StickerResource;
use app\common\service\FileService;
use Exception;

/**
 * 贴纸库读取与归属校验。
 *
 * 分类 / 资源列表不做租户过滤，按 status、delete_time 等业务条件查询。
 * 同 code 分类去重时保留先命中的一条（排序靠前优先）。
 *
 * 贴纸文件存 OSS，地址统一走 FileService::getFileUrlByStorage()，
 * 与素材库、画布、短剧模板同一套：本地、OSS、私有签名都由该函数处理。
 */
class StickerService
{
    /** 单次最多返回的资源数，避免前端一次拉全量 */
    public const MAX_PAGE_SIZE = 200;

    /**
     * 分类列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categories(): array
    {
        $rows = StickerCategory::where('status', 1)
            ->where('delete_time', 0)
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();

        // 同 code 去重：按当前排序保留第一条
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $code = (string)$row['code'];
            if ($code !== '' && isset($seen[$code])) {
                continue;
            }
            if ($code !== '') {
                $seen[$code] = true;
            }
            $result[] = [
                'id' => (int)$row['id'],
                'code' => $code,
                'name' => (string)$row['name'],
                'icon' => FileService::getFileUrlByStorage((string)$row['icon']),
                'sort' => (int)$row['sort'],
            ];
        }
        return $result;
    }

    /**
     * 资源列表。category_id 为 0 时不限分类。
     *
     * @return array{lists: array<int, array<string, mixed>>, count: int}
     */
    public static function resources(int $categoryId = 0, int $pageNo = 1, int $pageSize = 50): array
    {
        $pageNo = max(1, $pageNo);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));

        $query = StickerResource::where('status', 1)
            ->where('delete_time', 0);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        $count = (clone $query)->count();
        $rows = $query
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        return [
            'lists' => array_map([self::class, 'decorate'], $rows),
            'count' => (int)$count,
        ];
    }

    /**
     * 按 uri 解析贴纸，供智能剪辑提交时校验。
     *
     * 找不到返回 null，由调用方回退到素材库校验，
     * 兼容「从素材库选图片当贴纸」的用法。
     *
     * @return array{uri: string, mime_type: string, url: string}|null
     */
    public static function resolveForSmartEdit(string $uri): ?array
    {
        $uri = ltrim(trim($uri), '/');
        if ($uri === '' || str_contains($uri, '..')) {
            throw new Exception('贴纸地址非法');
        }

        $row = StickerResource::where('uri', $uri)
            ->where('status', 1)
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            return null;
        }

        return [
            'uri' => (string)$row['uri'],
            'mime_type' => (string)$row['mime_type'],
            'url' => self::storageUrl($row),
        ];
    }

    /**
     * 贴纸 uri -> 可访问地址。
     */
    public static function mediaUrl(string $uri): string
    {
        return $uri === '' ? '' : FileService::getFileUrl($uri);
    }

    /**
     * @param StickerResource|array<string, mixed> $row
     */
    private static function storageUrl($row): string
    {
        $uri = (string)($row['uri'] ?? '');
        if ($uri === '') {
            return '';
        }
        return FileService::getFileUrlByStorage(
            $uri,
            (string)($row['storage_scope'] ?? ''),
            (string)($row['storage_engine'] ?? ''),
            (string)($row['storage_domain'] ?? '')
        );
    }

    /**
     * 资源行补上与前端约定一致的字段。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row): array
    {
        $uri = (string)$row['uri'];
        $cover = (string)$row['cover_uri'];
        return [
            'id' => (int)$row['id'],
            'category_id' => (int)$row['category_id'],
            'name' => (string)$row['name'],
            // uri 给智能剪辑提交用，url 给前端预览用
            'uri' => $uri,
            'url' => self::storageUrl($row),
            'cover_url' => $cover === '' ? self::storageUrl($row) : self::storageUrl([
                'uri' => $cover,
                'storage_scope' => $row['storage_scope'] ?? '',
                'storage_engine' => $row['storage_engine'] ?? '',
                'storage_domain' => $row['storage_domain'] ?? '',
            ]),
            'mime_type' => (string)$row['mime_type'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'file_size' => (int)$row['file_size'],
        ];
    }
}
