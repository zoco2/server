<?php

declare(strict_types=1);

use app\api\controller\PublishTaskController;
use app\common\service\douyin\DouyinCredentialCache;
use app\common\service\douyin\DouyinH5ShareService;
use app\common\service\publish_task\PublishTaskService;
use app\common\service\share\TenantShareConfigService;
use think\App;
use think\facade\Cache;
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

$app = new App(dirname(__DIR__, 2));
$cacheDir = sys_get_temp_dir() . '/publish-task-share-' . bin2hex(random_bytes(8));
Config::set(['default' => 'fixture', 'connections' => ['fixture' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'trigger_sql' => false,
]]], 'database');
Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $cacheDir]]], 'cache');
Config::set(['default' => 'mns', 'channels' => ['mns' => ['type' => 'file', 'path' => $cacheDir]]], 'log');
Env::set(['database' => ['prefix' => 'test_'], 'wechat' => ['credential_key' => 'fixture-encryption-key']]);
Model::setDb($app->make('db'));
$request = new Request();
$request->setMethod('POST')->setAction('douyinShare')->withServer(['HTTP_HOST' => 'tenant.example', 'HTTPS' => 'on', 'REQUEST_METHOD' => 'POST']);
$request->tenantId = 1;
$app->instance('request', $request);
$controller = new PublishTaskController($app);

