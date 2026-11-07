<?php

namespace app\common\service\smart_edit;

use app\common\job\SmartEditGroupExpandJob;
use app\common\model\smart_edit\SmartEditGroup;
use app\common\model\smart_edit\SmartEditTask;
use Exception;
use think\facade\Log;

/**
 * 镜头组：镜头定义、笛卡尔积展开、组列表与详情。
 *
 * 组本身不提交 IMS，也不轮询；它只是「批量建任务」的容器。
 * 每个组合落到 la_smart_edit_task，batch_no 指回本组，之后完全复用现有提交 / 轮询链路。
 */
class SmartEditGroupService
{
    /** 单组最多展开的组合数 */
    public const MAX_COMBOS_PER_GROUP = 2000;

    /** 单个组最多镜头数 */
    public const MAX_SHOTS = 10;

    /** 单个镜头最多视频数 */
    public const MAX_VIDEOS_PER_SHOT = 20;

    /**
     * 建组：校验镜头、算组合数、落组行，投递展开 job 后立即返回。
     *
     * 展开可能产生上千条任务，放在请求里会超时，所以只落组行就返回。
     *
     * @param int   $tenantId 租户 ID
     * @param int   $userId   用户 ID
     * @param array $params   组参数
     * @return array 组行（已格式化）
     */
    public static function create(int $tenantId, int $userId, array $params): array
    {
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            $title = '镜头组';
        }
        $title = mb_substr($title, 0, 100);

        $shots = self::normalizeShots($params['shots'] ?? []);
        $comboCount = self::countCombos($shots);

        // 共用配置走与单任务完全相同的归一化，保证两边语义一致
        $shared = SmartEditService::normalizeSharedForGroup($params);
        // 提前解析素材地址，配置缺失或素材不归属当前租户时在请求里就报错，
        // 不把问题留给后台展开任务。
        SmartEditService::assertSharedResolvable($tenantId, $shared);

        $batchNo = self::newBatchNo();
        $group = SmartEditGroup::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'batch_no' => $batchNo,
            'title' => $title,
            'shots_json' => $shots,
            'config_json' => $shared,
            'combo_count' => $comboCount,
            'status' => SmartEditGroup::STATUS_EXPANDING,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        try {
            SmartEditGroupExpandJob::dispatch(['batch_no' => $batchNo], 0, '', $tenantId);
            Log::channel('mns')->info(sprintf(
                '[smart_edit] group dispatched batch=%s tenant=%d combos=%d',
                $batchNo,
                $tenantId,
                $comboCount
            ));
        } catch (\Throwable $e) {
            // 投递失败必须留痕：常见原因是 MNS 未配置或队列不存在
            Log::channel('mns')->error(sprintf(
                '[smart_edit] group dispatch failed batch=%s tenant=%d %s',
                $batchNo,
                $tenantId,
                $e->getMessage()
            ));
            $group->save([
                'status' => SmartEditGroup::STATUS_FAILED,
                'error_code' => 'DISPATCH_FAILED',
                'error_message' => mb_substr('任务投递失败：' . $e->getMessage(), 0, 480),
                'update_time' => time(),
            ]);
        }

