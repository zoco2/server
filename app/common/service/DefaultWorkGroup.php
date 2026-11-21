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

    public static function filterTextList(Query $query, int $tenantId, int $userId, string $type): void
    {
        $excluded = self::excludedItems(['tenant_id' => $tenantId, 'user_id' => $userId, 'type' => $type])
            ->field('source_ref')->buildSql();
        // id 来自外层商家模型，保留模型对租户分表的解析。只对归属索引做一次子查询。
        $query->whereRaw('CONCAT(:work_prefix, id) NOT IN ' . $excluded, ['work_prefix' => 'merchant_' . $type . ':']);
    }
}
