<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantBrandKnowledgeArticle;

/**
 * 品牌知识库文章列表。
 */
class KnowledgeArticleLists extends BaseApiDataLists
{
    /**
     * @return array
     */
    public function lists(): array
    {
        return $this->query()
            ->field('id,brand_id,folder_id,title,summary,content_text,tags_json,metadata_json,token_estimate,ai_index_status,ai_index_version,ai_indexed_at,sort,create_time,update_time')
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @return \think\db\Query
     */
    private function query()
    {
        $query = MerchantBrandKnowledgeArticle::where('user_id', $this->userId)
            ->where('brand_id', (int)($this->params['brand_id'] ?? 0));

        if (isset($this->params['folder_id']) && $this->params['folder_id'] !== '') {
            $query->where('folder_id', (int)$this->params['folder_id']);
        }

        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . self::like($keyword) . '%';
            $query->where(function ($query) use ($like) {
                $query->whereLike('title', $like)->whereOr('content_text', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param string $keyword
     * @return string
     */
    private static function like(string $keyword): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $keyword);
    }
}
