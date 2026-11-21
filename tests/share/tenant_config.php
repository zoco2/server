<?php

declare(strict_types=1);

use app\common\service\douyin\DouyinH5ShareService;
use app\common\service\share\TenantShareConfigService;
use app\common\service\xiaohongshu\XiaohongshuH5ShareService;
use app\tenantapi\controller\channel\ShareSettingController;
use app\tenantapi\http\middleware\AuthMiddleware;
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
Db::execute('CREATE TABLE test_tenant_system_menu (id INTEGER PRIMARY KEY, tenant_id INTEGER, perms TEXT, is_disable INTEGER)');
Db::execute('CREATE TABLE test_tenant_admin_role (id INTEGER PRIMARY KEY, admin_id INTEGER, role_id INTEGER, tenant_id INTEGER)');
Db::execute('CREATE TABLE test_tenant_system_role_menu (id INTEGER PRIMARY KEY, role_id INTEGER, menu_id INTEGER, tenant_id INTEGER)');

function requestFor(App $app, string $method, array $params = [], string $action = 'setConfig'): ShareSettingController
{
    $request = new Request();
    $request->setMethod($method)->setController('channel.ShareSetting')->setAction($action);
    $request->withServer(['REMOTE_ADDR' => '127.0.0.1'])->withPost($params);
    $request->tenantId = 1;
    $request->adminInfo = ['tenant_id' => 1, 'admin_id' => 10, 'root' => 1, 'login_ip' => '127.0.0.1'];
    $app->instance('request', $request);
    $controller = new ShareSettingController($app);
    $request->controllerObject = $controller;
    return $controller;
}

