<?php

declare(strict_types=1);

namespace app\common\service;

use think\db\Query;
use think\facade\Db;

/** 默认分组包含可见的历史作品，以及已登记为 group_id=0 的作品。 */
final class DefaultWorkGroup
{
    public const NAME = '默认分组';

    public static function excludedItems(array $scope): Query
    {
        return Db::name('work_item')->where($scope)
            ->whereRaw('(group_id <> 0 OR delete_time IS NOT NULL)');
    }

    public static function createTextItem(array $scope, int $groupId, callable $create)
    {
        return Db::transaction(static function () use ($scope, $groupId, $create) {
            $group = Db::name('work_group')->where($scope)->where('id', $groupId)
                ->whereNull('delete_time')->lock(true)->find();
            if (!$group) {
                throw new \InvalidArgumentException('所选成片组不存在或不可用');
            }

            $item = $create();
            $now = time();
            Db::name('work_item')->insert($scope + [
                'group_id' => $groupId,
                'source_ref' => 'merchant_' . $scope['type'] . ':' . $item->id,
                'title' => $item->name,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            return $item;
        });
    }

    public static function filterTextList(Query $query, int $tenantId, int $userId, string $type, int $groupId = 0): void
    {
        $scope = ['tenant_id' => $tenantId, 'user_id' => $userId, 'type' => $type];
        if ($groupId > 0) {
            $members = Db::name('work_item')->where($scope)->where('group_id', $groupId)
                ->whereNull('delete_time')->field('source_ref')->buildSql();
            $query->whereRaw('CONCAT(:work_prefix, id) IN ' . $members, ['work_prefix' => 'merchant_' . $type . ':']);
            return;
        }
        $excluded = self::excludedItems($scope)
            ->field('source_ref')->buildSql();
        // id 来自外层商家模型，保留模型对租户分表的解析。只对归属索引做一次子查询。
        $query->whereRaw('CONCAT(:work_prefix, id) NOT IN ' . $excluded, ['work_prefix' => 'merchant_' . $type . ':']);
    }
}
