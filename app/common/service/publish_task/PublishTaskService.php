<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\enum\user\UserTerminalEnum;
use app\common\job\PublishTaskJob;
use app\common\model\user\UserAuth;
use app\common\service\wechat\WeChatOaService;
use InvalidArgumentException;
use RuntimeException;
use think\db\connector\Mysql;
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

        return self::withOwnerLock($tenantId, $userId, static function (Mysql $db) use (
            $tenantId, $userId, $content, $platforms, $rule
        ): array {
            // 取得锁后再按当前时间计算首轮，范围内没有可用时间时直接结束。
            $now = time();
            $next = PublishTaskSchedule::first($rule, $now);
            $token = $next === null ? '' : self::token();
            $row = $rule + [
                'tenant_id' => $tenantId, 'user_id' => $userId, 'content' => trim($content),
                'platforms' => json_encode(array_values(array_unique($platforms)), JSON_THROW_ON_ERROR),
                'notice_type' => 'official_account', 'status' => $next === null ? 'ended' : 'scheduled',
                'next_run_at' => $next, 'wake_token' => $token, 'last_run_at' => null,
                'last_run_status' => '', 'last_error' => '', 'create_time' => $now, 'update_time' => $now,
            ];
            // 投递失败时回滚任务记录。零延迟消息可能立即被取到，消费者会等待同一把锁，
            // 在创建事务提交或回滚后才读取任务。
            $row['id'] = $db->transaction(static function () use ($db, $row, $next, $token): int {
                $id = (int)$db->name(self::TABLE)->insertGetId($row);
                if ($next !== null) {
                    self::enqueue($id, (int)$row['tenant_id'], (int)$row['user_id'], $next, $token);
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
        $query = Db::connect('mysql')->name(self::TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId]);
        return [
            'count' => (clone $query)->count(),
            'lists' => array_map(self::present(...), $query->order('id desc')->page($page, $size)->select()->toArray()),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /** 核对消息是否仍对应当前调度，到期则执行本轮，并投递下一轮。 */
    public static function consume(int $id, int $tenantId, int $userId, string $token): void
    {
        self::withOwnerLock($tenantId, $userId, static function (Mysql $db) use ($id, $tenantId, $userId, $token): void {
            // 主库上的令牌对应当前待处理消息，不能用旧消息覆盖新一轮调度。
            $query = $db->name(self::TABLE)->master()->where([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'scheduled', 'wake_token' => $token,
            ]);
            $row = (clone $query)->find();
            if ($row === null || $row === []) {
                return; // 忽略已处理、已失效或创建事务回滚后遗留的消息。
            }

            $now = time();
            $scheduledAt = (int)$row['next_run_at'];
            $claimed = (int)$row['last_run_at'] === $scheduledAt;
            if ($claimed && $row['last_run_status'] === 'sending') {
                // 上次已开始发送，但进程在写入结果前中断，无法确认微信是否收到了请求。
                (clone $query)->update(['last_run_status' => 'unknown',
                    'last_error' => '发送进程中断，无法确认微信是否接收', 'update_time' => $now]);
            }
            $next = null;
            if (!PublishTaskSchedule::hasEnded($row, $now)) {
                if ($now < $scheduledAt) {
                    $next = $scheduledAt; // 远期任务提前唤醒只接续等待，保留原执行时刻。
                } else {
                    if (!$claimed) {
                        // 用消息中的租户和用户显式查询公众号绑定，不依赖请求上下文。
                        $openid = trim((string)UserAuth::withoutGlobalScope()->where([
                            'tenant_id' => $tenantId, 'user_id' => $userId, 'terminal' => UserTerminalEnum::WECHAT_OA,
                        ])->order('id desc')->value('openid'));
                        // 先记录本轮开始发送。微信接口没有幂等键，重投时跳过已尝试的轮次，
                        // 避免发送成功但结果未写入时重复通知。
                        (clone $query)->update(['last_run_at' => $scheduledAt, 'last_run_status' => 'sending',
                            'last_error' => '', 'update_time' => $now]);
                        (clone $query)->update(self::notify($id, $tenantId, $userId, $openid) + ['update_time' => time()]);
                    }
                    // 从当前轮次之后计算下一次执行，跳过消息延迟期间已经错过的时刻。
                    $next = PublishTaskSchedule::next($row, max($scheduledAt + 1, time()));
                }
            }

            $nextToken = $next === null ? '' : self::token();
            if ($next !== null) {
                // 新消息投递成功后才替换令牌。投递失败时 MNS 可重投旧消息，
                // 已保存的发送记录让重投只接续调度，不重复发通知。
                self::enqueue($id, $tenantId, $userId, $next, $nextToken);
            }
            $query->update(['next_run_at' => $next, 'wake_token' => $nextToken,
                'status' => $next === null ? 'ended' : 'scheduled', 'update_time' => time()]);
        });
    }

    /** 消费重试耗尽时终止该消息对应的调度，旧消息不会影响当前任务状态。 */
    public static function abandon(int $id, int $tenantId, int $userId, string $token): void
    {
        self::withOwnerLock($tenantId, $userId, static function (Mysql $db) use ($id, $tenantId, $userId, $token): void {
            $db->name(self::TABLE)->where([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'scheduled', 'wake_token' => $token,
            ])->update(['status' => 'failed', 'next_run_at' => null, 'wake_token' => '', 'update_time' => time()]);
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

    /** 根据执行时间计算本次延时，消息携带任务归属、任务 ID 和令牌。 */
    private static function enqueue(int $id, int $tenantId, int $userId, int $next, string $token): void
    {
        PublishTaskJob::dispatch(['task_id' => $id, 'user_id' => $userId, 'wake_token' => $token],
            PublishTaskSchedule::delay($next, time()), '', $tenantId);
    }

    /** 在同一主库连接上加锁，让同一租户下同一用户的创建和消费串行执行。 */
    private static function withOwnerLock(int $tenantId, int $userId, callable $action): mixed
    {
        self::requireOwner($tenantId, $userId);
        $db = Db::connect('mysql');
        // MySQL 命名锁在同一实例内共享，锁名加入库名和表前缀以区分不同部署。
        $lock = 'publish_task:' . sha1($db->getConfig('database') . ':' . $db->getConfig('prefix') . ':' . $tenantId . ':' . $userId);
        if ((int)($db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock], true)[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('该用户的发布任务正在处理，请稍后重试');
        }
        try {
            return $action($db);
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

    /** 为每次新投递生成独立令牌，消费时用它区分有效消息和旧消息。 */
    private static function token(): string
    {
        return bin2hex(random_bytes(16));
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
        unset($row['wake_token'], $row['tenant_id'], $row['user_id']);
        $row['platforms'] = json_decode($row['platforms'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['id', 'weekday', 'next_run_at', 'last_run_at', 'create_time', 'update_time'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        return $row + ['timezone' => PublishTaskSchedule::TIMEZONE];
    }
}
