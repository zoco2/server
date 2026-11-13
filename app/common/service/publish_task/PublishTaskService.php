<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\PublishTask;
use app\common\model\user\UserAuth;
use app\common\service\wechat\WeChatOaService;
use InvalidArgumentException;
use RuntimeException;
use think\db\ConnectionInterface;
use think\db\Query;
use think\db\exception\DbException;
use think\facade\Log;
use Throwable;

/** 管理当前租户用户的发布任务，由 MNS 消息逐轮触发执行。 */
final class PublishTaskService
{
    private const PLATFORMS = ['douyin', 'xiaohongshu', 'kuaishou'];
    private const MAX_STAFF = 50;

    /** 校验任务内容和调度规则，保存任务并投递首次执行的消息。 */
    public static function create(int $userId, array $input): array
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

        return self::withOwnerLock($userId, static function (ConnectionInterface $connection) use (
            $userId, $content, $platforms, $rule, $staffIds
        ): array {
            if ($staffIds !== null) {
                $staff = MerchantStaff::where('user_id', $userId)->whereIn('id', $staffIds)
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

            // 取得锁后再按当前时间计算首轮，范围内没有可用时间时直接结束。
            $now = time();
            $nextRunAt = PublishTaskSchedule::first($rule, $now);
            $wakeAt = $nextRunAt === null ? null : PublishTaskSchedule::wakeAt($nextRunAt, $now);
            $row = $rule + [
                'user_id' => $userId,
                'content' => trim($content),
                'platforms' => json_encode(array_values(array_unique($platforms)), JSON_THROW_ON_ERROR),
                'notice_type' => 'official_account',
                'status' => $wakeAt === null ? 'ended' : 'scheduled',
                'next_wake_at' => $wakeAt,
                'last_run_at' => null,
                'last_run_status' => '',
                'last_error' => '',
                'create_time' => $now,
                'update_time' => $now,
            ];
            // 投递失败时回滚任务记录。零延迟消息可能立即被取到，消费者会等待同一把锁，
            // 在创建事务提交或回滚后才读取任务。
            $ids = $connection->transaction(static function () use ($userId, $row, $wakeAt, $staffIds): array {
                $ids = [];
                foreach ($staffIds ?? [null] as $staffId) {
                    // 与门店员工保存一致，由模型的 BeforeInsert 写入租户 ID。
                    $task = PublishTask::create($row + ['staff_id' => $staffId]);
                    $id = (int)$task->id;
                    if ($id <= 0) {
                        throw new RuntimeException('任务保存失败');
                    }
                    if ($wakeAt !== null) {
                        self::enqueue($id, $userId, $wakeAt);
                    }
                    $ids[] = $id;
                }
                return $ids;
            });
            if ($staffIds !== null) {
                return ['ids' => $ids, 'count' => count($ids)];
            }
            return self::present($row + ['id' => $ids[0]]);
        });
    }

