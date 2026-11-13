<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\api\logic\merchant\StaffWechatBindingLogic;
use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use app\common\model\merchant\PublishTask;
use app\common\model\merchant\PublishTaskRun;
use app\common\model\tenant\Tenant;
use app\common\model\user\UserAuth;
use app\common\service\tenant\TenantUrlService;
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
    private const PROGRESS_CONNECTION = 'publish_task_progress';

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
        $tasks = array_map(self::present(...), $tasks);
        $staff = self::staffDetails($tenantId, $userId, array_merge(...array_column($tasks, 'staff_ids')));
        foreach ($tasks as &$task) {
            $task['staff'] = self::selectStaff($staff, $task['staff_ids']);
        }
        unset($task);

        return [
            'count' => $count,
            'lists' => $tasks,
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 按最近创建顺序返回当前用户指定计划的执行记录。 */
    public static function runs(int $tenantId, int $userId, int $taskId, int $page, int $size): array
    {
        self::requireOwner($tenantId, $userId);
        if ($taskId <= 0) {
            throw new InvalidArgumentException('发布计划 ID 无效');
        }
        $task = PublishTask::where(['id' => $taskId, 'user_id' => $userId])->field('id,staff_ids')->findOrEmpty();
        if ($task->isEmpty()) {
            throw new InvalidArgumentException('发布计划不存在或无权查看');
        }

        $page = max(1, $page);
        $size = max(1, min(100, $size));
        $where = ['task_id' => $taskId, 'user_id' => $userId];
        $count = PublishTaskRun::where($where)->count();
        $runs = PublishTaskRun::where($where)->order('id desc')->page($page, $size)->select()->toArray();
        $plannedStaff = json_decode($task->staff_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $staffIds = $plannedStaff;
        foreach ($runs as &$run) {
            unset($run['tenant_id'], $run['user_id']);
            foreach (['id', 'task_id', 'scheduled_at', 'create_time', 'update_time'] as $key) {
                $run[$key] = (int)$run[$key];
            }
            // 接收人按员工 ID 索引，键 0 表示创建计划的用户。
            $results = json_decode($run['recipient_results'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $run['recipient_results'] = (object)$results;
            $staffIds = array_merge($staffIds, array_keys($results));
        }
        unset($run);

        $staff = self::staffDetails($tenantId, $userId, $staffIds);
        foreach ($runs as &$run) {
            $run['staff'] = self::selectStaff($staff, array_unique([...$plannedStaff, ...array_keys((array)$run['recipient_results'])]));
        }
        unset($run);
        return [
            'count' => $count,
            'lists' => $runs,
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 暂停当前计划，或从现在重新计算恢复后的排期。 */
    public static function setPaused(int $tenantId, int $userId, int $taskId, bool $paused): void
    {
        self::requireOwner($tenantId, $userId);
        if ($taskId <= 0) {
            throw new InvalidArgumentException('发布计划 ID 无效');
        }
        Db::transaction(static function () use ($userId, $taskId, $paused): void {
            $where = ['id' => $taskId, 'user_id' => $userId];
            $task = PublishTask::where($where)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new InvalidArgumentException('发布计划不存在或无权操作');
            }
            $status = (string)$task->status;
            if ($status === ($paused ? 'paused' : 'scheduled')) {
                return;
            }
            if ($status !== ($paused ? 'scheduled' : 'paused')) {
                throw new InvalidArgumentException('当前状态无法' . ($paused ? '暂停' : '恢复') . '计划');
            }

            $now = time();
            $nextRunAt = null;
            if ($paused) {
                // 消费端锁住同一条计划，正在重试的轮次也要等它处理完。
                $runWhere = ['task_id' => $taskId, 'user_id' => $userId, 'tasksn' => (string)$task->tasksn];
                if (PublishTaskRun::where($runWhere)->value('status') === 'running') {
                    throw new InvalidArgumentException('本轮任务尚在处理中，请稍后暂停');
                }
                PublishTaskRun::where($runWhere)->where('status', 'pending')
                    ->update(['status' => 'skipped', 'update_time' => $now]);
            } else {
                $nextRunAt = PublishTaskSchedule::first($task->getData(), max($now, (int)$task->last_run_at + 1));
            }

            PublishTask::where($where)->update([
                'status' => $paused ? 'paused' : ($nextRunAt === null ? 'ended' : 'scheduled'),
                'next_run_at' => $nextRunAt,
                'tasksn' => $nextRunAt === null ? null : bin2hex(random_bytes(16)),
                'update_time' => $now,
            ]);
        });
    }

    /** 创建到期计划的执行记录并投递 Job。 */
    public static function dispatchDue(int $id, int $tenantId, int $userId, string $taskSn): void
    {
        self::requireOwner($tenantId, $userId);
        // 已入队的轮次直接跳过，避免扫描等待正在执行的计划。
        if (PublishTaskRun::where('tasksn', $taskSn)->master()->value('id')) {
            return;
        }

        Db::transaction(static function () use ($id, $tenantId, $userId, $taskSn): void {
            $now = time();
            $task = PublishTask::where(['id' => $id, 'user_id' => $userId, 'tasksn' => $taskSn, 'status' => 'scheduled'])
                ->where('next_run_at', '<=', $now)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                return;
            }

            // 锁住计划后复查本轮，防止两个扫描进程重复投递。
            if (PublishTaskRun::where('tasksn', $taskSn)->value('id')) {
                return;
            }

            $run = PublishTaskRun::create([
                'user_id' => $userId,
                'task_id' => $id,
                'tasksn' => $taskSn,
                'scheduled_at' => (int)$task->next_run_at,
                'status' => 'pending',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if ((int)$run->id <= 0 || (int)$run->tenant_id !== $tenantId) {
                throw new RuntimeException('发布任务执行记录保存失败');
            }

            // 投递失败时回滚记录。Job 锁住同一条计划后才读取执行记录。
            PublishTaskJob::dispatch(['task_id' => $id, 'run_id' => (int)$run->id, 'user_id' => $userId, 'tasksn' => $taskSn]);
        });
    }

    /** 消费执行记录，发送结果与下一次执行时间一并提交。 */
    public static function consume(int $taskId, int $runId, int $tenantId, int $userId, string $taskSn): void
    {
        self::executeRun($taskId, $runId, $tenantId, $userId, $taskSn, false);
    }

    /** MNS 重试耗尽后结束本轮，并更新下一轮排期。 */
    public static function abandon(int $taskId, int $runId, int $tenantId, int $userId, string $taskSn): void
    {
        self::executeRun($taskId, $runId, $tenantId, $userId, $taskSn, true);
    }

    private static function executeRun(int $taskId, int $runId, int $tenantId, int $userId, string $taskSn, bool $failed): void
    {
        self::requireOwner($tenantId, $userId);
        Db::transaction(static function () use ($taskId, $runId, $tenantId, $userId, $taskSn, $failed): void {
            // 同一计划的同一轮串行处理，其他计划各自锁自己的行。
            $taskWhere = ['id' => $taskId, 'user_id' => $userId, 'tasksn' => $taskSn, 'status' => 'scheduled'];
            $record = PublishTask::where($taskWhere)->lock(true)->findOrEmpty();
            $runWhere = ['id' => $runId, 'task_id' => $taskId, 'user_id' => $userId, 'tasksn' => $taskSn];
            $run = PublishTaskRun::where($runWhere)->whereIn('status', ['pending', 'running'])->findOrEmpty();
            if ($run->isEmpty()) {
                return;
            }
            $runWhere['status'] = (string)$run->status;
            if ($record->isEmpty()) {
                PublishTaskRun::where($runWhere)->update(['status' => 'skipped', 'update_time' => time()]);
                return;
            }

            // 通过独立连接提交运行进度，进程中断后重试可以继续处理。
            if (!$failed && $runWhere['status'] === 'pending') {
                PublishTaskRun::connect(self::PROGRESS_CONNECTION)->where($runWhere)
                    ->update(['status' => 'running', 'update_time' => time()]);
                $runWhere['status'] = 'running';
            }

            $scheduledAt = (int)$run->scheduled_at;
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
    }

    /** 同一轮逐个发送，重试时继续处理没有发送记录的人。 */
    private static function sendRun(array $runWhere, array $task, int $tenantId, int $userId, array $results): array
    {
        $storeOrigin = '';
        $storeQuery = '';
        if (isset($task['staff_ids'])) {
            $staffIds = json_decode($task['staff_ids'], true, flags: JSON_THROW_ON_ERROR);
            $staff = self::staffQuery($tenantId, $userId)->whereIn('id', $staffIds)
                ->column('openid,appid,store_id', 'id');
            // Job 没有 HTTP 请求域名，使用当前租户配置的 PC 访问地址。
            $tenant = Tenant::where('id', $tenantId)->findOrEmpty();
            if (!$tenant->isEmpty()) {
                $pcUrl = TenantUrlService::links($tenant->getData())['current']['pc'];
                $host = TenantUrlService::normalizeHost($pcUrl);
                if ($host !== '' && !str_ends_with($host, '.') && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $storeOrigin = 'https://' . $host;
                    $storeQuery = '?pc=1';
                    if (parse_url($pcUrl, PHP_URL_QUERY)) {
                        $storeQuery .= '&tenant_id=' . $tenantId;
                    }
                }
            }
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
            $storeId = (int)($staff[$staffId]['store_id'] ?? 0);
            $storeUrl = $storeId > 0 && $storeOrigin !== ''
                ? $storeOrigin . '/pc/merchant/store/' . $storeId . '/decorate' . $storeQuery : null;

            // 发送前保存状态，进程中断后可以识别可能已发送的通知。
            $results[$staffId] = ['last_run_status' => 'sending', 'last_error' => ''];
            self::saveRecipientResults($runWhere, $results);
            $results[$staffId] = self::notify($task, $tenantId, $userId, $openid, $appId, $storeUrl);
            self::saveRecipientResults($runWhere, $results);
        }
        return $results;
    }

    /** 将收件人结果保存在本轮执行记录里。 */
    private static function saveRecipientResults(array $runWhere, array $results): void
    {
        PublishTaskRun::connect(self::PROGRESS_CONNECTION)->where($runWhere)->update([
            'recipient_results' => json_encode((object)$results, JSON_THROW_ON_ERROR),
            'update_time' => time(),
        ]);
    }

    /**
     * 发送服务号模板通知并返回接收人的发送结果。
     *
     * @return array{last_run_status: string, last_error: string}
     */
    private static function notify(array $task, int $tenantId, int $userId, string $openid, ?string $appId, ?string $storeUrl): array
    {
        if ($openid === '' || $appId === '') {
            return [
                'last_run_status' => 'failed',
                'last_error' => $appId === null ? '用户未绑定服务号' : '员工已删除或未绑定服务号',
            ];
        }
        if ($appId !== null && $storeUrl === null) {
            return ['last_run_status' => 'failed', 'last_error' => '无法生成门店链接，请检查员工所属门店和租户访问域名'];
        }
        try {
            StaffWechatBindingLogic::sendTaskNotice($tenantId, $openid, $appId, (string)$task['content'], $storeUrl);
            return ['last_run_status' => 'sent', 'last_error' => ''];
        } catch (Throwable $e) {
            // 请求异常可能包含带 access_token 的 URL，只记录异常类型和错误码。
            Log::channel('mns')->warning(sprintf('[publish_task] WeChat send failed id=%d tenant=%d user=%d type=%s code=%d',
                (int)$task['id'], $tenantId, $userId, get_class($e), (int)$e->getCode()));
            return ['last_run_status' => 'failed', 'last_error' => $e->getCode()
                ? '服务号模板通知发送失败（错误码 ' . $e->getCode() . '）' : '服务号模板通知发送失败，请检查员工通知模板和公众号配置'];
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

    /** 批量读取当前页涉及的员工和门店，仅返回列表展示需要的字段。 */
    private static function staffDetails(int $tenantId, int $userId, array $ids): array
    {
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $staff = self::staffQuery($tenantId, $userId)->whereIn('id', $ids)
            ->field('id,name,store_id')->select()->toArray();
        if ($staff === []) {
            return [];
        }
        $stores = MerchantStore::withoutGlobalScope()
            ->where(['tenant_id' => $tenantId, 'user_id' => $userId])->whereNull('delete_time')
            ->whereIn('id', array_unique(array_column($staff, 'store_id')))->column('name', 'id');
        foreach ($staff as &$row) {
            $row['id'] = (int)$row['id'];
            $row['store_id'] = (int)$row['store_id'];
            $row['store_name'] = (string)($stores[$row['store_id']] ?? '');
        }
        unset($row);
        return array_column($staff, null, 'id');
    }

    /** 保留原有接收人顺序，缺失员工由接口原有的 ID 标识。 */
    private static function selectStaff(array $staff, array $ids): array
    {
        $selected = [];
        foreach ($ids as $id) {
            if (isset($staff[$id])) {
                $selected[] = $staff[$id];
            }
        }
        return $selected;
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
