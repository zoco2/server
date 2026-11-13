<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;
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
    private const PLATFORMS = ['douyin', 'xiaohongshu', 'kuaishou'];
    private const MAX_STAFF = 50;

    /** 保存计划和首轮执行时间、编号。 */
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
            'staff_ids' => $staffIds === null ? null : json_encode($staffIds, JSON_THROW_ON_ERROR),
            'content' => trim($content),
            'platforms' => json_encode(array_values(array_unique($platforms)), JSON_THROW_ON_ERROR),
            'notice_type' => 'official_account',
            'status' => $nextRunAt === null ? 'ended' : 'scheduled',
            'next_run_at' => $nextRunAt,
            'tasksn' => $nextRunAt === null ? null : bin2hex(random_bytes(16)),
            'last_run_at' => null,
            'last_run_status' => '',
            'last_error' => '',
            'create_time' => $now,
            'update_time' => $now,
        ];
        $id = Db::transaction(static function () use ($tenantId, $row): int {
            // 模型保存会忽略缺失字段，先检查旧表是否具备完整的任务字段。
            $query = PublishTask::query();
            $fields = $query->getConnection()->getTableFields($query->getTable());
            if (array_diff([...array_keys($row), 'tenant_id'], $fields) !== []) {
                throw new RuntimeException('发布任务表结构不完整');
            }
            $task = PublishTask::create($row);
            if ((int)$task->id <= 0 || (int)$task->tenant_id !== $tenantId) {
                throw new RuntimeException('任务保存失败或租户上下文无效');
            }
            return (int)$task->id;
        });
        if ($staffIds !== null) {
            // count 表示员工人数，ids 只返回本次发布的任务 ID。
            return ['ids' => [$id], 'count' => count($staffIds)];
        }
        return self::present($row + ['id' => $id]);
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

    /** 创建到期计划的执行记录并投递 Job。 */
    public static function dispatchDue(int $id, int $tenantId, int $userId): void
    {
        self::withOwnerLock($tenantId, $userId, static function () use ($id, $tenantId, $userId): void {
            $now = time();
            $task = PublishTask::where(['id' => $id, 'user_id' => $userId, 'status' => 'scheduled'])
                ->whereNotNull('tasksn')->where('next_run_at', '<=', $now)->findOrEmpty();
            if ($task->isEmpty()) {
                return;
            }

            // 取得用户锁后复查，避免并发扫描重复投递或同时执行同一用户的任务。
            if (PublishTaskRun::where('tasksn', (string)$task->tasksn)->value('id')
                || PublishTaskRun::where('user_id', $userId)->whereIn('status', ['pending', 'running'])->value('id')) {
                return;
            }

            // 投递失败时回滚记录，下次扫描可以重试。Job 取得同一把用户锁后才读记录。
            Db::transaction(static function () use ($task, $id, $tenantId, $userId, $now): void {
                $run = PublishTaskRun::create([
                    'user_id' => $userId,
                    'task_id' => $id,
                    'tasksn' => (string)$task->tasksn,
                    'scheduled_at' => (int)$task->next_run_at,
                    'status' => 'pending',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                if ((int)$run->id <= 0 || (int)$run->tenant_id !== $tenantId) {
                    throw new RuntimeException('发布任务执行记录保存失败');
                }
                PublishTaskJob::dispatch(['run_id' => (int)$run->id, 'user_id' => $userId]);
            });
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
            $runWhere = ['id' => $runId, 'user_id' => $userId];
            $run = PublishTaskRun::where($runWhere)->whereIn('status', ['pending', 'running'])->findOrEmpty();
            if ($run->isEmpty()) {
                return;
            }
            // Job 开始处理时才进入 running，重试沿用同一条记录。
            $runWhere['status'] = (string)$run->status;
            if (!$failed && $runWhere['status'] === 'pending') {
                PublishTaskRun::where($runWhere)->update(['status' => 'running', 'update_time' => time()]);
                $runWhere['status'] = 'running';
            }

            $scheduledAt = (int)$run->scheduled_at;
            $taskWhere = [
                'id' => (int)$run->task_id,
                'user_id' => $userId,
                'status' => 'scheduled',
                'tasksn' => (string)$run->tasksn,
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

            $results = json_decode((string)($run->recipient_results ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
            // 中断时只有正在发送的员工结果未知，已记录的结果继续保留。
            foreach ($results as &$recipient) {
                if ($recipient['last_run_status'] === 'sending') {
                    $recipient = ['last_run_status' => 'unknown', 'last_error' => '发送进程中断，无法确认微信是否接收'];
                }
            }
            unset($recipient);

            if (PublishTaskSchedule::hasEnded($task, $now)) {
                $result = ['last_run_status' => 'skipped', 'last_error' => '任务已超过结束日期'];
            } elseif ($failed) {
                $result = ['last_run_status' => 'failed', 'last_error' => 'MNS 消费重试耗尽'];
            } else {
                $results = self::sendRun($runWhere, $task, $tenantId, $userId, $results);
                $result = ['last_run_status' => 'sent', 'last_error' => ''];
                foreach ($results as $recipient) {
                    if ($recipient['last_run_status'] !== 'sent') {
                        $result = $recipient;
                        if ($result['last_run_status'] === 'unknown') {
                            break;
                        }
                    }
                }
            }

            $finishedAt = time();
            $nextRunAt = PublishTaskSchedule::next($task, max($scheduledAt + 1, $finishedAt));
            $runStatus = match (true) {
                $result['last_run_status'] === 'skipped' => 'skipped',
                $failed => 'failed',
                default => 'completed',
            };
            $taskStatus = $nextRunAt !== null ? 'scheduled' : ($runStatus === 'failed' ? 'failed' : 'ended');
            // 本轮结束与下一轮排期一起提交，避免扫描到未更新的计划。
            Db::transaction(static function () use ($runWhere, $taskWhere, $scheduledAt, $result, $results, $finishedAt, $nextRunAt, $taskStatus, $runStatus): void {
                PublishTaskRun::where($runWhere)->update([
                    'status' => $runStatus,
                    'recipient_results' => json_encode((object)$results, JSON_THROW_ON_ERROR),
                    'update_time' => $finishedAt,
                ]);
                PublishTask::where($taskWhere)->update($result + [
                    'status' => $taskStatus,
                    'next_run_at' => $nextRunAt,
                    'tasksn' => $nextRunAt === null ? null : bin2hex(random_bytes(16)),
                    'last_run_at' => $scheduledAt,
                    'update_time' => $finishedAt,
                ]);
            });
        });
    }

    /** 同一轮逐个发送。记录每次发送的进度，重试时只继续处理未发送的人。 */
    private static function sendRun(array $runWhere, array $task, int $tenantId, int $userId, array $results): array
    {
        if (isset($task['staff_ids'])) {
            $staffIds = json_decode($task['staff_ids'], true, flags: JSON_THROW_ON_ERROR);
            $staff = self::staffQuery($tenantId, $userId)->whereIn('id', $staffIds)
                ->column('openid,appid', 'id');
        } else {
            // 键 0 表示通知创建人，员工键使用实际的员工 ID。
            $staffIds = [0];
            $openid = trim((string)UserAuth::where([
                'user_id' => $userId,
                'terminal' => UserTerminalEnum::WECHAT_OA,
            ])->order('id desc')->value('openid'));
            $staff = [0 => ['openid' => $openid, 'appid' => null]];
        }

        foreach ($staffIds as $staffId) {
            if (isset($results[$staffId])) {
                continue;
            }
            $openid = trim((string)($staff[$staffId]['openid'] ?? ''));
            $appId = $staffId === 0 ? null : trim((string)($staff[$staffId]['appid'] ?? ''));

            // 发送前保存状态，进程中断后可以识别可能已发送的通知。
            $results[$staffId] = ['last_run_status' => 'sending', 'last_error' => ''];
            self::saveRecipientResults($runWhere, $results);
            $results[$staffId] = self::notify((int)$task['id'], $tenantId, $userId, $openid, $appId);
            self::saveRecipientResults($runWhere, $results);
        }
        return $results;
    }

    /** 将收件人结果保存在本轮执行记录里。 */
    private static function saveRecipientResults(array $runWhere, array $results): void
    {
        PublishTaskRun::where($runWhere)->update([
            'recipient_results' => json_encode((object)$results, JSON_THROW_ON_ERROR),
            'update_time' => time(),
        ]);
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
        unset($row['tenant_id'], $row['user_id'], $row['tasksn']);
        $row['staff_ids'] = $row['staff_ids'] === null
            ? [] : json_decode($row['staff_ids'], true, flags: JSON_THROW_ON_ERROR);
        $row['platforms'] = json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'weekday', 'next_run_at', 'last_run_at', 'create_time', 'update_time'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        return $row + ['timezone' => PublishTaskSchedule::TIMEZONE];
    }
}
