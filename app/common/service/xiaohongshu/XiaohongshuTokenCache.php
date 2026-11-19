<?php

declare(strict_types=1);

namespace app\common\service\xiaohongshu;

use Closure;
use RuntimeException;
use think\cache\Driver;
use think\cache\driver\File;
use think\cache\driver\Redis;

/** 小红书重复申请 token 会立即覆盖旧 token，因此刷新必须按应用串行执行。 */
final class XiaohongshuTokenCache
{
    private string $key;
    private string $lockName;

    public function __construct(private Driver $cache, string $appKey, string $appSecret)
    {
        $identity = hash('sha256', $appKey);
        $this->key = 'xiaohongshu_share_' . $identity . '_' . hash('sha256', $appSecret);
        $this->lockName = 'xiaohongshu_share_' . $identity . '_refresh_lock';
    }

    public static function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    public function get(): ?array
    {
        $entry = $this->cache->get($this->key);
        return is_array($entry) && is_string($entry['access_token'] ?? null) && $entry['access_token'] !== ''
            && is_int($entry['expires_at_ms'] ?? null) && is_int($entry['refresh_at_ms'] ?? null)
            && $entry['expires_at_ms'] >= $entry['refresh_at_ms'] && $entry['refresh_at_ms'] > self::nowMs()
            ? $entry : null;
    }

    public function set(array $entry): void
    {
        $remaining = $entry['refresh_at_ms'] - self::nowMs();
        if ($remaining <= 0) {
            throw new RuntimeException('小红书凭据有效期不足，请稍后重试');
        }
        // 向上取整仅用于底层缓存；get() 始终检查准确的毫秒截止时间。
        if (!$this->cache->set($this->key, $entry, (int)ceil($remaining / 1000))) {
            throw new RuntimeException('小红书凭据缓存写入失败');
        }
    }

    public function synchronized(Closure $callback): array
    {
        if ($this->cache instanceof Redis) {
            $redis = $this->cache->handler();
            $key = $this->cache->getCacheKey($this->lockName);
            $owner = bin2hex(random_bytes(16));
            $deadline = microtime(true) + 5;
            do {
                // 单次请求超时 10 秒，锁租期覆盖完整刷新请求。
                $locked = $redis instanceof \Redis
                    ? $redis->set($key, $owner, ['nx', 'ex' => 30])
                    : $redis->set($key, $owner, 'EX', 30, 'NX');
                if ($locked) {
                    try {
                        return $callback();
                    } finally {
                        $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
                        if ($redis instanceof \Redis) {
                            $redis->eval($script, [$key, $owner], 1);
                        } else {
                            $redis->eval($script, 1, $key, $owner);
                        }
                    }
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
        } elseif ($this->cache instanceof File) {
            $path = $this->cache->getCacheKey($this->lockName) . '.lock';
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('小红书凭据缓存目录不可写');
            }
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                throw new RuntimeException('小红书凭据刷新锁创建失败');
            }
            try {
                $deadline = microtime(true) + 5;
                do {
                    if (flock($handle, LOCK_EX | LOCK_NB)) {
                        try {
                            return $callback();
                        } finally {
                            flock($handle, LOCK_UN);
                        }
                    }
                    usleep(50000);
                } while (microtime(true) < $deadline);
            } finally {
                // 不删除锁文件，避免等待中的进程锁住不同的 inode。
                fclose($handle);
            }
        } else {
            throw new RuntimeException('小红书凭据缓存仅支持 file 或 redis 驱动');
        }
        throw new RuntimeException('小红书凭据正在刷新，请稍后重试');
    }
}
