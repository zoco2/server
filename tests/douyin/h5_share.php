<?php

declare(strict_types=1);

use app\common\service\douyin\DouyinCredentialCache;
use app\common\service\douyin\DouyinH5ShareService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\App;
use think\cache\driver\File;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function fails(callable $callback, string $message, string $class = RuntimeException::class): Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (!$error instanceof $class || !str_contains($error->getMessage(), $message)) {
            throw new RuntimeException('Unexpected error: ' . $error->getMessage(), 0, $error);
        }
        return $error;
    }
    throw new RuntimeException('Expected failure containing: ' . $message);
}

function response(array $data): Response
{
    return new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
}

function token(string $value = 'server-token', int $ttl = 7200): Response
{
    return response(['error_code' => 0, 'access_token' => $value, 'expires_in' => $ttl]);
}

function ticket(string $value = '@server+ticket/==', int $ttl = 7200): Response
{
    return response(['error_code' => 0, 'ticket' => $value, 'expires_in' => $ttl]);
}

function fileCache(string $directory): File
{
    return new File(new App(dirname(__DIR__, 2)), ['path' => $directory]);
}

function fixture(string $directory, array $responses, array &$history, array $override = []): DouyinH5ShareService
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new DouyinH5ShareService($override + ['client_key' => 'test-key', 'client_secret' => 'test-secret'],
        new Client(['handler' => $stack]), fileCache($directory));
}

// 并发子进程使用同一缓存，模拟慢响应并记录真正的上游调用。
if (($argv[1] ?? '') === 'worker') {
    $directory = $argv[2];
    $record = static function ($request) use ($directory): Response {
        usleep(150000);
        file_put_contents($directory . '/requests.log', $request->getMethod() . "\n", FILE_APPEND | LOCK_EX);
        return $request->getMethod() === 'POST' ? token() : ticket();
    };
    $history = [];
    fixture($directory, [$record, $record], $history)->createShare(['image_path' => 'https://cdn.example.com/a.jpg']);
    exit(0);
}

$root = dirname(__DIR__, 2) . '/runtime/douyin-test-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
$video = ['video_path' => 'https://cdn.example.com/video.mp4?key=a+b%2Fc&v=1'];
$image = ['image_path' => 'https://cdn.example.com/a.jpg'];

