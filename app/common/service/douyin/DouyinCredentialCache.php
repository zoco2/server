<?php

declare(strict_types=1);

namespace app\common\service\douyin;

use Closure;
use RuntimeException;
use think\cache\Driver;
use think\cache\driver\File;
use think\cache\driver\Redis;

/** 应用级凭据缓存；刷新锁覆盖 token 和 ticket，避免并发获取使旧 token 失效。 */
final class DouyinCredentialCache
{
    private string $prefix;
    private string $lockName;

    public function __construct(private Driver $cache, string $clientKey, string $clientSecret)
    {
        $identity = hash('sha256', $clientKey);
        $this->prefix = 'douyin_h5_' . $identity . '_' . hash('sha256', $clientSecret) . '_';
        $this->lockName = 'douyin_h5_' . $identity . '_refresh_lock';
    }

    public function get(string $name): ?array
    {
        $entry = $this->cache->get($this->prefix . $name);
        return is_array($entry) && is_string($entry['value'] ?? null) && $entry['value'] !== ''
            && ($entry['expires_at'] ?? 0) > time() ? $entry : null;
    }

    public function set(string $name, array $entry): void
    {
        $ttl = $entry['expires_at'] - time();
        if ($ttl <= 0) {
            throw new RuntimeException('抖音凭据有效期不足，请重试');
        }
        if (!$this->cache->set($this->prefix . $name, $entry, $ttl)) {
            throw new RuntimeException('抖音凭据缓存写入失败');
        }
    }

    public function delete(string $name): void
    {
        $this->cache->delete($this->prefix . $name);
    }

    public function synchronized(Closure $callback): array
    {
        if ($this->cache instanceof Redis) {
            // 初始化后再取 key，兼容 ThinkPHP 对 Predis 前缀的处理。
            $redis = $this->cache->handler();
            $key = $this->cache->getCacheKey($this->lockName);
            $owner = bin2hex(random_bytes(16));
            $deadline = microtime(true) + 5;
            do {
                // 最多四次 HTTP 请求，每次超时 10 秒；锁租期大于整个刷新链路。
                $locked = $redis instanceof \Redis
                    ? $redis->set($key, $owner, ['nx', 'ex' => 60])
                    : $redis->set($key, $owner, 'EX', 60, 'NX');
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
            $path = $this->cache->getCacheKey($this->lockName);
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('抖音凭据缓存目录不可写');
            }
            $handle = @fopen($path . '.lock', 'c');
            if ($handle === false) {
                throw new RuntimeException('抖音凭据刷新锁创建失败');
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
                // 保留锁文件，避免删除后另一个进程锁住不同的 inode。
                fclose($handle);
            }
        } else {
            throw new RuntimeException('抖音凭据缓存仅支持 file 或 redis 驱动');
        }
        throw new RuntimeException('抖音凭据正在刷新，请稍后重试');
    }
}
