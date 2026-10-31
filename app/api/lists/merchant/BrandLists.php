<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantBrand;

/**
 * 品牌列表。
 */
class BrandLists extends BaseApiDataLists
{
    /**
     * @notes 品牌列表
     * @return array
     */
    public function lists(): array
    {
        return $this->query()
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    /**
     * @notes 品牌数量
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
        $query = MerchantBrand::where('user_id', $this->userId);
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . self::like($keyword) . '%');
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
