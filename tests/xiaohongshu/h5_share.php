<?php

declare(strict_types=1);

use app\common\service\xiaohongshu\XiaohongshuH5ShareService;
use app\common\service\xiaohongshu\XiaohongshuTokenCache;
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
    throw new RuntimeException('Expected failure: ' . $message);
}

function response(array $body): Response
{
    return new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR));
}

function token(string $value = 'server-token', ?int $expiresAt = null, bool $wrapped = true): Response
{
    $data = ['access_token' => $value, 'expires_in' => $expiresAt ?? XiaohongshuTokenCache::nowMs() + 7200000];
    return response($wrapped ? ['code' => 0, 'success' => true, 'data' => $data] : $data);
}

function fileCache(string $path): File
{
    return new File(new App(dirname(__DIR__, 2)), ['path' => $path]);
}

function fixture(string $path, array $responses, array &$history, array $override = []): XiaohongshuH5ShareService
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new XiaohongshuH5ShareService($override + ['app_key' => 'test-key', 'app_secret' => 'test-secret'],
        new Client(['handler' => $stack]), fileCache($path));
}

if (($argv[1] ?? '') === 'worker') {
    $directory = $argv[2];
    $record = static function ($request) use ($directory): Response {
        usleep(200000);
        file_put_contents($directory . '/requests.log', $request->getMethod() . "\n", FILE_APPEND | LOCK_EX);
        return token();
    };
    $history = [];
    fixture($directory, [$record], $history)->createShare(['type' => 'normal', 'images' => ['https://cdn.example.com/a.jpg']]);
    exit(0);
}

$root = dirname(__DIR__, 2) . '/runtime/xiaohongshu-test-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
$image = ['type' => 'normal', 'images' => ['https://cdn.example.com/a.jpg?key=a+b%2Fc&v=1', 'https://cdn.example.com/%E5%9B%BE.jpg']];
$video = ['type' => 'video', 'video' => 'https://cdn.example.com/a.mp4', 'cover' => 'https://cdn.example.com/cover.jpg'];

