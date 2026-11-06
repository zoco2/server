<?php

namespace app\common\model\file;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 用户文件分组。
 */
class UserFileCate extends BaseModel
{
    use SoftDelete;

    protected $name = 'user_file_cate';

    protected $deleteTime = 'delete_time';

    /**
     * 当前分组及其子分组。调用前需确认根分组属于该用户。
     * @param int $userId
     * @param int $rootId
     * @return int[]
     */
    public static function branchIds(int $userId, int $rootId): array
    {
        $seen = [];
        $pending = [$rootId];
        while ($pending !== []) {
            $current = array_shift($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $children = self::where('user_id', $userId)->where('pid', $current)->column('id');
            foreach ($children as $childId) {
                $childId = (int)$childId;
                if (!isset($seen[$childId])) {
                    $pending[] = $childId;
                }
            }
        }
        return array_map('intval', array_keys($seen));
    }
}
