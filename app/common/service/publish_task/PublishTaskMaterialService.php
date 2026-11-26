<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\model\merchant\MerchantCopy;
use app\common\model\merchant\MerchantTopic;
use app\common\model\merchant\PublishTaskRun;
use app\common\service\DefaultWorkGroup;
use app\common\service\smart_edit\SmartEditOutputGroupService;
use InvalidArgumentException;
use RuntimeException;
use think\db\exception\PDOException;
use think\facade\Db;

/** 按员工点击的平台锁定一套发布素材，分配明细同时作为次数账本。 */
final class PublishTaskMaterialService
{
    private const TABLE = 'publish_task_material_assignment';
    private const TYPES = ['video', 'finished_image', 'copy', 'topic'];
    private const MEDIA_BY_PLATFORM = [
        'douyin' => ['video', 'finished_image'],
        'xiaohongshu' => ['finished_image', 'video'],
        'kuaishou' => ['video', 'finished_image'],
    ];

    /** @return array<string,int> */
    public static function normalizeGroups(int $tenantId, int $userId, mixed $groups): array
    {
        if (!is_array($groups) || array_diff(array_keys($groups), self::TYPES)) {
            throw new InvalidArgumentException('请选择有效的成片组');
        }
        if (!array_key_exists('video', $groups) && !array_key_exists('finished_image', $groups)) {
            throw new InvalidArgumentException('请选择视频或图片成片组');
        }

        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        $normalized = [];
        foreach ($groups as $type => $value) {
            $id = is_int($value) || is_string($value)
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            if ($id === false || ($id > 0 && !Db::name('work_group')->where($owner + ['id' => $id, 'type' => $type])
                ->whereNull('delete_time')->value('id'))) {
                throw new InvalidArgumentException('所选成片组不存在或不可用');
            }
            $normalized[$type] = $id;
        }
        return $normalized;
    }

    /** @return array{counts:object,limits:object} */
    public static function usage(int $tenantId, int $userId, mixed $type, mixed $refs): array
    {
        if (!in_array($type, self::TYPES, true) || !is_array($refs) || !array_is_list($refs) || count($refs) > 100) {
            throw new InvalidArgumentException('请选择素材类型并提供最多 100 个作品标识');
        }
        foreach ($refs as $ref) {
            if (!is_string($ref) || !preg_match('/^[a-z0-9_:]{1,128}$/D', $ref)) {
                throw new InvalidArgumentException('作品标识格式无效');
            }
        }
        if (!$refs) {
            return ['counts' => (object)[], 'limits' => (object)[]];
        }

        $refs = array_values(array_unique($refs));
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        $column = $type . '_ref';
        $counts = array_fill_keys($refs, 0);
        $limits = $counts;
        $rows = Db::name(self::TABLE)->where($owner)->whereIn($column, $refs)
            ->field($column . ',COUNT(*) AS used_count')->group($column)->select()->toArray();
        foreach ($rows as $row) {
            $counts[$row[$column]] = (int)$row['used_count'];
        }

        $scope = $owner + ['type' => $type];
        $members = Db::name('work_item')->where($scope)->whereIn('source_ref', $refs)
            ->whereNull('delete_time')->where('group_id', '>', 0)->column('group_id', 'source_ref');
        if ($members) {
            $maxUses = Db::name('work_group')->where($scope)->whereNull('delete_time')
                ->whereIn('id', array_values(array_unique($members)))->column('max_uses', 'id');
            foreach ($members as $ref => $groupId) {
                $limits[$ref] = (int)($maxUses[$groupId] ?? 0);
            }
        }
        return ['counts' => (object)$counts, 'limits' => (object)$limits];
    }