try {
    $one = new TenantShareConfigService(1);
    $two = new TenantShareConfigService(2);
    $split = new TenantShareConfigService(3);
    foreach ($one->display() as $platform) {
        check($platform['enabled'] === 1 && !$platform['configured'], 'New platform should await credentials');
    }
    fails(fn () => new TenantShareConfigService(0), '租户');
    fails(fn () => (new TenantShareConfigService(99))->display(), '租户不存在');
    $douyin = ['platform' => 'douyin', 'client_key' => 'tenant-one', 'client_secret' => 'one-secret'];
    $one->save($douyin);
    $two->save(array_replace($douyin, ['client_key' => 'tenant-two', 'client_secret' => 'two-secret']));
    $split->save(array_replace($douyin, ['client_key' => 'tenant-split', 'client_secret' => 'split-secret']));
    $xhs = ['platform' => 'xiaohongshu', 'app_key' => 'xhs-one', 'app_secret' => 'xhs-secret'];
    $one->save($xhs);
    check($one->runtime('douyin')['client_secret'] === 'one-secret', 'Tenant one secret');
    check($two->runtime('douyin')['client_secret'] === 'two-secret', 'Tenant two secret');
    check($split->runtime('douyin')['client_secret'] === 'split-secret', 'Split tenant secret');
    check(Db::name('tenant_config')->where('tenant_id', 3)->count() === 0, 'Split tenant never writes shared table');
    $stored = Db::name('tenant_config')->where('tenant_id', 1)->where('name', 'douyin')->value('value');
    check(!str_contains($stored, 'one-secret') && str_contains($stored, 'enc:v1:'), 'Secret encrypted at rest');
    $display = $one->display();
    check($display['douyin']['client_secret'] === 'one-secret' && $display['xiaohongshu']['app_secret'] === 'xhs-secret', 'Both saved secrets must be editable');
    check($two->display()['douyin']['client_secret'] === 'two-secret' && $split->display()['douyin']['client_secret'] === 'split-secret', 'Form credentials are tenant scoped');
    check($display['douyin']['client_secret_set'] && $display['xiaohongshu']['configured'], 'Status returned');
    foreach (['douyin', 'xiaohongshu'] as $name) {
        $query = Db::name('tenant_config')->where('tenant_id', 1)->where('name', $name);
        $legacy = json_decode((clone $query)->value('value'), true);
        $legacy['enabled'] = 0;
        $query->update(['value' => json_encode($legacy)]);
        check($one->display()[$name]['enabled'] === 1 && $one->runtime($name), 'Existing credentials must work without re-saving');
    }
    $one->save(array_replace($douyin, ['client_secret' => '']));
    check($one->runtime('douyin')['client_secret'] === 'one-secret', 'Blank secret preserves original');
    check(Db::name('tenant_config')->where('tenant_id', 1)->where('name', 'douyin')->count() === 1, 'Save updates existing row');
    fails(fn () => $one->save(array_replace($douyin, ['client_key' => 'new-key', 'client_secret' => ''])), '对应的新密钥');
    check($one->runtime('douyin')['client_key'] === 'tenant-one', 'Rejected update leaves original intact');
    foreach ([['client_key' => []], ['client_secret' => ['bad']],
        ['client_key' => str_repeat('x', 129)], ['client_secret' => 'masked****'], ['client_secret' => "bad\nsecret"],
        ['platform' => 'kuaishou'], ['platform' => []]] as $invalid) {
        fails(fn () => $one->save(array_replace($douyin, $invalid)), '');
    }
    fails(fn () => $two->save(['platform' => 'xiaohongshu', 'app_key' => '']), '分享应用标识和密钥');
    check($one->runtime('xiaohongshu')['app_secret'] === 'xhs-secret', 'Other platform preserved');

    // Controller trusts the authenticated administrator, not request tenant_id or tenant context overrides.
    $controller = requestFor($app, 'POST', array_replace($douyin, ['tenant_id' => 2, 'client_secret' => 'rotated-secret']));
    request()->tenantId = 2;
    check($controller->setConfig()->getData()['code'] === 1, 'Controller save succeeds');
    check($one->runtime('douyin')['client_secret'] === 'rotated-secret', 'Uses administrator tenant');
    check($two->runtime('douyin')['client_secret'] === 'two-secret', 'Forged tenant ignored');
    check(requestFor($app, 'GET')->setConfig()->getData()['code'] === 0, 'GET cannot write credentials');
    check(requestFor($app, 'POST', [], 'getConfig')->getConfig()->getData()['code'] === 0, 'Read method constrained');
    $controller = requestFor($app, 'GET', [], 'getConfig');
    $detail = $controller->getConfig();
    check($detail->getData()['data']['douyin']['client_secret'] === 'rotated-secret', 'Rotated secret in read response');
    Db::execute('CREATE TABLE test_operation_log (id INTEGER PRIMARY KEY, admin_id INTEGER, admin_name TEXT,
        action TEXT, account TEXT, url TEXT, type TEXT, params TEXT, ip TEXT, result TEXT, create_time INTEGER, update_time INTEGER)');
    check((bool)(new \app\tenantapi\listener\OperationLog())->handle($detail), 'Share operation logged');
    $log = json_decode(Db::name('operation_log')->where('admin_id', 10)->value('result'), true);
    check(isset($log['data']['douyin']['client_key'], $log['data']['xiaohongshu']['app_key']), 'Platform identifiers are logged');
    check(!isset($log['data']['douyin']['client_secret']) && !isset($log['data']['xiaohongshu']['app_secret']), 'Share secrets omitted from log');

    // 使用既有权限机制；独立升级 SQL 注册查看和保存权限。
    Db::name('tenant_system_menu')->insertAll([
        ['id' => 101, 'tenant_id' => 1, 'perms' => 'channel.share_setting/getConfig', 'is_disable' => 0],
        ['id' => 102, 'tenant_id' => 1, 'perms' => 'channel.share_setting/setConfig', 'is_disable' => 0],
    ]);
    $controller = requestFor($app, 'GET', [], 'getConfig');
    $info = request()->adminInfo;
    $info['root'] = 0;
    request()->adminInfo = $info;
    $response = (new AuthMiddleware())->handle(request(), static fn () => throw new RuntimeException('Unauthorised access'));
    check($response->getData()['code'] === 0, 'Registered endpoint requires existing role permission');

    // Both real services use tenant credentials when preparing a signed share, with mocked platform HTTP.
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['data' => ['error_code' => 0, 'access_token' => 'mock-token', 'expires_in' => 7200]])),
        new Response(200, [], json_encode(['data' => ['error_code' => 0, 'ticket' => 'mock-ticket', 'expires_in' => 7200]])),
        new Response(200, [], json_encode(['access_token' => 'xhs-token', 'expires_in' => (int)(microtime(true) * 1000) + 7200000])),
    ]));
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack]);
    $share = DouyinH5ShareService::forTenant(1, $http)->createShare(['video_path' => 'https://cdn.example.com/v.mp4']);
    check($share['params']['client_key'] === 'tenant-one', 'Douyin tenant key');
    check(json_decode((string)$history[0]['request']->getBody(), true)['client_secret'] === 'rotated-secret', 'Rotated secret used');
    $share = XiaohongshuH5ShareService::forTenant(1, $http)->createShare(['type' => 'normal', 'images' => ['https://cdn.example.com/a.jpg']]);
    check($share['verifyConfig']['appKey'] === 'xhs-one', 'XHS tenant key');
    fails(fn () => XiaohongshuH5ShareService::forTenant(2, $http), '分享凭据不可用');
    $two->save(array_replace($douyin, ['client_key' => 'tenant-two', 'client_secret' => '', 'enabled' => 0]));
    request()->tenantId = 2;
    check(new DouyinH5ShareService() instanceof DouyinH5ShareService, 'Legacy disable input cannot disable sharing');
    fails(fn () => new XiaohongshuH5ShareService(), '分享凭据不可用');
    request()->tenantId = 1;
    check(new DouyinH5ShareService() instanceof DouyinH5ShareService, 'Default constructor uses request tenant');
    Db::name('tenant')->where('id', 2)->update(['delete_time' => time()]);
    fails(fn () => $two->display(), '租户不存在');
    echo "PASS tenant share config: encryption, isolation, split tables, validation, secret rotation, controller, existing permissions and signed shares\n";
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