        return self::formatGroup($group->refresh()->toArray());
    }

    /**
     * 组分页列表，附各组的成片进度聚合。
     *
     * @param int   $tenantId 租户 ID
     * @param int   $userId   用户 ID
     * @param array $query    page_no / page_size / status
     * @return array{lists: array, count: int, page_no: int, page_size: int}
     */
    public static function lists(int $tenantId, int $userId, array $query = []): array
    {
        $pageNo = max(1, (int)($query['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($query['page_size'] ?? 20)));

        $builder = SmartEditGroup::where('tenant_id', $tenantId)->where('user_id', $userId);
        $status = trim((string)($query['status'] ?? ''));
        if ($status !== '') {
            $builder->where('status', $status);
        }

        $count = (clone $builder)->count();
        $rows = $builder
            ->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        $progress = self::aggregateStatus($tenantId, array_column($rows, 'batch_no'));
        $lists = array_map(
            static fn(array $row): array => self::formatGroup($row, $progress[(string)$row['batch_no']] ?? []),
            $rows
        );

        return [
            'lists' => $lists,
            'count' => (int)$count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 组详情 + 子任务分页。
     *
     * @param int    $tenantId 租户 ID
     * @param int    $userId   用户 ID
     * @param string $batchNo  组号
     * @param int    $pageNo   页码
     * @param int    $pageSize 每页条数
     * @return array
     */
    public static function detail(int $tenantId, int $userId, string $batchNo, int $pageNo = 1, int $pageSize = 20): array
    {
        $group = self::findOwned($tenantId, $userId, $batchNo);

        $pageNo = max(1, $pageNo);
        $pageSize = min(100, max(1, $pageSize));
        $query = SmartEditTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('batch_no', $batchNo);

        $count = (clone $query)->count();
        $tasks = $query
            ->order('combo_index', 'asc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        return [
            'group' => self::formatGroup(
                $group->toArray(),
                self::aggregateStatus($tenantId, [$batchNo])[$batchNo] ?? []
            ),
            'tasks' => SmartEditService::decorateRows($tasks),
            'count' => (int)$count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 删除组，并连带软删该组下的全部任务。
     *
     * @param int    $tenantId 租户 ID
     * @param int    $userId   用户 ID
     * @param string $batchNo  组号
     */
    public static function delete(int $tenantId, int $userId, string $batchNo): void
    {
        $group = self::findOwned($tenantId, $userId, $batchNo);
        $group->delete();

        // 子任务逐个走模型删除，保证 delete_time 与全局作用域行为一致
        $tasks = SmartEditTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('batch_no', $batchNo)
            ->select();
        foreach ($tasks as $task) {
            $task->delete();
        }
    }

    /**
     * 按组号取组，校验归属。
     *
     * @param int    $tenantId 租户 ID
     * @param int    $userId   用户 ID
     * @param string $batchNo  组号
     * @return SmartEditGroup
     * @throws Exception 组不存在或不属于当前用户
     */
    public static function findOwned(int $tenantId, int $userId, string $batchNo): SmartEditGroup
    {
        $batchNo = trim($batchNo);
        if ($batchNo === '') {
            throw new Exception('缺少组号');
        }
        $group = SmartEditGroup::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('batch_no', $batchNo)
            ->findOrEmpty();
        if ($group->isEmpty()) {
            throw new Exception('组不存在');
        }
        return $group;
    }

    /**
     * 镜头定义归一化并校验。
     *
     * @param mixed $raw 前端传入的 shots
     * @return array<int, array{videos: array<int, array{uri: string, in: float, out: float, mute: bool}>}>
     * @throws Exception 镜头或视频不合法
     */
    public static function normalizeShots($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new Exception('至少需要 2 个镜头');
        }
        if (count($raw) > self::MAX_SHOTS) {
            throw new Exception('最多支持 ' . self::MAX_SHOTS . ' 个镜头');
        }
        if (count($raw) < 2) {
            // 单个组合的片段数等于镜头数，合并至少需要 2 段视频
            throw new Exception('至少需要 2 个镜头');
        }

        $shots = [];
        foreach (array_values($raw) as $index => $shot) {
            $no = $index + 1;
            $videos = is_array($shot) ? ($shot['videos'] ?? $shot) : [];
            if (!is_array($videos) || $videos === []) {
                throw new Exception('第 ' . $no . ' 个镜头还没有视频');
            }
            if (count($videos) > self::MAX_VIDEOS_PER_SHOT) {
                throw new Exception('第 ' . $no . ' 个镜头最多 ' . self::MAX_VIDEOS_PER_SHOT . ' 条视频');
            }

            $normalized = [];
            foreach (array_values($videos) as $video) {
                if (!is_array($video) || trim((string)($video['uri'] ?? '')) === '') {
                    throw new Exception('第 ' . $no . ' 个镜头存在空的素材地址');
                }
                $in = (float)($video['in'] ?? 0);
                $out = (float)($video['out'] ?? 0);
                if ($in < 0 || $out < 0) {
                    throw new Exception('截取区间不能为负数');
                }
                if ($out > 0 && $out <= $in) {
                    throw new Exception('截取结束时间必须大于开始时间');
                }
                $normalized[] = [
                    'uri' => (string)$video['uri'],
                    'in' => $in,
                    'out' => $out,
                    'mute' => !empty($video['mute']),
                ];
            }
            $shots[] = ['videos' => $normalized];
        }

        $combos = self::countCombos($shots);
        if ($combos > self::MAX_COMBOS_PER_GROUP) {
            throw new Exception('组合数 ' . $combos . ' 超过上限 ' . self::MAX_COMBOS_PER_GROUP . '，请减少镜头或视频');
        }
        return $shots;
    }

    /**
     * 组合总数 = 各镜头视频数连乘。
     *
     * @param array $shots 归一化后的镜头
     * @return int
     */
    public static function countCombos(array $shots): int
    {
        if ($shots === []) {
            return 0;
        }
        $total = 1;
        foreach ($shots as $shot) {
            $total *= max(1, count($shot['videos'] ?? []));
        }
        return $total;
    }

    /**
     * 笛卡尔积：每个镜头取一条视频，按镜头顺序拼成 clips。
     *
     * 顺序固定为「最后一个镜头变化最快」，combo_index 从 1 起，
     * 与前端展示的组合序号一一对应。
     *
     * @param array $shots 归一化后的镜头
     * @return array<int, array<int, array{uri: string, in: float, out: float, mute: bool}>> 每个元素是一组 clips
     */
    public static function expandShots(array $shots): array
    {
        $result = [[]];
        foreach ($shots as $shot) {
            $videos = $shot['videos'] ?? [];
            if ($videos === []) {
                continue;
            }
            $next = [];
            foreach ($result as $prefix) {
                foreach ($videos as $video) {
                    $next[] = array_merge($prefix, [$video]);
                }
            }
            $result = $next;
        }
        return $result;
    }

    /**
     * 成片进度聚合：一次分组查询，避免逐个组查。
     *
     * @param int   $tenantId 租户 ID
     * @param array $batchNos 组号列表
     * @return array<string, array{total: int, success: int, failed: int, running: int}>
     */
    public static function aggregateStatus(int $tenantId, array $batchNos): array
    {
        $batchNos = array_values(array_filter(array_map('strval', $batchNos), static fn($v) => $v !== ''));
        if ($batchNos === []) {
            return [];
        }

        $rows = SmartEditTask::where('tenant_id', $tenantId)
            ->whereIn('batch_no', $batchNos)
            ->field('batch_no, status, COUNT(*) AS total')
            ->group('batch_no, status')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $batchNo = (string)$row['batch_no'];
            $total = (int)$row['total'];
            $status = (string)$row['status'];
            $result[$batchNo] ??= ['total' => 0, 'success' => 0, 'failed' => 0, 'running' => 0];
            $result[$batchNo]['total'] += $total;
            if ($status === SmartEditTask::STATUS_SUCCESS) {
                $result[$batchNo]['success'] += $total;
            } elseif ($status === SmartEditTask::STATUS_FAILED) {
                $result[$batchNo]['failed'] += $total;
            } elseif (in_array($status, SmartEditTask::RUNNING_STATUSES, true)) {
                $result[$batchNo]['running'] += $total;
            }
            // canceled 记入 total，但不计入三类，前端按 total - 其余 展示
        }
        return $result;
    }

    /**
     * 组行补上进度与状态文案。
     *
     * @param array $row      组行
     * @param array $progress 聚合结果
     * @return array
     */
    public static function formatGroup(array $row, array $progress = []): array
    {
        $success = (int)($progress['success'] ?? 0);
        $failed = (int)($progress['failed'] ?? 0);
        $running = (int)($progress['running'] ?? 0);
        $expanded = (int)($progress['total'] ?? 0);
        $row['success_count'] = $success;
        $row['failed_count'] = $failed;
        $row['running_count'] = $running;
        $row['expanded_count'] = $expanded;
        $row['status_text'] = self::statusText((string)($row['status'] ?? ''), $expanded, $success, $failed, $running);
        return $row;
    }

    /**
     * 组状态文案。展开阶段的组，用子任务进度描述更直观。
     */
    private static function statusText(string $status, int $expanded, int $success, int $failed, int $running): string
    {
        if ($status === SmartEditGroup::STATUS_EXPANDING) {
            return '生成任务中';
        }
        if ($status === SmartEditGroup::STATUS_FAILED) {
            return '生成失败';
        }
        if ($expanded === 0) {
            return '等待提交';
        }
        if ($running > 0) {
            return '剪辑中';
        }
        if ($failed === 0) {
            return '全部完成';
        }
        return $success > 0 ? '部分失败' : '全部失败';
    }

    /**
     * 组号：SB + 秒级时间 + 6 位随机十六进制，配合唯一键防重。
     */
    public static function newBatchNo(): string
    {
        return 'SB' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}
