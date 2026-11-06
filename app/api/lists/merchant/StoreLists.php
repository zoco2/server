<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantStore;

/**
 * 门店列表。
 */
class StoreLists extends BaseApiDataLists
{
    /**
     * @notes 门店列表
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
     * @notes 门店数量
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
        $query = MerchantStore::where('user_id', $this->userId);
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $safe = str_replace(['%', '_'], ['\%', '\_'], $keyword);
            $query->whereLike('name', '%' . $safe . '%');
        }
        if ((int)($this->params['brand_id'] ?? 0) > 0) {
            $query->where('brand_id', (int)$this->params['brand_id']);
        }
        if (isset($this->params['status']) && $this->params['status'] !== '') {
            $query->where('status', (int)$this->params['status']);
        }
        return $query;
    }
}