try {
    // 官方 Java 示例入参，预期值由独立 Node crypto SHA-256 计算交叉验证。
    same('16c900bfce9d55a78406a8d5a0be0b04cd4881bde8ade0b7bc9ae7ea2d32c506',
        XiaohongshuH5ShareService::signature('123', 'nonce', '1692102691696', 'test'), 'official signing sample');
    same('0d2ec3bbb6a3bd4ae871de922648d9592c2fd7c943456bdb5ebc0b007b4f7d0a',
        XiaohongshuH5ShareService::signature('app+key', 'nonce_1', '1692102691696', 'secret+/=&中文'), 'raw UTF-8 signing without URL encoding');
    foreach ([['key', '', '1692102691696', 'secret'], ['key', str_repeat('a', 33), '1692102691696', 'secret'],
        ['key', 'nonce', '1692102691', 'secret'], ['key', 'nonce', '1692102691696', '']] as $args) {
        fails(fn () => XiaohongshuH5ShareService::signature(...$args), '签名', InvalidArgumentException::class);
    }

    $history = [];
    $expiry = XiaohongshuTokenCache::nowMs() + 7200000;
    $service = fixture($root . '/basic', [token('server-token', $expiry)], $history);
    $before = XiaohongshuTokenCache::nowMs();
    $share = $service->createShare($image);
    same($image, $share['shareInfo'], 'media URL query and encoding preserved');
    same($expiry, $share['token_expires_at_ms'], 'absolute millisecond expiry returned unchanged');
    same(XiaohongshuH5ShareService::SDK_URL, $share['sdk_url'], 'official SDK URL');
    $verify = $share['verifyConfig'];
    same(['appKey', 'nonce', 'timestamp', 'signature'], array_keys($verify), 'JS SDK verification names');
    same(true, is_string($verify['timestamp']) && (int)$verify['timestamp'] >= $before
        && (int)$verify['timestamp'] <= XiaohongshuTokenCache::nowMs(), 'JS timestamp is current milliseconds as string');
    same(32, strlen($verify['nonce']), 'nonce within official length');
    same(XiaohongshuH5ShareService::signature('test-key', $verify['nonce'], $verify['timestamp'], 'server-token'),
        $verify['signature'], 'second signature uses token');
    same(false, $verify['signature'] === XiaohongshuH5ShareService::signature('test-key', $verify['nonce'], $verify['timestamp'], 'test-secret'), 'second signature does not use app secret');
    foreach (['test-secret', 'server-token'] as $secret) {
        same(false, str_contains(json_encode($share), $secret), 'no credentials in response');
    }
    same(1, count($history), 'only one upstream token request');
    same('POST', $history[0]['request']->getMethod(), 'token HTTP method');
    same('https://edith.xiaohongshu.com/api/sns/v1/ext/access/token', (string)$history[0]['request']->getUri(), 'token URL');
    same('application/json', $history[0]['request']->getHeaderLine('Content-Type'), 'JSON request');
    $request = json_decode((string)$history[0]['request']->getBody(), true);
    same('test-key', $request['app_key'], 'wire app_key uses snake case');
    same(true, is_int($request['timestamp']), 'wire timestamp is int64 milliseconds');
    same($request['timestamp'] + 86400000, $request['expires_in'], 'requested expiry is absolute time, not TTL');
    same(XiaohongshuH5ShareService::signature('test-key', $request['nonce'], (string)$request['timestamp'], 'test-secret'),
        $request['signature'], 'first signature uses app secret');
    same(false, array_key_exists('app_secret', $request), 'app secret not sent in request');
    same(false, $history[0]['options']['allow_redirects'], 'no credential redirect');
    same(true, $history[0]['options']['verify'], 'TLS verification enabled');
    same(10, $history[0]['options']['timeout'], 'bounded HTTP request');

    $cached = new XiaohongshuTokenCache(fileCache($root . '/basic'), 'test-key', 'test-secret');
    same($expiry - 120000, $cached->get()['refresh_at_ms'], 'early refresh margin');
    $cachedHistory = [];
    $second = fixture($root . '/basic', [], $cachedHistory)->createShare($video);
    same($video, $second['shareInfo'], 'video and optional cover');
    same([], $cachedHistory, 'token shared across service instances');
    same(false, $second['verifyConfig']['nonce'] === $verify['nonce'], 'fresh per-share nonce');
    $withoutCover = $video;
    unset($withoutCover['cover']);
    same($withoutCover, $service->createShare($withoutCover)['shareInfo'], 'video cover optional');

    foreach ([['app_key' => 'another-key'], ['app_secret' => 'rotated-secret']] as $override) {
        $history = [];
        fixture($root . '/basic', [token()], $history, $override)->createShare($image);
        same(1, count($history), 'application and secret isolation');
    }
    $cacheKey = 'xiaohongshu_share_' . hash('sha256', 'test-key') . '_' . hash('sha256', 'test-secret');
    $driver = fileCache($root . '/basic');
    $driver->set($cacheKey, ['access_token' => 'stale-token', 'expires_at_ms' => $expiry,
        'refresh_at_ms' => XiaohongshuTokenCache::nowMs() - 1], 100);
    $history = [];
    fixture($root . '/basic', [token('fresh-token', null, false)], $history)->createShare($image);
    same(1, count($history), 'refresh deadline checked even when cache file still exists');
    same('fresh-token', $cached->get()['access_token'], 'direct response fields accepted');

    $shortExpiry = XiaohongshuTokenCache::nowMs() + 20000;
    $history = [];
    fixture($root . '/short', [token('short-token', $shortExpiry)], $history)->createShare($image);
    $short = (new XiaohongshuTokenCache(fileCache($root . '/short'), 'test-key', 'test-secret'))->get();
    same($shortExpiry, $short['expires_at_ms'], 'short upstream expiry never extended');
    same(true, $short['refresh_at_ms'] <= $shortExpiry && $short['refresh_at_ms'] >= $shortExpiry - 2000, 'short expiry margin scales down');

    $invalid = [[], ['type' => 'text'], ['type' => 'normal', 'images' => []], ['type' => 'normal', 'images' => '[]'],
        ['type' => 'normal', 'images' => ['key' => 'https://cdn.example.com/a.jpg']], ['type' => 'video'],
        ['type' => 'video', 'video' => 'data:video/mp4;base64,a'], $image + ['title' => 'title'],
        $image + ['content' => '正文'], $image + ['hashtag_list' => ['探店']], $image + ['appKey' => 'override'],
        $image + ['verifyConfig' => ['signature' => 'forged']], $image + ['video' => 'https://cdn.example.com/a.mp4'],
        $image + ['cover' => 'https://cdn.example.com/a.jpg'], $video + ['images' => ['https://cdn.example.com/a.jpg']]];
    foreach (['/relative.jpg', 'file:///a.jpg', 'https://user:pass@cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg#fragment',
        'https://cdn.example.com/中文.jpg', 'https://cdn.example.com/a b.jpg', "https://cdn.example.com/a\n.jpg", [], null] as $url) {
        $invalid[] = ['type' => 'normal', 'images' => [$url]];
    }
    $history = [];
    $validator = fixture($root . '/invalid', [], $history);
    foreach ($invalid as $input) {
        fails(fn () => $validator->createShare($input), '', InvalidArgumentException::class);
    }
    same([], $history, 'invalid share does not request token');
    fails(fn () => new XiaohongshuH5ShareService(['app_key' => '']), 'app_key');

    $future = XiaohongshuTokenCache::nowMs() + 7200000;
    foreach ([new Response(500, [], 'test-secret'), new Response(302, ['Location' => 'https://other.example.com']),
        new Response(200, [], 'invalid JSON test-secret'), new Response(200, [], '[]'), response(['data' => null]),
        response(['code' => null]), response(['code' => 401, 'msg' => 'server-token']),
        response(['code' => 401, 'access_token' => 'server-token', 'expires_in' => $future]),
        response(['success' => false, 'data' => ['access_token' => 'server-token', 'expires_in' => $future]]),
        response(['success' => 'true', 'data' => ['access_token' => 'server-token', 'expires_in' => $future]]),
        response(['access_token' => 'server-token']), token('', $future), token('server-token', 7200),
        token('server-token', time() + 7200), token('server-token', XiaohongshuTokenCache::nowMs() - 1),
        token('server-token', XiaohongshuTokenCache::nowMs() + 172800000), new RuntimeException('test-secret')] as $index => $bad) {
        $history = [];
        $broken = fixture($root . '/bad-' . $index, [$bad], $history);
        $error = fails(fn () => $broken->createShare($image), '小红书');
        same(1, count($history), 'token request not automatically repeated');
        same(false, str_contains($error->getMessage(), 'test-secret') || str_contains($error->getMessage(), 'server-token'), 'errors redact secrets');
        same(null, $error->getPrevious(), 'no secret-bearing previous exception');
        $recovery = [];
        fixture($root . '/bad-' . $index, [token()], $recovery)->createShare($image);
        same(1, count($recovery), 'failed token not cached and refresh lock released');
    }

    $failingCache = new class(new App(dirname(__DIR__, 2)), ['path' => $root . '/write-failure']) extends File {
        public function set($name, $value, $expire = null): bool { return false; }
    };
    $writer = new XiaohongshuH5ShareService(['app_key' => 'test-key', 'app_secret' => 'test-secret'],
        new Client(['handler' => HandlerStack::create(new MockHandler([token()]))]), $failingCache);
    fails(fn () => $writer->createShare($image), '缓存写入失败');

    // 真正创建四个 PHP 进程：同一应用冷缓存仅允许一次 token 请求。
    $concurrent = $root . '/concurrent';
    mkdir($concurrent, 0770, true);
    $workers = [];
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $concurrent],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process), 'concurrent worker: ' . $output);
    }
    same("POST\n", file_get_contents($concurrent . '/requests.log'), 'only one token created under concurrency');
    echo "PASS Xiaohongshu H5 signatures, milliseconds, media, validation, token cache, errors and concurrency\n";
} finally {
    $resolved = realpath($root);
    $runtime = realpath(dirname(__DIR__, 2) . '/runtime');
    if ($resolved !== false && $runtime !== false && str_starts_with($resolved, $runtime . DIRECTORY_SEPARATOR . 'xiaohongshu-test-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }
}