Db::execute('CREATE TABLE test_tenant (id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER, delete_time INTEGER)');
Db::execute('CREATE TABLE test_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_merchant_staff (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, store_id INTEGER, appid TEXT, openid TEXT, delete_time INTEGER)');
Db::execute('CREATE TABLE test_merchant_store (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, brand_id INTEGER, delete_time INTEGER)');
Db::execute('CREATE TABLE test_publish_task_run (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, task_id INTEGER, tasksn TEXT)');
Db::execute('CREATE TABLE test_publish_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, staff_ids TEXT, content TEXT, platforms TEXT,
    douyin_file_id INTEGER, push_mode TEXT, schedule_type TEXT, push_time TEXT, weekday INTEGER, start_date TEXT, end_date TEXT, notice_type TEXT, status TEXT,
    next_run_at INTEGER, tasksn TEXT, last_run_at INTEGER, last_run_status TEXT, last_error TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_tenant_file (id INTEGER PRIMARY KEY, tenant_id INTEGER, source INTEGER, source_id INTEGER, upload_origin TEXT,
    type INTEGER, uri TEXT, name TEXT, create_time INTEGER, storage_engine TEXT, storage_scope TEXT, storage_domain TEXT, delete_time INTEGER)');
Db::execute('CREATE TABLE test_user_asset_state (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, asset_ref TEXT, trashed_at INTEGER, hidden_at INTEGER)');
Db::name('tenant')->insert(['id' => 1, 'sn' => 'first', 'tactics' => 0]);
Db::name('merchant_staff')->insert(['id' => 3, 'tenant_id' => 1, 'user_id' => 2, 'store_id' => 5, 'appid' => 'wx', 'openid' => 'employee']);
Db::name('tenant_file')->insert(['id' => 4, 'tenant_id' => 1, 'source' => 1, 'source_id' => 2, 'upload_origin' => 'local',
    'type' => 20, 'uri' => 'uploads/clip.mp4', 'storage_engine' => 'local']);
$input = ['content' => '员工操作说明', 'staff_ids' => [3], 'platforms' => ['douyin'], 'push_mode' => 'immediate', 'douyin_file_id' => 4];
$id = PublishTaskService::create(1, 2, $input)['ids'][0];
$task = Db::name('publish_task')->where('id', $id);
$file = Db::name('tenant_file')->where('id', 4);
Db::name('publish_task_run')->insert(['id' => 7, 'tenant_id' => 1, 'user_id' => 2, 'task_id' => $id, 'tasksn' => $task->value('tasksn')]);
$token = (new ReflectionMethod(PublishTaskService::class, 'noticeToken'))->invoke(null, 1, 7, 3, $task->value('tasksn'), 'wx', 'employee');
$request->withPost(['token' => $token, 'tenant_id' => 99, 'video_path' => 'https://other.example/video.mp4']);
$call = static fn () => $controller->douyinShare()->getData();
$expectError = static function (string $message) use ($call): void {
    $response = $call();
    check($response['code'] === 0 && str_contains($response['msg'], $message), 'Expected error: ' . $message);
};
try {
    $expectError('抖音分享凭据不可用');
    (new TenantShareConfigService(1))->save(['platform' => 'douyin', 'enabled' => 1, 'client_key' => 'test-key', 'client_secret' => 'test-secret']);
    // 旧数据可能仍保存 enabled=0，凭据完整的通知应当可以生成分享链接。
    $config = Db::name('tenant_config')->where(['tenant_id' => 1, 'type' => 'social_share', 'name' => 'douyin']);
    $legacy = json_decode($config->value('value'), true, flags: JSON_THROW_ON_ERROR);
    $legacy['enabled'] = 0;
    $config->update(['value' => json_encode($legacy, JSON_THROW_ON_ERROR)]);
    // 使用测试票据运行真实签名逻辑，无需平台账号或网络请求。
    (new DouyinCredentialCache(Cache::store(), 'test-key', 'test-secret'))->set('open_ticket', ['value' => 'test-ticket', 'expires_at' => time() + 300]);
    check($controller->isNotNeedLogin(), 'Notification authorizes the employee without an account session');
    foreach (['video_path' => 'uploads/clip.mp4', 'image_path' => 'uploads/photo.jpg'] as $field => $uri) {
        $file->update(['type' => $field === 'video_path' ? 20 : 10, 'uri' => $uri]);
        $response = $call();
        check($response['code'] === 1 && array_keys($response['data']) === ['schema'], 'Expected only the share scheme');
        $schema = $response['data']['schema'];
        check(str_starts_with($schema, 'snssdk1128://openplatform/share?'), 'Official Douyin scheme');
        parse_str(parse_url($schema, PHP_URL_QUERY), $params);
        check($params[$field] === 'https://tenant.example/' . $uri && $params['client_key'] === 'test-key', 'Signed assigned media for the tenant');
        check($params['signature'] === DouyinH5ShareService::signature($params['nonce_str'], 'test-ticket', $params['timestamp']), 'Valid signature');
        check(!isset($params['title']), 'Employee instructions are not the post caption');
    }
    Db::execute('ALTER TABLE test_user_asset_state RENAME TO test_unavailable_state');
    $failure = $call();
    check($failure['code'] === 0 && preg_match('/排查编号 [a-f0-9]{12}$/u', $failure['msg']) === 1, 'Unexpected failures have a support reference');
    check(!str_contains($failure['msg'], 'test_user_asset_state'), 'Database errors are never public');
    Db::execute('ALTER TABLE test_unavailable_state RENAME TO test_user_asset_state');
    Db::name('user_asset_state')->insert(['tenant_id' => 1, 'user_id' => 2, 'asset_ref' => 'upload:result:4', 'hidden_at' => 100]);
    $expectError('素材已移除或不可访问');
    Db::name('user_asset_state')->where('id', 1)->update(['hidden_at' => 0]);
    $file->update(['source_id' => 99]);
    $expectError('素材已移除或不可访问');
    $file->update(['source_id' => 2, 'delete_time' => 100]);
    $expectError('素材已移除或不可访问');
    $task->update(['douyin_file_id' => null]);
    $expectError('尚未指定');
    check(PublishTaskService::publicDetail($token)['content'] === $input['content'], 'Text-only task still readable');
    $task->update(['platforms' => '["kuaishou"]']);
    $expectError('未选择抖音');
    $request->tenantId = 2;
    $expectError('任务链接无效');
    $request->tenantId = 1;
    Db::name('merchant_staff')->where('id', 3)->update(['openid' => '']);
    $expectError('任务链接无效');
    $request->withPost(['token' => 'invalid']);
    $expectError('任务链接无效');
    echo "PASS employee notification Douyin share: task creation, tenant, binding, ownership, image/video signatures and text-only tasks\n";
} finally {
    Cache::store()->clear();
    if (is_dir($cacheDir)) rmdir($cacheDir);
}
