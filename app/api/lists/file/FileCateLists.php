<?php

namespace app\api\lists\file;

use app\api\lists\BaseApiDataLists;
use app\common\model\file\UserFileCate;

/**
 * 用户文件分组树。
 */
class FileCateLists extends BaseApiDataLists
{
    /**
     * @notes 分组树
     * @return array
     */
    public function lists(): array
    {
        $lists = $this->query()
            ->field('id,pid,type,name')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        return linear_to_tree($lists, 'children');
    }

    /**
     * @notes 分组数量
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
        return UserFileCate::where('user_id', $this->userId)
            ->where('type', (int)($this->params['type'] ?? 0));
    }
}