try {
    // 验证不传配置/缓存时的生产入口，不初始化应用、数据库或读取真实凭据。
    new App(dirname(__DIR__, 2));
    \think\facade\Env::set(['DOUYIN' => ['CLIENT_KEY' => 'configured-key', 'CLIENT_SECRET' => 'configured-secret', 'CACHE_STORE' => 'douyin_test']]);
    \think\facade\Config::set(require dirname(__DIR__, 2) . '/config/douyin.php', 'douyin');
    \think\facade\Config::set(['default' => 'douyin_test', 'stores' => [
        'douyin_test' => ['type' => 'File', 'path' => $root . '/configured'],
    ]], 'cache');
    $configured = new DouyinH5ShareService(null, new Client(['handler' => HandlerStack::create(new MockHandler([token(), ticket()]))]));
    same('configured-key', $configured->createShare($image)['params']['client_key'], 'framework config and cache wiring');

    same('3f7b739a91a52cb7d85c4f89c5f611fe', DouyinH5ShareService::signature('Wm3WZYTPz0wzccnW',
        '@ml6sqYBGgTKmQNajnKNkaj8yksCAY++adIhlGIqfTiKyvBqOIkzdJ6WRgP+nO+wtVItqKbX4iZ+mFIYkyPJjpQ==',
        '1650941858'), 'official signature vector');

    $history = [];
    $service = fixture($root . '/basic', [token(), ticket()], $history);
    $before = time();
    $share = $service->createShare($video + ['title' => '来吃饭 🍲 & 放松+一下', 'hashtag_list' => ['探店', '同城美食'],
        'title_hashtag_list' => [['name' => '探店', 'start' => 2]], 'poi_id' => '123456', 'state' => 'share-id']);
    same(true, str_starts_with($share['schema'], 'snssdk1128://openplatform/share?'), 'schema prefix');
    parse_str(parse_url($share['schema'], PHP_URL_QUERY), $decoded);
    same($video['video_path'], $decoded['video_path'], 'signed media URL preserved');
    same('来吃饭 🍲 & 放松+一下', $decoded['title'], 'unicode and reserved title characters');
    same(['探店', '同城美食'], json_decode($decoded['hashtag_list'], true), 'hashtags JSON');
    same([['name' => '探店', 'start' => 2]], json_decode($decoded['title_hashtag_list'], true), 'positioned tags');
    same('1', $decoded['share_to_publish'], 'video defaults to publish page');
    same('123456', $decoded['poi_id'], 'store POI');
    same('share-id', $decoded['state'], 'state passthrough');
    same(true, is_string($share['params']['timestamp']), 'string timestamp');
    same(DouyinH5ShareService::signature($decoded['nonce_str'], '@server+ticket/==', $decoded['timestamp']), $decoded['signature'], 'matching signed parameters');
    same(true, $share['ticket_expires_at'] >= $before + 7080 && $share['ticket_expires_at'] <= time() + 7080, 'early refresh margin');
    foreach (['test-secret', 'server-token', '@server+ticket/=='] as $secret) {
        same(false, str_contains(json_encode($share), $secret), 'no credential in result');
    }
    same('POST', $history[0]['request']->getMethod(), 'token method');
    same('https://open.douyin.com/oauth/client_token/', (string)$history[0]['request']->getUri(), 'token URL');
    same(['client_key' => 'test-key', 'client_secret' => 'test-secret', 'grant_type' => 'client_credential'],
        json_decode((string)$history[0]['request']->getBody(), true), 'token JSON body');
    same('GET', $history[1]['request']->getMethod(), 'ticket method');
    same('https://open.douyin.com/open/getticket/', (string)$history[1]['request']->getUri(), 'ticket URL');
    same('server-token', $history[1]['request']->getHeaderLine('access-token'), 'ticket auth header');
    same('application/json', $history[1]['request']->getHeaderLine('Content-Type'), 'ticket content type');
    same(false, $history[0]['options']['allow_redirects'], 'no credential redirects');
    same(true, $history[0]['options']['verify'], 'TLS verified');

    $cachedHistory = [];
    $cachedShare = fixture($root . '/basic', [], $cachedHistory)->createShare($image);
    same([], $cachedHistory, 'cache reused across service instances');
    same(false, $cachedShare['params']['nonce_str'] === $share['params']['nonce_str'], 'fresh nonce for each share');
    same(0, $cachedShare['params']['share_to_publish'], 'image uses edit flow');

    $listShare = $service->createShare(['image_list_path' => ['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg'],
        'feature' => 'note', 'private_status' => 0, 'download_type' => 2,
        'micro_app_info' => ['appId' => 'tt123', 'appTitle' => '门店', 'description' => '介绍', 'appUrl' => 'pages/store?id=1']]);
    same(2, count(json_decode($listShare['params']['image_list_path'], true)), 'image list JSON');
    same('门店', json_decode($listShare['params']['micro_app_info'], true)['appTitle'], 'mini-app JSON');
    same('note', $listShare['params']['feature'], 'note media');
    same(false, isset($service->createShare($video + $image)['params']['image_path']), 'video takes precedence');

    $history = [];
    fixture($root . '/basic', [token('other-token'), ticket('other-ticket')], $history, ['client_key' => 'other-key'])->createShare($image);
    same(2, count($history), 'applications do not share credentials');
    $history = [];
    fixture($root . '/basic', [token(), ticket()], $history, ['client_secret' => 'rotated-secret'])->createShare($image);
    same(2, count($history), 'secret rotation invalidates old credentials');

    // ticket 到期时复用尚未到期的 token；忽略过期但仍存在于缓存中的条目。
    $prefix = 'douyin_h5_' . hash('sha256', 'test-key') . '_' . hash('sha256', 'test-secret') . '_';
    $cache = fileCache($root . '/basic');
    $cache->set($prefix . 'open_ticket', ['value' => 'expired-ticket', 'expires_at' => time() - 1], 100);
    $history = [];
    fixture($root . '/basic', [ticket('fresh-ticket')], $history)->createShare($image);
    same(1, count($history), 'only ticket refreshed');
    same('GET', $history[0]['request']->getMethod(), 'valid token reused');
    $cache->delete($prefix . 'open_ticket');
    $cache->set($prefix . 'client_token', ['value' => 'expired-token', 'expires_at' => time() - 1], 100);
    $history = [];
    fixture($root . '/basic', [token(), ticket()], $history)->createShare($image);
    same(2, count($history), 'expired token refreshed');

    foreach ([2190002, 2190008] as $code) {
        $history = [];
        fixture($root . '/retry-' . $code, [token('old'), response(['error_code' => $code]), token('new'), ticket()], $history)->createShare($image);
        same(4, count($history), 'invalid token retried once');
        same('new', $history[3]['request']->getHeaderLine('access-token'), 'retry uses fresh token');
    }
    $history = [];
    $retryService = fixture($root . '/retry-limit', [token(), response(['error_code' => 2190008]), token(), response(['error_code' => 2190008])], $history);
    fails(fn () => $retryService->createShare($image), '2190008');
    same(4, count($history), 'retry bounded');
    same(null, (new DouyinCredentialCache(fileCache($root . '/retry-limit'), 'test-key', 'test-secret'))->get('client_token'), 'rejected token evicted');

    $history = [];
    $shortStart = time();
    $shortShare = fixture($root . '/short-ttl', [token('short-token', 20), ticket('short-ticket', 20)], $history)->createShare($image);
    same(true, $shortShare['ticket_expires_at'] >= $shortStart + 18 && $shortShare['ticket_expires_at'] <= time() + 18, 'short TTL never extended');

    foreach ([new Response(503, [], 'test-secret'), new Response(302, ['Location' => 'https://other.example.com']),
        new Response(200, [], 'not json test-secret'), new Response(200, [], '{}'), response(['error_code' => null]),
        token('', 7200), token('token', 0), token('token', -1), response(['error_code' => 0, 'access_token' => 'token']),
        response(['error_code' => 10013, 'description' => 'test-secret']), new RuntimeException('request test-secret')] as $index => $bad) {
        $history = [];
        $broken = fixture($root . '/bad-' . $index, [$bad], $history);
        $error = fails(fn () => $broken->createShare($image), '抖音');
        same(false, str_contains($error->getMessage(), 'test-secret'), 'upstream secrets redacted');
        same(null, $error->getPrevious(), 'no secret-bearing exception chain');
        $recoveryHistory = [];
        fixture($root . '/bad-' . $index, [token(), ticket()], $recoveryHistory)->createShare($image);
        same(2, count($recoveryHistory), 'failed token not cached and refresh lock released');
    }
    $history = [];
    $denied = fixture($root . '/denied', [token(), response(['error_code' => 2190004, 'description' => 'server-token'])], $history);
    fails(fn () => $denied->createShare($image), '2190004');
    same(2, count($history), 'permission failures are not retried');

    $history = [];
    $badTicket = fixture($root . '/bad-ticket', [token(), ticket('', 7200)], $history);
    fails(fn () => $badTicket->createShare($image), '凭据');
    $recoveryHistory = [];
    fixture($root . '/bad-ticket', [ticket()], $recoveryHistory)->createShare($image);
    same(1, count($recoveryHistory), 'invalid ticket not cached, successful token retained');

    $invalidInputs = [[], ['title' => 'text only'], ['image_path' => '/a.jpg'], ['image_path' => 'file:///a.jpg'],
        ['image_path' => 'https://user:pass@example.com/a.jpg'], ['image_path' => 'https://cdn.example.com/中文.jpg'],
        ['image_path' => 'https://cdn.example.com/a.jpg#fragment'], ['image_list_path' => '["https://cdn.example.com/a.jpg"]'],
        $image + ['signature' => 'forged'], $image + ['client_key' => 'override'], $image + ['share_to_publish' => 1],
        $image + ['title' => []], $image + ['hashtag_list' => ['']], $image + ['hashtag_list' => ['key' => 'tag']],
        $image + ['title_hashtag_list' => [['name' => 'tag', 'start' => -1]]], $image + ['private_status' => 9],
        $image + ['share_to_type' => 1, 'download_type' => 1], $image + ['feature' => 'note'],
        $image + ['micro_app_info' => ['appId' => 'tt123']], $image + ['share_to_type' => '0']];
    $history = [];
    $validator = fixture($root . '/validation', [], $history);
    foreach ($invalidInputs as $input) {
        fails(fn () => $validator->createShare($input), '', InvalidArgumentException::class);
    }
    same([], $history, 'invalid content never contacts Douyin');
    fails(fn () => new DouyinH5ShareService(['client_key' => '']), 'client_key');

    // 不依赖本机 Redis 服务，检查 Predis 锁协议以及锁过期换主后的释放行为。
    $redis = new class {
        public array $values = [];
        public array $lastSet = [];
        public function set(string $key, string $value, string $ex, int $ttl, string $nx): ?string
        {
            $this->lastSet = [$ex, $ttl, $nx];
            if (isset($this->values[$key])) return null;
            $this->values[$key] = $value;
            return 'OK';
        }
        public function eval(string $script, int $numKeys, string $key, string $owner): int
        {
            same(1, $numKeys, 'one lock key');
            same(true, str_contains($script, 'redis.call("get", KEYS[1]) == ARGV[1]'), 'atomic owner check');
            if (($this->values[$key] ?? null) !== $owner) return 0;
            unset($this->values[$key]);
            return 1;
        }
    };
    $redisDriver = new class($redis) extends \think\cache\driver\Redis {
        public function __construct(private object $testHandler) { parent::__construct(['prefix' => 'test:']); }
        public function handler() { return $this->testHandler; }
    };
    $lockedCache = new DouyinCredentialCache($redisDriver, 'redis-key', 'redis-secret');
    same(['ok'], $lockedCache->synchronized(fn () => ['ok']), 'Redis callback result');
    same(['EX', 60, 'NX'], $redis->lastSet, 'bounded atomic Redis lease');
    same([], $redis->values, 'Redis lock released on success');
    fails(fn () => $lockedCache->synchronized(static function (): array { throw new RuntimeException('refresh failed'); }), 'refresh failed');
    same([], $redis->values, 'Redis lock released on failure');
    $lockedCache->synchronized(static function () use ($redis): array {
        $redis->values[array_key_first($redis->values)] = 'new-owner';
        return [];
    });
    same(['new-owner'], array_values($redis->values), 'expired owner cannot release replacement lock');

    // 真实多进程验证 file 刷新锁和锁内二次读取，避免 Mock 掩盖缓存击穿。
    $concurrent = $root . '/concurrent';
    mkdir($concurrent, 0770, true);
    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $concurrent],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start concurrency worker');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process), 'concurrent worker: ' . $output);
    }
    same("POST\nGET\n", file_get_contents($concurrent . '/requests.log'), 'concurrent requests fetch credentials only once');
    echo "PASS Douyin H5 signature, schema, payload validation, credential cache, refresh, redaction and concurrency\n";
} finally {
    // 仅清理由本次测试在 runtime 下新建的随机目录。
    $resolved = realpath($root);
    $runtime = realpath(dirname(__DIR__, 2) . '/runtime');
    if ($resolved !== false && $runtime !== false && str_starts_with($resolved, $runtime . DIRECTORY_SEPARATOR . 'douyin-test-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }
}
