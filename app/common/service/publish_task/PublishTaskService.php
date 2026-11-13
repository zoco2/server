<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;
use app\common\model\tenant\Tenant;
use app\common\model\user\UserAuth;
use app\common\service\wechat\WeChatOaService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/** 管理当前租户用户的发布任务，由 MNS 消息逐轮触发执行。 */
final class PublishTaskService
{
    // 投递后长期未完成时，重新投递同一条执行记录。
    public const REDISPATCH_AFTER_SECONDS = 300;

    private const PLATFORMS = ['douyin', 'xiaohongshu', 'kuaishou'];
    private const MAX_STAFF = 50;

    /** 校验发布规则，保存任务和首轮执行时间。 */
    public static function create(int $tenantId, int $userId, array $input): array
    {
        $content = $input['content'] ?? null;
        if (!is_string($content) || !mb_check_encoding($content, 'UTF-8')
            || trim($content) === '' || mb_strlen(trim($content), 'UTF-8') > 500) {
            throw new InvalidArgumentException('任务内容必须为 1 至 500 个字符');
        }
        $platforms = $input['platforms'] ?? null;
        if (!is_array($platforms) || !array_is_list($platforms) || $platforms === [] || count($platforms) > 3) {
            throw new InvalidArgumentException('请从抖音、小红书、快手中选择发布平台');
        }
        foreach ($platforms as $platform) {
            if (!in_array($platform, self::PLATFORMS, true)) {
                throw new InvalidArgumentException('发布平台只支持抖音、小红书、快手');
            }
        }
        $rule = PublishTaskSchedule::normalize($input);
        $staffIds = array_key_exists('staff_ids', $input) ? self::staffIds($input['staff_ids']) : null;

        self::requireOwner($tenantId, $userId);
        if ($staffIds !== null) {
            $staff = self::staffQuery($tenantId, $userId)->whereIn('id', $staffIds)
                ->field('id,openid,appid')->select()->toArray();
            if (count($staff) !== count($staffIds)) {
                throw new InvalidArgumentException('所选员工不存在或不属于当前商家');
            }
            foreach ($staff as $employee) {
                if (trim((string)$employee['openid']) === '' || trim((string)$employee['appid']) === '') {
                    throw new InvalidArgumentException('所选员工中有未绑定服务号的员工');
                }
            }
        }

        // 从当前时间计算首轮，范围内没有可用时间时直接结束。
        $now = time();
        $nextRunAt = PublishTaskSchedule::first($rule, $now);
        $row = $rule + [
            'user_id' => $userId,
            'content' => trim($content),
            'platforms' => json_encode(array_values(array_unique($platforms)), JSON_THROW_ON_ERROR),
            'notice_type' => 'official_account',
            'status' => $nextRunAt === null ? 'ended' : 'scheduled',
            'next_run_at' => $nextRunAt,
            'last_run_at' => null,
            'last_run_status' => '',
            'last_error' => '',
            'create_time' => $now,
            'update_time' => $now,
        ];
        $ids = Db::transaction(static function () use ($tenantId, $row, $staffIds): array {
            // 模型保存会忽略缺失字段，先检查旧表是否具备完整的任务字段。
            $query = PublishTask::query();
            $table = $query->parseOptions()['table'];
            $fields = $query->getConnection()->getTableFields($table);
            if (array_diff([...array_keys($row), 'tenant_id', 'staff_id'], $fields) !== []) {
                throw new RuntimeException('发布任务表结构不完整');
            }
            $ids = [];
            foreach ($staffIds ?? [null] as $staffId) {
                $task = PublishTask::create($row + ['staff_id' => $staffId]);
                $id = (int)$task->id;
                if ($id <= 0 || (int)$task->tenant_id !== $tenantId) {
                    throw new RuntimeException('任务保存失败或租户上下文无效');
                }
                $ids[] = $id;
            }
            return $ids;
        });
        if ($staffIds !== null) {
            return ['ids' => $ids, 'count' => count($ids)];
        }
        return self::present($row + ['id' => $ids[0]]);
    }

