<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
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
    private const TABLE = 'publish_task';
    private const PLATFORMS = ['douyin', 'xiaohongshu', 'kuaishou'];

    /** 校验任务内容和调度规则，保存任务并投递首次执行的消息。 */
    public static function create(int $tenantId, int $userId, array $input): array
    {
        self::requireOwner($tenantId, $userId);
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

        return self::withOwnerLock($tenantId, $userId, static function () use (
            $tenantId, $userId, $content, $platforms, $rule
        ): array {
            // 取得锁后再按当前时间计算首轮，范围内没有可用时间时直接结束。
            $now = time();
            $nextRunAt = PublishTaskSchedule::first($rule, $now);
            $wakeAt = $nextRunAt === null ? null : PublishTaskSchedule::wakeAt($nextRunAt, $now);
            $row = $rule + [
                'tenant_id' => $tenantId,
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
            $row['id'] = Db::transaction(static function () use ($tenantId, $userId, $row, $wakeAt): int {
                $id = (int)Db::name(self::TABLE)->insertGetId($row);
                if ($wakeAt !== null) {
                    self::enqueue($id, $tenantId, $userId, $wakeAt);
                }
                return $id;
            });
            return self::present($row);
        });
    }

    /** 按创建顺序倒序分页，仅返回当前租户下当前用户的任务。 */
    public static function lists(int $tenantId, int $userId, int $page, int $size): array
    {
        self::requireOwner($tenantId, $userId);
        $page = max(1, $page);
        $size = max(1, min(100, $size));
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        $count = Db::name(self::TABLE)->where($owner)->count();
        $tasks = Db::name(self::TABLE)->where($owner)
            ->order('id desc')->page($page, $size)->select()->toArray();
        return [
            'count' => $count,
            'lists' => array_map(self::present(...), $tasks),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 核对消息是否仍对应当前调度，到期则执行本轮，并投递下一轮。 */
    public static function consume(int $id, int $tenantId, int $userId, int $wakeAt): void
    {
        self::withOwnerLock($tenantId, $userId, static function () use ($id, $tenantId, $userId, $wakeAt): void {
            // 消息的计划触发时间必须与任务记录一致，旧消息直接忽略。
            $where = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'scheduled',
                'next_wake_at' => $wakeAt,
            ];
            $task = Db::name(self::TABLE)->where($where)->find();
            if (!$task) {
                return;
            }

            $now = time();
            // 从本条消息的计划触发时间还原本轮，避免迟到的消息跳过应执行的轮次。
            $scheduledAt = PublishTaskSchedule::first($task, $wakeAt);
            $alreadyAttempted = $scheduledAt !== null && (int)$task['last_run_at'] === $scheduledAt;
            if ($alreadyAttempted && $task['last_run_status'] === 'sending') {
                // 上次在发送阶段中断，结果记为未知，避免重复通知。
                Db::name(self::TABLE)->where($where)->update([
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
                    $openid = trim((string)UserAuth::withoutGlobalScope()->where([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'terminal' => UserTerminalEnum::WECHAT_OA,
                    ])->order('id desc')->value('openid'));

                    // 发送前记录本轮已经开始，MNS 重投时可以跳过这次发送。
                    Db::name(self::TABLE)->where($where)->update([
                        'last_run_at' => $scheduledAt,
                        'last_run_status' => 'sending',
                        'last_error' => '',
                        'update_time' => $now,
                    ]);
                    $result = self::notify($id, $tenantId, $userId, $openid);
                    Db::name(self::TABLE)->where($where)->update($result + ['update_time' => time()]);
                }
                // 已错过的轮次不补发，只安排下一次未来的执行。
                $nextRunAt = PublishTaskSchedule::next($task, max($scheduledAt + 1, time()));
            }

            $nextWakeAt = null;
            if ($nextRunAt !== null) {
                // 先投递再更新时间。投递失败时，当前消息仍可重试。
                $nextWakeAt = PublishTaskSchedule::wakeAt($nextRunAt, time());
                self::enqueue($id, $tenantId, $userId, $nextWakeAt);
            }
            Db::name(self::TABLE)->where($where)->update([
                'status' => $nextWakeAt === null ? 'ended' : 'scheduled',
                'next_wake_at' => $nextWakeAt,
                'update_time' => time(),
            ]);
        });
    }

    /** 消费重试耗尽时终止该消息对应的调度，旧消息不会影响当前任务状态。 */
    public static function abandon(int $id, int $tenantId, int $userId, int $wakeAt): void
    {
        self::withOwnerLock($tenantId, $userId, static function () use ($id, $tenantId, $userId, $wakeAt): void {
            Db::name(self::TABLE)->where([
                'id' => $id,
                'tenant_id' => $tenantId,
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
    private static function notify(int $id, int $tenantId, int $userId, string $openid): array
    {
        if ($openid === '') {
            return ['last_run_status' => 'failed', 'last_error' => '用户未绑定服务号'];
        }
        try {
            (new WeChatOaService())->sendCustomText($openid, 'hello');
            return ['last_run_status' => 'sent', 'last_error' => ''];
        } catch (Throwable $e) {
            // 请求异常可能包含带 access_token 的 URL，只记录异常类型和错误码。
            Log::channel('mns')->warning(sprintf('[publish_task] WeChat send failed id=%d tenant=%d user=%d type=%s code=%d',
                $id, $tenantId, $userId, get_class($e), (int)$e->getCode()));
            return ['last_run_status' => 'failed', 'last_error' => $e->getCode()
                ? '服务号通知发送失败（错误码 ' . $e->getCode() . '）' : '服务号通知发送失败，请检查配置及用户互动权限'];
        }
    }

    /** 按指定时刻投递消息，消费时用该时间匹配任务记录。 */
    private static function enqueue(int $id, int $tenantId, int $userId, int $wakeAt): void
    {
        PublishTaskJob::dispatch(
            ['task_id' => $id, 'user_id' => $userId, 'wake_at' => $wakeAt],
            max(0, $wakeAt - time()),
            '',
            $tenantId
        );
    }

    /** 在默认数据库连接上持有锁，让同一租户下同一用户的创建和消费串行执行。 */
    private static function withOwnerLock(int $tenantId, int $userId, callable $action): mixed
    {
        self::requireOwner($tenantId, $userId);
        $db = Db::connect();
        // MySQL 命名锁在同一实例内共享，锁名加入库名和表前缀以区分不同部署。
        $lock = 'publish_task:' . sha1($db->getConfig('database') . ':' . $db->getConfig('prefix') . ':' . $tenantId . ':' . $userId);
        if ((int)($db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock], true)[0]['acquired'] ?? 0) !== 1) {
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
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('缺少租户或登录用户');
        }
    }

    /** 将数据库记录转换成接口数据，隐藏内部标识并统一数值字段类型。 */
    private static function present(array $row): array
    {
        // 接口返回真正发通知的时间，分段延时的中间触发时间仅供队列使用。
        $row['next_run_at'] = $row['next_wake_at'] === null
            ? null : PublishTaskSchedule::first($row, (int)$row['next_wake_at']);
        unset($row['next_wake_at'], $row['tenant_id'], $row['user_id']);
        $row['platforms'] = json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'weekday', 'last_run_at', 'create_time', 'update_time'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        return $row + ['timezone' => PublishTaskSchedule::TIMEZONE];
    }
}
