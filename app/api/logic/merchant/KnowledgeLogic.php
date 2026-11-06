<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantBrandKnowledgeArticle;
use app\common\model\merchant\MerchantBrandKnowledgeFolder;
use Throwable;

/**
 * 品牌知识库。
 */
class KnowledgeLogic extends BaseLogic
{
    /**
     * @param int $brandId
     * @return bool
     */
    public static function canUseBrand(int $brandId): bool
    {
        if (!self::brandOwned($brandId)) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        return true;
    }

    /**
     * @param int $brandId
     * @return array
     */
    public static function folders(int $brandId): array
    {
        if (!self::brandOwned($brandId)) {
            self::setError('品牌不存在或无权限');
            return [];
        }

        $folders = MerchantScope::query(MerchantBrandKnowledgeFolder::class)
            ->where('brand_id', $brandId)
            ->field('id,brand_id,name,sort,metadata_json,create_time,update_time')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $counts = MerchantScope::query(MerchantBrandKnowledgeArticle::class)
            ->where('brand_id', $brandId)
            ->field('folder_id,COUNT(*) AS article_count')
            ->group('folder_id')
            ->select()
            ->toArray();
        $countMap = [];
        foreach ($counts as $row) {
            $countMap[(int)$row['folder_id']] = (int)$row['article_count'];
        }

        foreach ($folders as &$folder) {
            $folder['article_count'] = $countMap[(int)$folder['id']] ?? 0;
        }
        unset($folder);

        return [
            'folders' => $folders,
            'ungrouped_count' => $countMap[0] ?? 0,
            'total_count' => array_sum($countMap),
        ];
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function addFolder(array $params): bool
    {
        $brandId = (int)$params['brand_id'];
        if (!self::brandOwned($brandId)) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        $name = trim((string)$params['name']);
        if (self::folderNameTaken($brandId, $name)) {
            self::setError('文件夹名称已存在');
            return false;
        }
        try {
            MerchantBrandKnowledgeFolder::create([
                'user_id' => (int)request()->userId,
                'brand_id' => $brandId,
                'name' => $name,
                'sort' => (int)($params['sort'] ?? 0),
                'metadata_json' => self::jsonObject($params['metadata_json'] ?? ''),
            ]);
            return true;
        } catch (Throwable $e) {
            self::setError(self::duplicate($e, 'uk_brand_name') ? '文件夹名称已存在' : '文件夹保存失败');
            return false;
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function editFolder(array $params): bool
    {
        $row = self::folderRow((int)$params['id']);
        if (!$row) {
            self::setError('文件夹不存在或无权限');
            return false;
        }
        $brandId = (int)$row['brand_id'];
        $name = trim((string)$params['name']);
        if (self::folderNameTaken($brandId, $name, (int)$params['id'])) {
            self::setError('文件夹名称已存在');
            return false;
        }
        try {
            $row->save([
                'name' => $name,
                'sort' => (int)($params['sort'] ?? 0),
                'metadata_json' => self::jsonObject($params['metadata_json'] ?? ''),
            ]);
            return true;
        } catch (Throwable $e) {
            self::setError(self::duplicate($e, 'uk_brand_name') ? '文件夹名称已存在' : '文件夹保存失败');
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function deleteFolder(int $id): bool
    {
        $row = self::folderRow($id);
        if (!$row) {
            self::setError('文件夹不存在或无权限');
            return false;
        }
        $used = MerchantScope::query(MerchantBrandKnowledgeArticle::class)
            ->where('folder_id', $id)
            ->count();
        if ($used > 0) {
            self::setError('文件夹下还有文章，不能删除');
            return false;
        }
        $row->delete();
        return true;
    }

    /**
     * @param int $id
     * @return array
     */
    public static function detail(int $id): array
    {
        $row = self::articleRow($id);
        if (!$row) {
            self::setError('文章不存在或无权限');
            return [];
        }
        return $row->toArray();
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function addArticle(array $params): bool
    {
        $brandId = (int)$params['brand_id'];
        if (!self::brandOwned($brandId)) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        $folderId = (int)($params['folder_id'] ?? 0);
        if (!self::folderBelongsToBrand($folderId, $brandId)) {
            self::setError('文件夹不存在或无权限');
            return false;
        }
        try {
            MerchantBrandKnowledgeArticle::create(self::articleFields($params, $brandId));
            return true;
        } catch (Throwable $e) {
            self::setError('文章保存失败');
            return false;
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function editArticle(array $params): bool
    {
        $row = self::articleRow((int)$params['id']);
        if (!$row) {
            self::setError('文章不存在或无权限');
            return false;
        }
        $brandId = (int)$row['brand_id'];
        $folderId = (int)($params['folder_id'] ?? 0);
        if (!self::folderBelongsToBrand($folderId, $brandId)) {
            self::setError('文件夹不存在或无权限');
            return false;
        }
        try {
            $fields = self::articleFields($params, $brandId);
            $contentChanged = (string)($row['content_hash'] ?? '') !== $fields['content_hash'];
            if ($contentChanged) {
                $fields['ai_index_status'] = 'none';
                $fields['ai_indexed_at'] = 0;
            } else {
                unset($fields['ai_index_status'], $fields['ai_indexed_at']);
            }
            unset($fields['user_id'], $fields['brand_id']);
            $row->save($fields);
            return true;
        } catch (Throwable $e) {
            self::setError('文章保存失败');
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function deleteArticle(int $id): bool
    {
        $row = self::articleRow($id);
        if (!$row) {
            self::setError('文章不存在或无权限');
            return false;
        }
        $row->delete();
        return true;
    }

    /**
     * @param array $params
     * @param int $brandId
     * @return array
     */
    private static function articleFields(array $params, int $brandId): array
    {
        $contentHtml = self::sanitizeHtml((string)$params['content_html']);
        $contentText = self::plainText($contentHtml);
        $summary = trim((string)($params['summary'] ?? ''));
        if ($summary === '') {
            $summary = mb_substr($contentText, 0, 120);
        }
        return [
            'user_id' => (int)request()->userId,
            'brand_id' => $brandId,
            'folder_id' => (int)($params['folder_id'] ?? 0),
            'title' => trim((string)$params['title']),
            'content_html' => $contentHtml,
            'content_text' => $contentText,
            'summary' => mb_substr($summary, 0, 500),
            'tags_json' => self::jsonList($params['tags_json'] ?? ''),
            'metadata_json' => self::jsonObject($params['metadata_json'] ?? ''),
            'content_hash' => hash('sha256', $contentHtml),
            'token_estimate' => max(0, (int)ceil(mb_strlen($contentText) / 1.5)),
            'ai_index_status' => 'none',
            'ai_indexed_at' => 0,
            'sort' => (int)($params['sort'] ?? 0),
        ];
    }

    /**
     * @param int $brandId
     * @return bool
     */
    private static function brandOwned(int $brandId): bool
    {
        return MerchantScope::query(MerchantBrand::class)->where('id', $brandId)->count() > 0;
    }

    /**
     * @param int $folderId
     * @param int $brandId
     * @return bool
     */
    private static function folderBelongsToBrand(int $folderId, int $brandId): bool
    {
        if ($folderId <= 0) {
            return true;
        }
        return MerchantScope::query(MerchantBrandKnowledgeFolder::class)
            ->where('id', $folderId)
            ->where('brand_id', $brandId)
            ->count() > 0;
    }

    /**
     * @param int $id
     * @return MerchantBrandKnowledgeFolder|null
     */
    private static function folderRow(int $id)
    {
        $row = MerchantScope::query(MerchantBrandKnowledgeFolder::class)->where('id', $id)->findOrEmpty();
        return $row->isEmpty() ? null : $row;
    }

    /**
     * @param int $id
     * @return MerchantBrandKnowledgeArticle|null
     */
    private static function articleRow(int $id)
    {
        $row = MerchantScope::query(MerchantBrandKnowledgeArticle::class)->where('id', $id)->findOrEmpty();
        return $row->isEmpty() ? null : $row;
    }

    /**
     * @param int $brandId
     * @param string $name
     * @param int $exceptId
     * @return bool
     */
    private static function folderNameTaken(int $brandId, string $name, int $exceptId = 0): bool
    {
        $query = MerchantScope::query(MerchantBrandKnowledgeFolder::class)
            ->where('brand_id', $brandId)
            ->where('name', $name);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    /**
     * @param string $html
     * @return string
     */
    private static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '$1="#"', $html) ?? '';
        return trim($html);
    }

    /**
     * @param string $html
     * @return string
     */
    private static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function jsonObject($value): string
    {
        if ($value === null || $value === '') {
            return '{}';
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded) || self::isList($decoded)) {
            return '{}';
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function jsonList($value): string
    {
        if ($value === null || $value === '') {
            return '[]';
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded) || !self::isList($decoded)) {
            return '[]';
        }
        $tags = [];
        foreach ($decoded as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $tags[] = mb_substr($item, 0, 30);
            }
        }
        return json_encode(array_values(array_unique($tags)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @param array $value
     * @return bool
     */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param Throwable $e
     * @param string $needle
     * @return bool
     */
    private static function duplicate(Throwable $e, string $needle): bool
    {
        return str_contains($e->getMessage(), $needle);
    }
}
