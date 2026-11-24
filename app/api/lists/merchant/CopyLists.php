<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\service\DefaultWorkGroup;
use app\common\model\merchant\MerchantCopy;

class CopyLists extends BaseApiDataLists
{
    public function lists(): array
    {
        return $this->query()
            ->field('id,name,content,create_time,update_time')
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
        $query = MerchantCopy::where([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
        ]);
        $groupId = filter_var($this->params['work_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($groupId !== false) {
            DefaultWorkGroup::filterTextList($query, (int)$this->request->tenantId, $this->userId, 'copy', $groupId);
        }
        $keyword = $this->params['keyword'] ?? '';
        $keyword = is_string($keyword) ? trim($keyword) : '';
        if ($keyword !== '') {
            $query->whereLike('name', '%' . addcslashes($keyword, '\\%_') . '%');
        }
        return $query;
    }
}