    /** @return array<string,array{ref:string,title:string,url?:string,text?:string}>|null */
    public static function readExisting(int $tenantId, int $userId, int $runId, int $staffId, string $platform): ?array
    {
        $scope = self::assignmentScope($tenantId, $userId, $runId, $staffId, $platform);
        $row = Db::name(self::TABLE)->master()->where($scope)->field('materials')->find();
        if (!$row) {
            return null;
        }
        $materials = json_decode($row['materials'], true, flags: JSON_THROW_ON_ERROR);
        $media = new PublishTaskMediaPool($tenantId, $userId);
        foreach (self::MEDIA_BY_PLATFORM[$platform] as $type) {
            if (!isset($materials[$type])) {
                continue;
            }
            $updated = $media->resolve($type, $materials[$type]['ref']);
            if ($updated === null) {
                throw new InvalidArgumentException('已分配的发布素材已移除或不可访问');
            }
            // 重新生成签名 URL，保持素材引用和已使用次数不变。
            $materials[$type]['url'] = $updated['url'];
        }
        return $materials;
    }

    /** @return array<string,array{ref:string,title:string,url?:string,text?:string}> */
    public static function assign(int $tenantId, int $userId, int $runId, int $staffId, string $platform, array $groupIds): array
    {
        $existing = self::readExisting($tenantId, $userId, $runId, $staffId, $platform);
        if ($existing !== null) {
            return $existing;
        }
        $scope = self::assignmentScope($tenantId, $userId, $runId, $staffId, $platform);
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        if (isset($groupIds['video']) && class_exists(SmartEditOutputGroupService::class) && in_array(Db::name('smart_edit_group')->getTable(), Db::connect()->getTables(), true)) {
            SmartEditOutputGroupService::repairMissingMemberships($tenantId, $userId, $groupIds['video'] ?: null);
        }

        $create = static function () use ($scope, $owner, $platform, $groupIds): ?array {
            $run = PublishTaskRun::where(['user_id' => $owner['user_id'], 'id' => $scope['run_id']])->lock(true)->field('id')->findOrEmpty();
            if ($run->isEmpty()) {
                throw new InvalidArgumentException('任务执行记录不存在');
            }
            if (Db::name(self::TABLE)->where($scope)->lock(true)->field('id')->find()) {
                return null;
            }

            $groups = self::lockGroups($owner, $groupIds);
            $media = new PublishTaskMediaPool($owner['tenant_id'], $owner['user_id']);
            $materials = [];
            $missing = [];
            foreach (self::MEDIA_BY_PLATFORM[$platform] as $type) {
                if (!isset($groups[$type])) {
                    continue;
                }
                $item = self::pick($owner, $type, $groups[$type], $media);
                if ($item !== null) {
                    $materials[$type] = $item;
                    break;
                }
                $missing[] = ['type' => $type, 'name' => $groups[$type]['name']];
            }
            if (!$materials) {
                throw new PublishTaskMaterialShortage($missing);
            }
            foreach (['copy', 'topic'] as $type) {
                if (isset($groups[$type])) {
                    $item = self::pick($owner, $type, $groups[$type], $media);
                    if ($item !== null) {
                        $materials[$type] = $item;
                    }
                }
            }

            $snapshot = $materials;
            $refs = [];
            foreach ($snapshot as $type => &$item) {
                $refs[$type . '_ref'] = $item['ref'];
                unset($item['url']);
            }
            unset($item);
            Db::name(self::TABLE)->insert($scope + $refs + [
                'materials' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'create_time' => time(),
            ]);
            return $materials;
        };

        for ($attempt = 0; ; $attempt++) {
            try {
                $materials = Db::transaction($create);
                return $materials ?? self::readExisting($tenantId, $userId, $runId, $staffId, $platform)
                    ?? throw new RuntimeException('素材分配结果不存在');
            } catch (PDOException $error) {
                // 并发跨组移动或首条使用记录插入可能引发数据库死锁。
                $driverCode = (int)($error->getData()['PDO Error Info']['Driver Error Code'] ?? 0);
                if ($attempt >= 2 || !in_array($driverCode, [1205, 1213], true)) {
                    throw $error;
                }
            }
        }
    }

    private static function assignmentScope(int $tenantId, int $userId, int $runId, int $staffId, string $platform): array
    {
        if (min($tenantId, $userId, $runId, $staffId) <= 0 || !isset(self::MEDIA_BY_PLATFORM[$platform])) {
            throw new InvalidArgumentException('发布任务、员工或平台无效');
        }
        return [
            'tenant_id' => $tenantId, 'user_id' => $userId,
            'run_id' => $runId, 'staff_id' => $staffId, 'platform' => $platform,
        ];
    }

