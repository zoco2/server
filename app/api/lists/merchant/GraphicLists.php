<?php

namespace app\api\lists\merchant;

use app\api\lists\BaseApiDataLists;
use app\common\model\merchant\MerchantGraphic;

class GraphicLists extends BaseApiDataLists
{
    public function lists(): array
    {
        $rows = $this->query()
            ->field('id,name,content,file_ids,create_time,update_time')
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        $fileIds = array_unique(array_merge([], ...array_column($rows, 'file_ids')));
        $files = MerchantGraphic::imageFiles((int)$this->request->tenantId, $this->userId, $fileIds);
        return array_map(static fn(array $row): array => MerchantGraphic::present($row, $files), $rows);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = MerchantGraphic::where([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
        ]);
        $keyword = $this->params['keyword'] ?? '';
        $keyword = is_string($keyword) ? trim($keyword) : '';
        if ($keyword !== '') {
            $query->whereLike('name', '%' . addcslashes($keyword, '\\%_') . '%');
        }
        return $query;
    }
}
