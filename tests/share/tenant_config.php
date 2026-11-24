<?php

declare(strict_types=1);

use app\common\service\douyin\DouyinH5ShareService;
use app\common\service\share\PlatformShareConfigService;
use app\common\service\xiaohongshu\XiaohongshuH5ShareService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;
use think\Model;
use think\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

function fails(callable $call, string $message): void
{
    try {
        $call();
    } catch (RuntimeException | InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), $message), 'Unexpected exception: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected error: ' . $message);
}

// 独立内存数据库；不初始化 App，不加载本地 .env 或连接业务数据库。
$app = new App(dirname(__DIR__, 2));
$root = dirname(__DIR__, 2) . '/runtime/share-config-test-' . bin2hex(random_bytes(8));
Config::set(['default' => 'fixture', 'connections' => ['fixture' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_',
    'fields_strict' => true, 'trigger_sql' => false,
]]], 'database');
Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $root]]], 'cache');
Config::set(['client_key' => 'global-key', 'client_secret' => 'global-secret'], 'douyin');
Config::set(['app_key' => 'global-xhs', 'app_secret' => 'global-xhs-secret'], 'xiaohongshu');
Env::set(['wechat' => ['credential_key' => 'fixture-only-encryption-key'], 'database' => ['prefix' => 'test_']]);
Model::setDb($app->make('db'));
Db::execute('CREATE TABLE test_tenant (id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER, delete_time INTEGER)');
Db::name('tenant')->insertAll([
    ['id' => 1, 'sn' => 'first', 'tactics' => 0],
    ['id' => 2, 'sn' => 'second', 'tactics' => 0],
    ['id' => 3, 'sn' => 'split', 'tactics' => 1],
]);
foreach (['tenant_config', 'tenant_config_split'] as $table) {
    Db::execute('CREATE TABLE test_' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
        type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
}
Db::execute('CREATE TABLE test_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
$app->instance('request', new Request());

try {
    $request = new Request();
    $request->source = \app\common\enum\AdminTerminalEnum::PLATFORM;
    $app->instance('request', $request);
    $douyin = ['platform'=>'douyin','client_key'=>'platform-douyin','client_secret'=>'platform-secret'];
    $xhs = ['platform'=>'xiaohongshu','app_key'=>'platform-xhs','app_secret'=>'xhs-secret'];
    PlatformShareConfigService::save($douyin);
    PlatformShareConfigService::save($xhs);
    $display = PlatformShareConfigService::display();
    check($display['douyin']['configured'] && !isset($display['douyin']['client_secret']), 'Share credentials are masked');
    fails(fn () => PlatformShareConfigService::save(array_replace($douyin,['client_key'=>'changed-key','client_secret'=>''])), '对应的新密钥');
    PlatformShareConfigService::save(array_replace($douyin, ['client_secret'=>'']));
    check(PlatformShareConfigService::runtime('douyin')['client_secret'] === 'platform-secret', 'Blank secret preserves configured key');
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['data' => ['error_code' => 0, 'access_token' => 'mock-token', 'expires_in' => 7200]])),
        new Response(200, [], json_encode(['data' => ['error_code' => 0, 'ticket' => 'mock-ticket', 'expires_in' => 7200]])),
        new Response(200, [], json_encode(['access_token' => 'xhs-token', 'expires_in' => (int)(microtime(true) * 1000) + 7200000])),
    ]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $share = DouyinH5ShareService::forTenant(1, $http)->createShare(['video_path'=>'https://cdn.example.com/v.mp4']);
    check($share['params']['client_key'] === 'platform-douyin', 'Douyin uses the platform configuration');
    $share = XiaohongshuH5ShareService::forTenant(2, $http)->createShare(['type'=>'normal','images'=>['https://cdn.example.com/a.jpg']]);
    check($share['verifyConfig']['appKey'] === 'platform-xhs', 'Xiaohongshu uses the platform configuration');
    echo "PASS platform share credentials and signed shares\n";
} finally {
    $resolved = realpath($root);
    $runtime = realpath(dirname(__DIR__, 2) . '/runtime');
    if ($resolved !== false && $runtime !== false && str_starts_with($resolved, $runtime . DIRECTORY_SEPARATOR . 'share-config-test-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
    }
}