    /** 按创建顺序倒序分页，仅返回当前租户下当前用户的任务。 */
    public static function lists(int $userId, int $page, int $size): array
    {
        $page = max(1, $page);
        $size = max(1, min(100, $size));
        $query = self::taskQuery($userId);
        $count = (clone $query)->count();
        $tasks = $query->order('id desc')->page($page, $size)->select()->toArray();
        return [
            'count' => $count,
            'lists' => array_map(self::present(...), $tasks),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 核对消息是否仍对应当前调度，到期则执行本轮，并投递下一轮。 */
    public static function consume(int $id, int $userId, int $wakeAt): void
    {
        self::withOwnerLock($userId, static function () use ($id, $userId, $wakeAt): void {
            // 消息的计划触发时间必须与任务记录一致，旧消息直接忽略。
            $where = [
                'id' => $id,
                'user_id' => $userId,
                'status' => 'scheduled',
                'next_wake_at' => $wakeAt,
            ];
            $record = PublishTask::where($where)->findOrEmpty();
            if ($record->isEmpty()) {
                return;
            }
            $task = $record->getData();

            $now = time();
            // 从本条消息的计划触发时间还原本轮，避免迟到的消息跳过应执行的轮次。
            $scheduledAt = PublishTaskSchedule::first($task, $wakeAt);
            $alreadyAttempted = $scheduledAt !== null && (int)$task['last_run_at'] === $scheduledAt;
            if ($alreadyAttempted && $task['last_run_status'] === 'sending') {
                // 上次在发送阶段中断，结果记为未知，避免重复通知。
                PublishTask::where($where)->update([
                    'last_run_status' => 'unknown',
                    'last_error' => '发送进程中断，无法确认微信是否接收',
                    'update_time' => $now,
                ]);
            }

            if ($scheduledAt === null || PublishTaskSchedule::hasEnded($task, $now)) {
                $nextRunAt = null;
            } elseif ($now < $scheduledAt) {
                // 分段延时的消息提前到达，继续等待原定执行时间。
                $nextRunAt = $scheduledAt;
            } else {
                if (!$alreadyAttempted) {
                    $appId = null;
                    if (isset($task['staff_id'])) {
                        // 员工可以在任务创建后解绑或删除，发送前重新检查。
                        $staff = MerchantStaff::where('user_id', $userId)->where('id', (int)$task['staff_id'])
                            ->field('openid,appid')->findOrEmpty();
                        $openid = trim((string)($staff['openid'] ?? ''));
                        $appId = trim((string)($staff['appid'] ?? ''));
                    } else {
                        $openid = trim((string)UserAuth::where([
                            'user_id' => $userId,
                            'terminal' => UserTerminalEnum::WECHAT_OA,
                        ])->order('id desc')->value('openid'));
                    }

                    // 发送前记录本轮已经开始，MNS 重投时可以跳过这次发送。
                    PublishTask::where($where)->update([
                        'last_run_at' => $scheduledAt,
                        'last_run_status' => 'sending',
                        'last_error' => '',
                        'update_time' => $now,
                    ]);
                    $result = self::notify($id, $userId, $openid, $appId);
                    PublishTask::where($where)->update($result + ['update_time' => time()]);
                }
                // 已错过的轮次不补发，只安排下一次未来的执行。
                $nextRunAt = PublishTaskSchedule::next($task, max($scheduledAt + 1, time()));
            }

            $nextWakeAt = null;
            if ($nextRunAt !== null) {
                // 先投递再更新时间。投递失败时，当前消息仍可重试。
                $nextWakeAt = PublishTaskSchedule::wakeAt($nextRunAt, time());
                self::enqueue($id, $userId, $nextWakeAt);
            }
            PublishTask::where($where)->update([
                'status' => $nextWakeAt === null ? 'ended' : 'scheduled',
                'next_wake_at' => $nextWakeAt,
                'update_time' => time(),
            ]);
        });
    }

    /** 消费重试耗尽时终止该消息对应的调度，旧消息不会影响当前任务状态。 */
    public static function abandon(int $id, int $userId, int $wakeAt): void
    {
        self::withOwnerLock($userId, static function () use ($id, $userId, $wakeAt): void {
            PublishTask::where([
                'id' => $id,
                'user_id' => $userId,
                'status' => 'scheduled',
                'next_wake_at' => $wakeAt,
            ])->update([
                'status' => 'failed',
                'next_wake_at' => null,
                'update_time' => time(),
            ]);
        });
    }

    /**
     * 尝试发送服务号文本并返回本轮结果，供调用方保存。
     *
     * @return array{last_run_status: string, last_error: string}
     */
    private static function notify(int $id, int $userId, string $openid, ?string $appId): array
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
                $id, (int)request()->tenantId, $userId, get_class($e), (int)$e->getCode()));
            return ['last_run_status' => 'failed', 'last_error' => $e->getCode()
                ? '服务号通知发送失败（错误码 ' . $e->getCode() . '）' : '服务号通知发送失败，请检查配置及用户互动权限'];
        }
    }

    /** 按指定时刻投递消息，消费时用该时间匹配任务记录。 */
    private static function enqueue(int $id, int $userId, int $wakeAt): void
    {
        PublishTaskJob::dispatch(
            ['task_id' => $id, 'user_id' => $userId, 'wake_at' => $wakeAt],
            max(0, $wakeAt - time())
        );
    }

    /** 在任务模型的连接上持有锁，让同一租户下同一用户的创建和消费串行执行。 */
    private static function withOwnerLock(int $userId, callable $action): mixed
    {
        $connection = self::taskQuery($userId)->getConnection();
        $tenantId = (int)request()->tenantId;
        // MySQL 命名锁在同一实例内共享，锁名加入库名和表前缀以区分不同部署。
        $lock = 'publish_task:' . sha1($connection->getConfig('database') . ':' . $connection->getConfig('prefix') . ':' . $tenantId . ':' . $userId);
        if ((int)($connection->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock], true)[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('该用户的发布任务正在处理，请稍后重试');
        }
        try {
            return $action($connection);
        } finally {
            try {
                $connection->query('SELECT RELEASE_LOCK(?)', [$lock], true);
            } catch (Throwable $e) {
                // 显式释放失败时关闭连接，避免常驻消费者继续占用这把锁。
                $connection->close();
                Log::channel('mns')->warning('[publish_task] owner lock release failed: ' . get_class($e));
            }
        }
    }

    /** 只处理已有租户上下文且任务表已完成迁移的请求。 */
    private static function taskQuery(int $userId): Query
    {
        if ((int)(request()->tenantId ?? 0) <= 0 || !AdminTerminalEnum::isUser() || $userId <= 0) {
            throw new InvalidArgumentException('缺少租户或登录用户');
        }
        $query = PublishTask::where('user_id', $userId);
        $fields = $query->getConnection()->getTableFields($query->getTable());
        // 旧表缺租户列时 BaseModel 无法限定租户，缺员工列时可能丢失接收人。
        foreach (['tenant_id', 'staff_id', 'next_wake_at'] as $field) {
            if (!in_array($field, $fields, true)) {
                throw new DbException('fields not exists:[' . $field . ']');
            }
        }
        return $query;
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
                // JSON 数字理应是 int，但表单编码会把 "2" 送进来；先记录真实类型再拒绝。
                Log::channel('mns')->warning('[publish_task] invalid staff id: '
                    . get_debug_type($staffId) . ' ' . json_encode($staffId, JSON_UNESCAPED_UNICODE));
                throw new InvalidArgumentException('员工 ID 无效');
            }
            $clean[] = $value;
        }
        return array_values(array_unique($clean));
    }

    /** 将数据库记录转换成接口数据，隐藏内部标识并统一数值字段类型。 */
    private static function present(array $row): array
    {
        // 接口返回真正发通知的时间，分段延时的中间触发时间仅供队列使用。
        $row['next_run_at'] = $row['next_wake_at'] === null
            ? null : PublishTaskSchedule::first($row, (int)$row['next_wake_at']);
        unset($row['next_wake_at'], $row['tenant_id'], $row['user_id']);
        if (isset($row['staff_id'])) {
            $row['staff_id'] = (int)$row['staff_id'];
        } else {
            unset($row['staff_id']);
        }
        $row['platforms'] = json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'weekday', 'last_run_at', 'create_time', 'update_time'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        return $row + ['timezone' => PublishTaskSchedule::TIMEZONE];
    }
}
