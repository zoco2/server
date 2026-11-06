<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantStaff;

/**
 * 员工列表。
 */
class StaffLists extends BaseApiDataLists
{
    /**
     * @notes 员工列表
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
     * @notes 员工数量
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
        $query = MerchantStaff::where('user_id', $this->userId);
        if ((int)($this->params['store_id'] ?? 0) > 0) {
            $query->where('store_id', (int)$this->params['store_id']);
        }
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $safe = str_replace(['%', '_'], ['\%', '\_'], $keyword);
            $query->where(function ($inner) use ($safe) {
                $inner->whereLike('name', '%' . $safe . '%')->whereOr('mobile', 'like', '%' . $safe . '%');
            });
        }
        return $query;
    }
}