    private static function lockGroups(array $owner, array $groupIds): array
    {
        asort($groupIds, SORT_NUMERIC);
        $groups = [];
        foreach ($groupIds as $type => $id) {
            if ($id === 0) {
                $groups[$type] = ['id' => 0, 'name' => DefaultWorkGroup::NAME, 'max_uses' => 0, 'active' => true];
                continue;
            }
            $group = Db::name('work_group')->where($owner + ['type' => $type, 'id' => $id])
                ->field('name,max_uses,delete_time')->lock(true)->find();
            $groups[$type] = [
                'id' => $id,
                'name' => $group['name'] ?? ('成片组 #' . $id),
                'max_uses' => (int)($group['max_uses'] ?? 0),
                'active' => $group && $group['delete_time'] === null,
            ];
        }
        return $groups;
    }

    private static function pick(array $owner, string $type, array $group, PublishTaskMediaPool $media): ?array
    {
        if (!$group['active']) {
            return null;
        }
        $scope = $owner + ['type' => $type];
        $groupId = $group['id'];
        $isMedia = in_array($type, ['video', 'finished_image'], true);
        if ($groupId > 0) {
            // 与移组、分组删除使用相同的作品行锁，读取最新成员。
            $refs = Db::name('work_item')->where($scope + ['group_id' => $groupId])
                ->whereNull('delete_time')->order('id')->lock(true)->column('source_ref');
        } elseif ($isMedia) {
            $refs = $media->refs($type);
        } else {
            $query = $type === 'copy'
                ? MerchantCopy::where('user_id', $owner['user_id'])
                : MerchantTopic::where('user_id', $owner['user_id']);
            DefaultWorkGroup::filterTextList($query, $owner['tenant_id'], $owner['user_id'], $type);
            $refs = array_map(static fn($id) => 'merchant_' . $type . ':' . $id, $query->column('id'));
        }

        $refs = array_values(array_unique($refs));
        shuffle($refs);
        foreach ($refs as $ref) {
            if ($group['max_uses'] > 0 && self::atLimit($owner, $type, $ref, $group['max_uses'])) {
                continue;
            }
            $item = $isMedia ? $media->resolve($type, $ref) : self::text($owner, $type, $ref);
            if ($item === null) {
                continue;
            }
            if ($groupId === 0) {
                // 默认组没有组行，锁定该作品的归属以防同时移入其他组。
                $membership = Db::name('work_item')->where($owner + ['source_ref' => $ref])
                    ->field('group_id,delete_time')->lock(true)->find();
                if ($membership && ((int)$membership['group_id'] !== 0 || $membership['delete_time'] !== null)) {
                    continue;
                }
            }
            return $item;
        }
        return null;
    }

    private static function atLimit(array $owner, string $type, string $ref, int $limit): bool
    {
        // 锁定组后用当前读检查第 N 条记录，避免事务旧快照放过已用完的素材。
        return Db::name(self::TABLE)->where($owner + [$type . '_ref' => $ref])
            ->order('id')->limit($limit - 1, 1)->lock(true)->column('id') !== [];
    }

    private static function text(array $owner, string $type, string $ref): ?array
    {
        if (!preg_match('/^merchant_' . $type . ':([1-9][0-9]*)$/D', $ref, $match)) {
            return null;
        }
        $scope = ['user_id' => $owner['user_id'], 'id' => (int)$match[1]];
        if ($type === 'copy') {
            $item = MerchantCopy::where($scope)->field('name,content')->findOrEmpty();
            $text = trim((string)$item->content);
            $title = (string)$item->name;
        } else {
            $item = MerchantTopic::where($scope)->field('name')->findOrEmpty();
            $text = trim((string)$item->name);
            $title = '#' . $text;
        }
        return $item->isEmpty() || $text === '' ? null : ['ref' => $ref, 'title' => $title, 'text' => $text];
    }
}
