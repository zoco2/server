<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantTopic;

/** 当前商家的话题列表。 */
class TopicLists extends BaseApiDataLists
{
    public function lists(): array
    {
        return $this->query()
            ->field('id,name,create_time')
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = MerchantTopic::where('user_id', $this->userId);
        $keyword = $this->params['keyword'] ?? '';
        $keyword = is_string($keyword) ? trim(preg_replace('/^[#＃\s]+/u', '', $keyword) ?? '') : '';
        if ($keyword !== '') {
            $query->whereLike('name', '%' . addcslashes($keyword, '\\%_') . '%');
        }
        return $query;
    }
}
