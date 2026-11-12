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

final class PublishTaskService
{
    private const TABLE = 'publish_task';
    private const PLATFORMS = ['douyin', 'xiaohongshu', 'kuaishou'];

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
            // A zero-delay message may be received immediately. The consumer acquires the same
            // owner lock, so it cannot read the row until this transaction commits or rolls back.
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

    public static function consume(int $id, int $tenantId, int $userId, string $token): void
    {
        self::withOwnerLock($tenantId, $userId, static function (Mysql $db) use ($id, $tenantId, $userId, $token): void {
            $query = $db->name(self::TABLE)->master()->where([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'scheduled', 'wake_token' => $token,
            ]);
            $row = (clone $query)->find();
            if ($row === null || $row === []) {
                return; // Duplicate, obsolete, or uncommitted creation that rolled back.
            }

            $now = time();
            $scheduledAt = (int)$row['next_run_at'];
            $claimed = (int)$row['last_run_at'] === $scheduledAt;
            if ($claimed && $row['last_run_status'] === 'sending') {
                (clone $query)->update(['last_run_status' => 'unknown',
                    'last_error' => '发送进程中断，无法确认微信是否接收', 'update_time' => $now]);
            }
            $next = null;
            if (!PublishTaskSchedule::hasEnded($row, $now)) {
                if ($now < $scheduledAt) {
                    $next = $scheduledAt; // MNS woke up to relay a long delay.
                } else {
                    if (!$claimed) {
                        $openid = trim((string)UserAuth::withoutGlobalScope()->where([
                            'tenant_id' => $tenantId, 'user_id' => $userId, 'terminal' => UserTerminalEnum::WECHAT_OA,
                        ])->order('id desc')->value('openid'));
                        // Claim this occurrence before the remote call. WeChat has no idempotency
                        // key, so a crashed sender must never send the same occurrence again.
                        (clone $query)->update(['last_run_at' => $scheduledAt, 'last_run_status' => 'sending',
                            'last_error' => '', 'update_time' => $now]);
                        (clone $query)->update(self::notify($id, $tenantId, $userId, $openid) + ['update_time' => time()]);
                    }
                    $next = PublishTaskSchedule::next($row, max($scheduledAt + 1, time()));
                }
            }

            $nextToken = $next === null ? '' : self::token();
            if ($next !== null) {
                // Keep the old token until the next wake-up is accepted. On a dispatch error,
                // MNS redelivers this message and the saved occurrence prevents another send.
                self::enqueue($id, $tenantId, $userId, $next, $nextToken);
            }
            $query->update(['next_run_at' => $next, 'wake_token' => $nextToken,
                'status' => $next === null ? 'ended' : 'scheduled', 'update_time' => time()]);
        });
    }

    public static function abandon(int $id, int $tenantId, int $userId, string $token): void
    {
        self::withOwnerLock($tenantId, $userId, static function (Mysql $db) use ($id, $tenantId, $userId, $token): void {
            $db->name(self::TABLE)->where([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'status' => 'scheduled', 'wake_token' => $token,
            ])->update(['status' => 'failed', 'next_run_at' => null, 'wake_token' => '', 'update_time' => time()]);
        });
    }

    private static function notify(int $id, int $tenantId, int $userId, string $openid): array
    {
        if ($openid === '') {
            return ['last_run_status' => 'failed', 'last_error' => '用户未绑定服务号'];
        }
        try {
            (new WeChatOaService())->sendCustomText($openid, 'hello');
            return ['last_run_status' => 'sent', 'last_error' => ''];
        } catch (Throwable $e) {
            // Transport exception text can contain the WeChat access-token URL.
            Log::channel('mns')->warning(sprintf('[publish_task] WeChat send failed id=%d tenant=%d user=%d type=%s code=%d',
                $id, $tenantId, $userId, get_class($e), (int)$e->getCode()));
            return ['last_run_status' => 'failed', 'last_error' => $e->getCode()
                ? '服务号通知发送失败（错误码 ' . $e->getCode() . '）' : '服务号通知发送失败，请检查配置及用户互动权限'];
        }
    }

    private static function enqueue(int $id, int $tenantId, int $userId, int $next, string $token): void
    {
        PublishTaskJob::dispatch(['task_id' => $id, 'user_id' => $userId, 'wake_token' => $token],
            PublishTaskSchedule::delay($next, time()), '', $tenantId);
    }

    private static function withOwnerLock(int $tenantId, int $userId, callable $action): mixed
    {
        self::requireOwner($tenantId, $userId);
        $db = Db::connect('mysql');
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
                $db->close();
                Log::channel('mns')->warning('[publish_task] owner lock release failed: ' . get_class($e));
            }
        }
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function requireOwner(int $tenantId, int $userId): void
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('缺少租户或登录用户');
        }
    }

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
