<?php

declare(strict_types=1);

use app\api\controller\PublishTaskController;
use app\common\service\douyin\DouyinCredentialCache;
use app\common\service\douyin\DouyinH5ShareService;
use app\common\service\publish_task\PublishTaskMaterialService;
use app\common\service\publish_task\PublishTaskService;
use app\common\service\share\PlatformShareConfigService;
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
Db::execute('CREATE TABLE test_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_merchant_staff (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, store_id INTEGER, appid TEXT, openid TEXT, delete_time INTEGER)');
Db::execute('CREATE TABLE test_merchant_store (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, brand_id INTEGER, delete_time INTEGER)');
Db::execute('CREATE TABLE test_publish_task_run (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, task_id INTEGER, tasksn TEXT)');
Db::execute('CREATE TABLE test_publish_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, staff_ids TEXT, content TEXT, platforms TEXT,
    work_group_ids TEXT, push_mode TEXT, schedule_type TEXT, push_time TEXT, weekday INTEGER, start_date TEXT, end_date TEXT, notice_type TEXT, status TEXT,
    next_run_at INTEGER, tasksn TEXT, last_run_at INTEGER, last_run_status TEXT, last_error TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_tenant_file (id INTEGER PRIMARY KEY, tenant_id INTEGER, source INTEGER, source_id INTEGER, upload_origin TEXT,
    type INTEGER, uri TEXT, name TEXT, create_time INTEGER, storage_engine TEXT, storage_scope TEXT, storage_domain TEXT, delete_time INTEGER)');
Db::execute('CREATE TABLE test_user_asset_state (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, asset_ref TEXT, trashed_at INTEGER, hidden_at INTEGER)');
Db::name('tenant')->insert(['id' => 1, 'sn' => 'first', 'tactics' => 0]);
Db::name('merchant_staff')->insert(['id' => 3, 'tenant_id' => 1, 'user_id' => 2, 'store_id' => 5, 'appid' => 'wx', 'openid' => 'employee']);
Db::execute('CREATE TABLE test_work_group (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, type TEXT,
    name TEXT, max_uses INTEGER, delete_time INTEGER)');
Db::execute('CREATE TABLE test_work_item (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, type TEXT,
    group_id INTEGER, source_ref TEXT, delete_time INTEGER)');
Db::execute('CREATE TABLE test_publish_task_material_assignment (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER,
    run_id INTEGER, staff_id INTEGER, platform TEXT, video_ref TEXT, finished_image_ref TEXT, copy_ref TEXT, topic_ref TEXT,
    materials TEXT, create_time INTEGER, UNIQUE(tenant_id, run_id, staff_id, platform))');
foreach (['video' => 'uploads/clip.mp4', 'finished_image' => 'uploads/photo.jpg'] as $kind => $uri) {
    $id = $kind === 'video' ? 4 : 5;
    Db::name('tenant_file')->insert([
        'id' => $id, 'tenant_id' => 1, 'source' => 1, 'source_id' => 2, 'upload_origin' => 'local',
        'type' => $kind === 'video' ? 20 : 10, 'uri' => $uri, 'storage_engine' => 'local',
    ]);
    Db::name('work_group')->insert([
        'id' => $id, 'tenant_id' => 1, 'user_id' => 2, 'type' => $kind, 'name' => $kind, 'max_uses' => $kind === 'video' ? 2 : 1,
    ]);
    Db::name('work_item')->insert([
        'tenant_id' => 1, 'user_id' => 2, 'type' => $kind, 'group_id' => $id, 'source_ref' => 'upload:result:' . $id,
    ]);
}
Db::execute('CREATE TABLE test_merchant_copy (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, name TEXT, content TEXT)');
Db::execute('CREATE TABLE test_merchant_topic (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, name TEXT)');
foreach (['copy' => 6, 'topic' => 7] as $kind => $groupId) {
    $row = ['id' => 1, 'tenant_id' => 1, 'user_id' => 2, 'name' => '可选素材'];
    if ($kind === 'copy') {
        $row['content'] = '可选文案';
    }
    Db::name('merchant_' . $kind)->insert($row);
    Db::name('work_group')->insert([
        'id' => $groupId, 'tenant_id' => 1, 'user_id' => 2, 'type' => $kind, 'name' => $kind, 'max_uses' => 1,
    ]);
    Db::name('work_item')->insert([
        'tenant_id' => 1, 'user_id' => 2, 'type' => $kind, 'group_id' => $groupId, 'source_ref' => 'merchant_' . $kind . ':1',
    ]);
}
$input = [
    'content' => '员工操作说明', 'staff_ids' => [3], 'platforms' => ['douyin', 'xiaohongshu', 'kuaishou'],
    'push_mode' => 'immediate', 'work_group_ids' => ['video' => 4, 'finished_image' => 5, 'copy' => 6, 'topic' => 7],
    'platform_materials' => [
        'douyin' => ['video'],
        'xiaohongshu' => ['finished_image'],
        'kuaishou' => ['finished_image'],
    ],
];
$id = PublishTaskService::create(1, 2, $input)['ids'][0];
$task = Db::name('publish_task')->where('id', $id);
$assignments = Db::name('publish_task_material_assignment');
Db::name('publish_task_run')->insert(['id' => 7, 'tenant_id' => 1, 'user_id' => 2, 'task_id' => $id, 'tasksn' => $task->value('tasksn')]);
$token = (new ReflectionMethod(PublishTaskService::class, 'noticeToken'))->invoke(null, 1, 7, 3, $task->value('tasksn'), 'wx', 'employee');
$call = static fn () => $controller->douyinShare()->getData();
$expectError = static function (string $message) use ($call): void {
    $response = $call();
    check($response['code'] === 0 && str_contains($response['msg'], $message), 'Expected error: ' . $message);
};
try {
    check($controller->isNotNeedLogin(), 'Notification authorizes the employee without an account session');
    $request->withPost(['token' => $token]);
    check(PublishTaskService::publicDetail($token)['content'] === $input['content'], 'Task detail stays readable');
    $expectError('请先点击发布领取抖音素材');
    check($assignments->count() === 0, 'Detail and signing never allocate');

    $douyin = PublishTaskService::assignMaterial($token, 'douyin');
    check(array_keys($douyin) === ['video'] && $douyin['video']['ref'] === 'upload:result:4', 'Douyin only allocates its configured video');
    check($douyin === PublishTaskService::assignMaterial($token, 'douyin'), 'Repeated platform clicks reuse the allocation');
    $xiaohongshu = PublishTaskService::assignMaterial($token, 'xiaohongshu');
    check(array_keys($xiaohongshu) === ['finished_image'] && $xiaohongshu['finished_image']['ref'] === 'upload:result:5',
        'Picture platforms allocate only the configured image group');
    $request->withPost(['token' => $token, 'platform' => 'kuaishou']);
    $shortage = $controller->assignMaterial()->getData();
    check($shortage['code'] === 1 && $shortage['data']['missing_groups'] === [['type' => 'finished_image', 'name' => 'finished_image']],
        'An exhausted image group never falls back to an available video');
    check($assignments->count() === 2, 'Shortage and retries leave counts unchanged');
    $usage = PublishTaskMaterialService::usage(1, 2, 'video', ['upload:result:4']);
    check($usage['counts']->{'upload:result:4'} === 1 && $usage['limits']->{'upload:result:4'} === 2, 'Scoped use count and group limit');

    $request->withPost(['token' => $token, 'tenant_id' => 99, 'video_path' => 'https://other.example/video.mp4']);
    $expectError('抖音分享凭据不可用');
    $source = request()->source;
    request()->source = \app\common\enum\AdminTerminalEnum::PLATFORM;
    PlatformShareConfigService::save(['platform' => 'douyin', 'client_key' => 'test-key', 'client_secret' => 'test-secret']);
    request()->source = $source;
    (new DouyinCredentialCache(Cache::store(), 'test-key', 'test-secret'))->set('open_ticket', ['value' => 'test-ticket', 'expires_at' => time() + 300]);
    $response = $call();
    check($response['code'] === 1 && array_keys($response['data']) === ['schema'], 'Expected only the share scheme');
    $schema = $response['data']['schema'];
    check(str_starts_with($schema, 'snssdk1128://openplatform/share?'), 'Official Douyin scheme');
    parse_str(parse_url($schema, PHP_URL_QUERY), $params);
    check($params['video_path'] === 'https://tenant.example/uploads/clip.mp4' && $params['client_key'] === 'test-key', 'Signed assigned media for the tenant');
    check($params['signature'] === DouyinH5ShareService::signature($params['nonce_str'], 'test-ticket', $params['timestamp']), 'Valid signature');
    check(!isset($params['title']), 'Employee instructions are not the post caption');
    check($assignments->count() === 2, 'Signing an assignment does not consume another use');

    Db::name('user_asset_state')->insert(['tenant_id' => 1, 'user_id' => 2, 'asset_ref' => 'upload:result:4', 'hidden_at' => 100]);
    $expectError('素材已移除或不可访问');
    check($assignments->count() === 2, 'Removing media does not redraw an assignment');
    $request->tenantId = 2;
    $expectError('任务链接无效');
    $request->tenantId = 1;
    Db::name('merchant_staff')->where('id', 3)->update(['openid' => '']);
    $expectError('任务链接无效');
    $request->withPost(['token' => 'invalid']);
    $expectError('任务链接无效');
    echo "PASS employee platform assignment, quota, tenant isolation and Douyin signing\n";
} finally {
    Cache::store()->clear();
    if (is_dir($cacheDir)) rmdir($cacheDir);
}
