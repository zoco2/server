<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\service\DefaultWorkGroup;
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
        $groupId = filter_var($this->params['work_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($groupId !== false) {
            DefaultWorkGroup::filterTextList($query, (int)$this->request->tenantId, $this->userId, 'topic', $groupId);
        }
        $keyword = $this->params['keyword'] ?? '';
        $keyword = is_string($keyword) ? trim(preg_replace('/^[#＃\s]+/u', '', $keyword) ?? '') : '';
        if ($keyword !== '') {
            $query->whereLike('name', '%' . addcslashes($keyword, '\\%_') . '%');
        }
        return $query;
    }
}
