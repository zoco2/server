<?php

namespace app\common\service\geo;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantBrandKnowledgeArticle;
use InvalidArgumentException;

class GeoBrandKnowledgeService
{
    private const PAGE_LENGTH = 2400;
    private const PROFILE_FIELDS = 'name,industry,contact_name,contact_phone,contact_email,website,address,logo_url,cover_url,company_intro,products_services,target_customers,competitor_facts';

    public static function profile(int $tenantId, int $userId, int $brandId): array
    {
        if ($tenantId <= 0 || $userId <= 0 || $brandId <= 0) {
            throw new InvalidArgumentException('品牌不存在或无权限');
        }
        $brand = MerchantBrand::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $brandId,
        ])->field(self::PROFILE_FIELDS)->findOrEmpty();
        if ($brand->isEmpty()) {
            throw new InvalidArgumentException('品牌不存在或无权限');
        }
        return $brand->toArray();
    }

    public static function catalog(array $scope): array
    {
        $articles = MerchantBrandKnowledgeArticle::where($scope)
            ->field('id,folder_id,title,summary,tags_json,metadata_json,token_estimate,update_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select();

        $catalog = [];
        foreach ($articles as $article) {
            $catalog[] = [
                'article_id' => (int)$article['id'],
                'folder_id' => (int)$article['folder_id'],
                'title' => (string)$article['title'],
                'summary' => (string)$article['summary'],
                'tags' => json_decode((string)$article['tags_json'], true) ?? [],
                'meta' => json_decode((string)$article['metadata_json'], true) ?? [],
                'token_estimate' => (int)$article['token_estimate'],
                'update_time' => (int)$article['update_time'],
            ];
        }
        return $catalog;
    }

    public static function articleDetail(array $scope, int $articleId, int $offset): array
    {
        $start = $offset + 1;
        $length = self::PAGE_LENGTH + 1;
        $article = MerchantBrandKnowledgeArticle::where($scope + ['id' => $articleId])
            ->field('id,title')
            ->fieldRaw("SUBSTRING(content_text, $start, $length) AS excerpt")
            ->findOrEmpty();
        if ($article->isEmpty()) {
            return ['error' => '文章不存在或无权限'];
        }

        $excerpt = (string)$article['excerpt'];
        $hasMore = mb_strlen($excerpt, 'UTF-8') > self::PAGE_LENGTH;
        return [
            'article_id' => (int)$article['id'],
            'title' => (string)$article['title'],
            'content' => mb_substr($excerpt, 0, self::PAGE_LENGTH, 'UTF-8'),
            'next_offset' => $hasMore ? $offset + self::PAGE_LENGTH : null,
        ];
    }
}
