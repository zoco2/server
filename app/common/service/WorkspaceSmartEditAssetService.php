<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditService;
use InvalidArgumentException;
use think\db\Query;
use think\facade\Db;

/** 智能剪辑直接从成功任务读取合成结果；任务模型负责租户分表。 */
final class WorkspaceSmartEditAssetService
{
    private const FIELDS = 'id,title,status,duration,create_time,output_uri,cover_uri,storage_domain';

    public static function lists(int $tenant, int $user, int $page, int $size, string $keyword, ?int $groupId = null): array
    {
        if ($page < 1 || $size < 1 || $size > 100 || $page > intdiv(PHP_INT_MAX, $size)) {
            throw new InvalidArgumentException('页码须大于 0，每页数量须在 1 到 100 之间');
        }

        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'type' => 'video'];
        $membership = '';
        if ($groupId === 0) {
            $excluded = DefaultWorkGroup::excludedItems($scope)->field('source_ref')->buildSql();
            $membership = "CONCAT('smart_edit:task:', id) NOT IN " . $excluded;
        } elseif ($groupId !== null) {
            if (!Db::name('work_group')->where($scope)->where('id', $groupId)->whereNull('delete_time')->count()) {
                throw new InvalidArgumentException('成片组不存在或已删除');
            }
            $members = Db::name('work_item')->where($scope)->where('group_id', $groupId)
                ->whereNull('delete_time')->field('source_ref')->buildSql();
            $membership = "CONCAT('smart_edit:task:', id) IN " . $members;
        }

        // 租户模型解析分表时会修改查询，列表和总数分别创建查询实例。
        $count = self::visibleTasks($tenant, $user, $keyword, $membership)->count();
        $rows = self::visibleTasks($tenant, $user, $keyword, $membership)->field(self::FIELDS)->order(['create_time' => 'desc', 'id' => 'desc'])
            ->page($page, $size)->select();
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::present($row->getData());
        }
        return ['lists' => $items, 'count' => $count, 'has_more' => $page * $size < $count];
    }

    private static function visibleTasks(int $tenant, int $user, string $keyword, string $membership): Query
    {
        $query = self::finishedTasks($tenant, $user);
        $hidden = Db::name('user_asset_state')->where(['tenant_id' => $tenant, 'user_id' => $user])
            ->whereRaw('(trashed_at > 0 OR hidden_at > 0)')->field('asset_ref')->buildSql();
        $query->whereRaw("CONCAT('smart_edit:task:', id) NOT IN " . $hidden);
        if ($membership !== '') {
            $query->whereRaw($membership);
        }
        if ($keyword !== '') {
            $literal = strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("title LIKE ? ESCAPE '!'", ['%' . $literal . '%']);
        }
        return $query;
    }

    public static function owned(int $tenant, int $user, string $ref, bool $includeDeleted): ?array
    {
        if (!preg_match('/^smart_edit:task:([1-9][0-9]{0,10})$/D', $ref, $match)) {
            return null;
        }
        $task = self::finishedTasks($tenant, $user, $includeDeleted)
            ->field(self::FIELDS)->where('id', (int)$match[1])->find();
        return $task ? self::present($task->getData()) : null;
    }

    public static function syncDeletion(int $tenant, int $user, int $id, int $stamp, bool $restore): void
    {
        $query = self::finishedTasks($tenant, $user, true)->where('id', $id);
        if ($restore) {
            $query->where('delete_time', $stamp)->update(['delete_time' => null]);
        } else {
            $query->whereNull('delete_time')->update(['delete_time' => $stamp]);
        }
    }

    private static function finishedTasks(int $tenant, int $user, bool $includeDeleted = false): Query
    {
        if ($tenant <= 0 || $user <= 0) {
            throw new InvalidArgumentException('请先登录');
        }
        $query = SmartEditTask::withTrashed()->where([
            'tenant_id' => $tenant, 'user_id' => $user, 'status' => SmartEditTask::STATUS_SUCCESS,
        ])->whereRaw("TRIM(output_uri) <> '' AND TRIM(storage_domain) <> ''");
        if (!$includeDeleted) {
            $query->whereNull('delete_time');
        }
        return $query;
    }

    private static function present(array $row): array
    {
        $task = SmartEditService::formatTask($row);
        $id = (int)$task['id'];
        return [
            'source' => 'smart_edit',
            'ref' => 'smart_edit:task:' . $id,
            'raw' => [
                'id' => $id, 'task_id' => $id, 'title' => (string)$task['title'],
                'video_url' => $task['output_url'], 'cover_url' => $task['cover_url'],
                'duration' => (float)$task['duration'], 'status' => $task['status'],
                'create_time' => (int)$task['create_time'],
            ],
        ];
    }
}