    /** 按创建顺序倒序分页，仅返回当前租户下当前用户的任务。 */
    public static function lists(int $tenantId, int $userId, int $page, int $size): array
    {
        self::requireOwner($tenantId, $userId);
        $page = max(1, $page);
        $size = max(1, min(100, $size));
        $count = PublishTask::where('user_id', $userId)->count();
        $tasks = PublishTask::where('user_id', $userId)
            ->order('id desc')->page($page, $size)->select()->toArray();
        return [
            'count' => $count,
            'lists' => array_map(self::present(...), $tasks),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 复查到期记录的租户和分表，再保存并投递执行记录。 */
    public static function dispatchDue(int $id, int $tenantId, int $userId, string $sourceTable): void
    {
        self::withOwnerLock($tenantId, $userId, static function () use ($id, $tenantId, $userId, $sourceTable): void {
            // 只复查有待投递任务的租户，停用或已删除的租户暂停投递。
            if (!Tenant::where(['id' => $tenantId, 'disable' => 0])->value('id')) {
                return;
            }
            // 分表的任务 ID 可以重复，确认候选记录来自模型当前路由的表。
            if (PublishTask::query()->parseOptions()['table'] !== $sourceTable) {
                return;
            }
            $now = time();
            $run = PublishTaskRun::where(['user_id' => $userId, 'status' => 'running'])->findOrEmpty();
            if ($run->isEmpty()) {
                $task = PublishTask::where(['id' => $id, 'user_id' => $userId, 'status' => 'scheduled'])
                    ->where('next_run_at', '<=', $now)->findOrEmpty();
                if ($task->isEmpty()) {
                    return;
                }
                $run = PublishTaskRun::create([
                    'user_id' => $userId,
                    'task_id' => $id,
                    'scheduled_at' => (int)$task->next_run_at,
                    'status' => 'running',
                    'dispatched_at' => null,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                if ((int)$run->id <= 0 || (int)$run->tenant_id !== $tenantId) {
                    throw new RuntimeException('发布任务执行记录保存失败');
                }
            } elseif ((int)$run->task_id !== $id
                || (int)$run->dispatched_at > $now - self::REDISPATCH_AFTER_SECONDS) {
                return;
            }

            // 失败时保留原执行记录，下一次扫描仍投递同一个 ID。
            PublishTaskJob::dispatch(['run_id' => (int)$run->id, 'user_id' => $userId]);
            PublishTaskRun::where(['id' => (int)$run->id, 'user_id' => $userId])->update([
                'dispatched_at' => time(),
                'update_time' => time(),
            ]);
        }, 0);
    }

    /** 消费执行记录，发送结果与下一次执行时间一并提交。 */
    public static function consume(int $runId, int $tenantId, int $userId): void
    {
        self::executeRun($runId, $tenantId, $userId, false);
    }

    /** MNS 重试耗尽后结束本轮，让同一用户的其他任务继续调度。 */
    public static function abandon(int $runId, int $tenantId, int $userId): void
    {
        self::executeRun($runId, $tenantId, $userId, true);
    }

    private static function executeRun(int $runId, int $tenantId, int $userId, bool $failed): void
    {
        self::withOwnerLock($tenantId, $userId, static function () use ($runId, $tenantId, $userId, $failed): void {
            $runWhere = ['id' => $runId, 'user_id' => $userId, 'status' => 'running'];
            $run = PublishTaskRun::where($runWhere)->findOrEmpty();
            if ($run->isEmpty()) {
                return;
            }

            $scheduledAt = (int)$run->scheduled_at;
            $taskWhere = [
                'id' => (int)$run->task_id,
                'user_id' => $userId,
                'status' => 'scheduled',
                'next_run_at' => $scheduledAt,
            ];
            $record = PublishTask::where($taskWhere)->findOrEmpty();
            if ($record->isEmpty()) {
                PublishTaskRun::where($runWhere)->update(['status' => 'skipped', 'update_time' => time()]);
                return;
            }

            $task = $record->getData();
            $now = time();
            if ($scheduledAt > $now) {
                throw new RuntimeException('发布任务尚未到执行时间');
            }

            if ((int)$task['last_run_at'] === $scheduledAt && $task['last_run_status'] === 'sending') {
                $result = ['last_run_status' => 'unknown', 'last_error' => '发送进程中断，无法确认微信是否接收'];
            } elseif (PublishTaskSchedule::hasEnded($task, $now)) {
                $result = ['last_run_status' => 'skipped', 'last_error' => '任务已超过结束日期'];
            } elseif ($failed) {
                $result = ['last_run_status' => 'failed', 'last_error' => 'MNS 消费重试耗尽'];
            } else {
                $result = self::sendRun($taskWhere, $task, $tenantId, $userId, $scheduledAt, $now);
            }

            $finishedAt = time();
            $nextRunAt = PublishTaskSchedule::next($task, max($scheduledAt + 1, $finishedAt));
            $taskStatus = $nextRunAt !== null ? 'scheduled' : 'ended';
            if ($taskStatus === 'ended' && $failed && $result['last_run_status'] === 'failed') {
                $taskStatus = 'failed';
            }
            Db::transaction(static function () use ($runWhere, $taskWhere, $scheduledAt, $result, $finishedAt, $nextRunAt, $taskStatus): void {
                PublishTaskRun::where($runWhere)->update([
                    'status' => $result['last_run_status'],
                    'update_time' => $finishedAt,
                ]);
                PublishTask::where($taskWhere)->update($result + [
                    'status' => $taskStatus,
                    'next_run_at' => $nextRunAt,
                    'last_run_at' => $scheduledAt,
                    'update_time' => $finishedAt,
                ]);
            });
        });
    }

    /** 发送前写入检查点，避免消费重试时重复通知。 */
    private static function sendRun(array $where, array $task, int $tenantId, int $userId, int $scheduledAt, int $now): array
    {
        $appId = null;
        if (isset($task['staff_id'])) {
            // 员工可以在任务创建后解绑或删除，发送前重新检查。
            $staff = self::staffQuery($tenantId, $userId)->where('id', (int)$task['staff_id'])
                ->field('openid,appid')->findOrEmpty();
            $openid = trim((string)($staff['openid'] ?? ''));
            $appId = trim((string)($staff['appid'] ?? ''));
        } else {
            $openid = trim((string)UserAuth::where([
                'user_id' => $userId,
                'terminal' => UserTerminalEnum::WECHAT_OA,
            ])->order('id desc')->value('openid'));
        }

        PublishTask::where($where)->update([
            'last_run_at' => $scheduledAt,
            'last_run_status' => 'sending',
            'last_error' => '',
            'update_time' => $now,
        ]);
        return self::notify((int)$task['id'], $tenantId, $userId, $openid, $appId);
    }

    /**
     * 尝试发送服务号文本并返回本轮结果，供调用方保存。
     *
     * @return array{last_run_status: string, last_error: string}
     */
    private static function notify(int $id, int $tenantId, int $userId, string $openid, ?string $appId): array
    {
        if ($openid === '' || $appId === '') {
            return [
                'last_run_status' => 'failed',
                'last_error' => $appId === null ? '用户未绑定服务号' : '员工已删除或未绑定服务号',
            ];
        }
        try {
            (new WeChatOaService())->sendCustomText($openid, 'hello', $appId);
            return ['last_run_status' => 'sent', 'last_error' => ''];
        } catch (Throwable $e) {
            // 请求异常可能包含带 access_token 的 URL，只记录异常类型和错误码。
            Log::channel('mns')->warning(sprintf('[publish_task] WeChat send failed id=%d tenant=%d user=%d type=%s code=%d',
                $id, $tenantId, $userId, get_class($e), (int)$e->getCode()));
            return ['last_run_status' => 'failed', 'last_error' => $e->getCode()
                ? '服务号通知发送失败（错误码 ' . $e->getCode() . '）' : '服务号通知发送失败，请检查配置及用户互动权限'];
        }
    }

    /** 在默认数据库连接上持有锁，让同一租户下同一用户的创建和消费串行执行。 */
    private static function withOwnerLock(int $tenantId, int $userId, callable $action, int $wait = 10): mixed
    {
        self::requireOwner($tenantId, $userId);
        $db = Db::connect();
        // MySQL 命名锁在同一实例内共享，锁名加入库名和表前缀以区分不同部署。
        $lock = 'publish_task:' . sha1($db->getConfig('database') . ':' . $db->getConfig('prefix') . ':' . $tenantId . ':' . $userId);
        if ((int)($db->query('SELECT GET_LOCK(?, ?) AS acquired', [$lock, $wait], true)[0]['acquired'] ?? 0) !== 1) {
            if ($wait === 0) {
                return null;
            }
            throw new RuntimeException('该用户的发布任务正在处理，请稍后重试');
        }
        try {
            return $action();
        } finally {
            try {
                $db->query('SELECT RELEASE_LOCK(?)', [$lock], true);
            } catch (Throwable $e) {
                // 显式释放失败时关闭连接，避免常驻消费者继续占用这把锁。
                $db->close();
                Log::channel('mns')->warning('[publish_task] owner lock release failed: ' . get_class($e));
            }
        }
    }

    /** 拒绝缺少有效租户或用户标识的请求和队列消息。 */
    private static function requireOwner(int $tenantId, int $userId): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $tenantId !== (int)(request()->tenantId ?? 0)) {
            throw new InvalidArgumentException('缺少租户或登录用户');
        }
        $query = PublishTask::query();
        // 任务表必须有租户列，BaseModel 才能限定查询范围。
        if (!in_array('tenant_id', $query->getConnection()->getTableFields($query->getTable()), true)) {
            throw new RuntimeException('发布任务表缺少租户字段');
        }
    }

    /** 员工档案只允许当前租户下的创建用户查询，后台消费也使用相同范围。 */
    private static function staffQuery(int $tenantId, int $userId): \think\db\Query
    {
        return MerchantStaff::withoutGlobalScope()
            ->where(['tenant_id' => $tenantId, 'user_id' => $userId])
            ->whereNull('delete_time');
    }

    /** 校验批量请求并去重，避免重复给同一员工创建任务。 */
    private static function staffIds(mixed $staffIds): array
    {
        if (!is_array($staffIds) || !array_is_list($staffIds) || $staffIds === [] || count($staffIds) > self::MAX_STAFF) {
            throw new InvalidArgumentException('请选择 1 至 50 名员工');
        }
        $clean = [];
        foreach ($staffIds as $staffId) {
            // 表单使用字符串传输员工 ID，仅接受不带前导零的十进制正整数。
            $value = $staffId;
            if (is_string($staffId) && preg_match('/^[1-9][0-9]*$/D', $staffId)) {
                $value = filter_var($staffId, FILTER_VALIDATE_INT);
            }
            if (!is_int($value) || $value <= 0) {
                throw new InvalidArgumentException('员工 ID 无效');
            }
            $clean[] = $value;
        }
        return array_values(array_unique($clean));
    }

    /** 将数据库记录转换成接口数据，隐藏内部标识并统一数值字段类型。 */
    private static function present(array $row): array
    {
        unset($row['tenant_id'], $row['user_id']);
        if (isset($row['staff_id'])) {
            $row['staff_id'] = (int)$row['staff_id'];
        } else {
            unset($row['staff_id']);
        }
        $row['platforms'] = json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'weekday', 'next_run_at', 'last_run_at', 'create_time', 'update_time'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        return $row + ['timezone' => PublishTaskSchedule::TIMEZONE];
    }
}
