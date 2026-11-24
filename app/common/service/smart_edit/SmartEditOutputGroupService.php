<?php

declare(strict_types=1);

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditGroup;
use app\common\model\smart_edit\SmartEditTask;
use think\facade\Db;

/**
 * 智能剪辑成片与作品分组的归属索引。
 *
 * smart_edit_group.config_json 是批次提交快照，work_item 是作品库查询索引。
 * 常驻 worker 未及时重启时可能只保留前者；读取作品库前可据此幂等补齐后者。
 */
final class SmartEditOutputGroupService
{
    private const TYPE = 'video';
    private const REF_PREFIX = 'smart_edit:task:';

    /** 登记一条任务；已有行（含回收行）保持不变，避免覆盖用户后续操作。 */
    public static function registerTask(
        int $tenantId,
        int $userId,
        int $groupId,
        int $taskId,
        string $title
    ): bool {
        if ($tenantId <= 0 || $userId <= 0 || $groupId <= 0 || $taskId <= 0) {
            return false;
        }
        $identity = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source_ref' => self::REF_PREFIX . $taskId,
        ];
        if (Db::name('work_item')->where($identity)->count()) {
            return false;
        }
        $now = time();
        try {
            return (bool)Db::name('work_item')->insert($identity + [
                'type' => self::TYPE,
                'group_id' => $groupId,
                'title' => mb_substr($title, 0, 255),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (\Throwable $error) {
            // 并发登记由 uk_owner_source 收敛；复查到记录即视为幂等成功。
            if (Db::name('work_item')->where($identity)->count()) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * 按批次快照补齐遗漏的 work_item 行。
     *
     * $onlyGroupId 为 null 时修复当前账号的全部有效视频组；传值时只修复指定组。
     * 只补缺失行，不覆盖用户之后对作品做的移动、回收或删除。
     */
    public static function repairMissingMemberships(
        int $tenantId,
        int $userId,
        ?int $onlyGroupId = null
    ): int {
        if ($tenantId <= 0 || $userId <= 0 || ($onlyGroupId !== null && $onlyGroupId <= 0)) {
            return 0;
        }

        $groupQuery = Db::name('work_group')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => self::TYPE,
        ])->whereNull('delete_time');
        if ($onlyGroupId !== null) {
            $groupQuery->where('id', $onlyGroupId);
        }
        $activeGroupIds = array_fill_keys(array_map('intval', $groupQuery->column('id')), true);
        if ($activeGroupIds === []) {
            return 0;
        }

        $batchGroups = [];
        $groups = SmartEditGroup::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->field('batch_no,config_json')
            ->select();
        foreach ($groups as $group) {
            $config = (array)$group['config_json'];
            $groupId = (int)($config['work_group_id'] ?? 0);
            $batchNo = (string)$group['batch_no'];
            if ($batchNo !== '' && isset($activeGroupIds[$groupId])) {
                $batchGroups[$batchNo] = $groupId;
            }
        }
        if ($batchGroups === []) {
            return 0;
        }

        $rows = [];
        $tasks = SmartEditTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('batch_no', array_keys($batchGroups))
            ->field('id,title,batch_no')
            ->select();
        $now = time();
        foreach ($tasks as $task) {
            $taskId = (int)$task['id'];
            $batchNo = (string)$task['batch_no'];
            if ($taskId <= 0 || !isset($batchGroups[$batchNo])) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => self::TYPE,
                'group_id' => $batchGroups[$batchNo],
                'source_ref' => self::REF_PREFIX . $taskId,
                'title' => mb_substr((string)$task['title'], 0, 255),
                'create_time' => $now,
                'update_time' => $now,
            ];
        }

        $existing = [];
        foreach (array_chunk(array_column($rows, 'source_ref'), 200) as $refs) {
            foreach (Db::name('work_item')->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ])->whereIn('source_ref', $refs)->column('source_ref') as $ref) {
                $existing[(string)$ref] = true;
            }
        }
        $inserted = 0;
        foreach ($rows as $row) {
            if (!isset($existing[$row['source_ref']])) {
                $inserted += self::registerTask(
                    $tenantId,
                    $userId,
                    (int)$row['group_id'],
                    (int)substr($row['source_ref'], strlen(self::REF_PREFIX)),
                    (string)$row['title']
                ) ? 1 : 0;
            }
        }
        return $inserted;
    }

    /** 只修复本页默认分组判定涉及的智能剪辑任务，避免每次排除查询扫描全部批次。 */
    public static function repairRefs(int $tenantId, int $userId, array $refs): int
    {
        $taskIds = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && preg_match('/^smart_edit:task:([1-9][0-9]{0,10})$/D', $ref, $match)) {
                $taskIds[] = (int)$match[1];
            }
        }
        $taskIds = array_values(array_unique($taskIds));
        if ($tenantId <= 0 || $userId <= 0 || $taskIds === []) {
            return 0;
        }

        $tasks = SmartEditTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('id', $taskIds)
            ->field('id,title,batch_no')
            ->select();
        $batchNos = [];
        foreach ($tasks as $task) {
            $batchNo = (string)$task['batch_no'];
            if ($batchNo !== '') {
                $batchNos[$batchNo] = true;
            }
        }
        $batchNos = array_keys($batchNos);
        if ($batchNos === []) {
            return 0;
        }

        $candidateGroups = [];
        $groups = SmartEditGroup::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('batch_no', $batchNos)
            ->field('batch_no,config_json')
            ->select();
        foreach ($groups as $group) {
            $groupId = (int)(((array)$group['config_json'])['work_group_id'] ?? 0);
            if ($groupId > 0) {
                $candidateGroups[(string)$group['batch_no']] = $groupId;
            }
        }
        if ($candidateGroups === []) {
            return 0;
        }

        $active = array_fill_keys(array_map('intval', Db::name('work_group')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => self::TYPE,
        ])->whereNull('delete_time')->whereIn('id', array_values($candidateGroups))->column('id')), true);
        $inserted = 0;
        foreach ($tasks as $task) {
            $batchNo = (string)$task['batch_no'];
            $groupId = $candidateGroups[$batchNo] ?? 0;
            if (isset($active[$groupId])) {
                $inserted += self::registerTask(
                    $tenantId,
                    $userId,
                    $groupId,
                    (int)$task['id'],
                    (string)$task['title']
                ) ? 1 : 0;
            }
        }
        return $inserted;
    }

    /** 成片完成时再自愈一次，兼容创建任务阶段未登记归组索引的旧任务。 */
    public static function repairTask(SmartEditTask $task): bool
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $params = (array)$task['params_json'];
        $groupId = (int)($params['work_group_id'] ?? 0);

        if ($groupId <= 0 && (string)$task['batch_no'] !== '') {
            $group = SmartEditGroup::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('batch_no', (string)$task['batch_no'])
                ->field('config_json')
                ->findOrEmpty();
            if (!$group->isEmpty()) {
                $groupId = (int)(((array)$group['config_json'])['work_group_id'] ?? 0);
            }
        }
        if ($groupId <= 0 || !Db::name('work_group')->where([
            'id' => $groupId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => self::TYPE,
        ])->whereNull('delete_time')->count()) {
            return false;
        }

        return self::registerTask(
            $tenantId,
            $userId,
            $groupId,
            (int)$task['id'],
            (string)$task['title']
        );
    }
}
