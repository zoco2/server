<?php
declare(strict_types=1);

use app\common\enum\AdminTerminalEnum;
use app\common\service\ConfigService;
use app\common\service\database\SqlMigrationExecutor;
use app\common\service\geo\AigcGeoManuscriptService;
use app\common\service\geo\GeoDiagConfigService;
use app\common\service\geo\GeoDiagnosisPricing;
use app\common\service\geo\GeoMediaSyncService;
use app\common\service\share\PlatformShareConfigService;
use app\common\service\smart_edit\SmartEditBillingService;
use app\common\service\smart_edit\SmartEditImsConfigService;
use app\common\service\smart_edit\SmartEditVoiceConfigService;
use app\common\service\smart_edit\SmartEditVoiceService;
use app\common\service\wenan\WenanConfigService;
use app\platformapi\controller\setting\BusinessConfigController;
use app\platformapi\http\middleware\AuthMiddleware;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;
use think\Model;

$tree = dirname(__DIR__);
require $tree . '/vendor/autoload.php';
require $tree . '/vendor/topthink/framework/src/helper.php';
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', (string)$e . PHP_EOL);
    exit(1);
});
$app = new App($tree);
Config::set(['default' => 'fixture', 'connections' => ['fixture' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'trigger_sql' => false,
]]], 'database');
Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
Env::set(['wechat' => ['credential_key' => 'fixture-shared-configuration-key'], 'database' => ['prefix' => 'test_']]);
(new \think\service\ValidateService($app))->boot();
Model::setDb($app->make('db'));
Model::maker(static function (Model $model): void {
    $model->isAutoWriteTimestamp(true);
    $model->setDateFormat('Y-m-d H:i:s');
});
Db::execute('CREATE TABLE test_tenant (id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER, delete_time INTEGER)');
Db::execute("INSERT INTO test_tenant VALUES (1, 'one', 0, NULL), (2, 'two', 0, NULL)");
Db::execute('CREATE TABLE test_tenant_config (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_config (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_power_market_product (id INTEGER PRIMARY KEY, resource_type INTEGER, model_type TEXT, status INTEGER, update_time INTEGER)');

function fixtureRequest(App $app, string $source, int $tenant = 0, array $data = []): \app\Request
{
    $request = new \app\Request();
    $request->setMethod($data === [] ? 'GET' : 'POST');
    $request->source = $source;
    if ($tenant > 0) $request->tenantId = $tenant;
    $request->withPost($data);
    $app->instance('request', $request);
    return $request;
}
function verify(bool $ok, string $case): void
{
    if (!$ok) throw new RuntimeException($case);
    echo 'PASS ' . $case . PHP_EOL;
}

fixtureRequest($app, AdminTerminalEnum::TENANT, 1);
ConfigService::set('business_price', 'settings', ['smart_edit_points'=>15,'geo_markup_percent'=>10,'media_markup_percent'=>0]);
ConfigService::set('smart_edit', 'aliyun', ['domain'=>'https://old-oss.example','access_key'=>'old-ak','secret_key'=>'old-sk','bucket'=>'old','region'=>'cn-old']);
ConfigService::set('smart_edit', 'volcengine', ['api_key'=>'old-voice']);
ConfigService::set('geo_diag', 'provider', ['api_key'=>'old-geo']);
ConfigService::set('geo_manuscript', 'account', ['token'=>'old-token','key'=>'old-key']);
ConfigService::set('geo_manuscript', 'markup', 1.3);
ConfigService::set('wenan', 'model', ['text_model_id'=>'11']);
ConfigService::set('social_share', 'douyin', ['client_key'=>'old-id','client_secret'=>'old-secret']);
verify(SmartEditBillingService::unitPoints() === 0 && GeoDiagnosisPricing::markupPercent() === 0.0
    && GeoMediaSyncService::markup() === 1.0, 'prices never fall back to tenant data');
verify(SmartEditImsConfigService::getPlatform()['secret_key'] === '' && GeoDiagConfigService::getPlatform()['api_key'] === ''
    && !AigcGeoManuscriptService::platformConfigDetail()['configured'], 'provider settings never fall back to tenant data');
fixtureRequest($app, AdminTerminalEnum::PLATFORM);
SmartEditImsConfigService::savePlatform(['domain'=>'https://shared-oss.example','access_key'=>'shared-ak','secret_key'=>'shared-sk','bucket'=>'shared','region'=>'cn-shared']);
SmartEditVoiceConfigService::savePlatform(['api_key'=>'shared-voice']);
SmartEditVoiceService::savePlatform([['key'=>'test-voice','label'=>'Test','provider_asset_id'=>'example']]);
GeoDiagConfigService::savePlatform(['api_key'=>'shared-geo']);
ConfigService::set('geo_manuscript', 'account', ['token'=>'shared-token','key'=>'shared-key']);
ConfigService::set('business_price', 'settings', ['smart_edit_points'=>21,'geo_markup_percent'=>25,'media_markup_percent'=>40]);
WenanConfigService::savePlatform(['text_model_id'=>'']);
PlatformShareConfigService::save(['platform'=>'douyin','client_key'=>'shared-id','client_secret'=>'shared-secret']);
PlatformShareConfigService::save(['platform'=>'xiaohongshu','app_key'=>'shared-xhs','app_secret'=>'shared-xhs-secret']);
verify(!array_key_exists('client_secret',PlatformShareConfigService::display()['douyin']) && PlatformShareConfigService::display()['douyin']['configured'], 'platform form only reports whether share credentials are set');
verify(str_starts_with(json_decode((string)Db::name('config')->where(['type'=>'social_share','name'=>'douyin'])->value('value'), true)['client_secret'], 'enc:v1:'), 'shared share credentials stay encrypted at rest');

foreach ([1,2] as $tenant) {
    fixtureRequest($app, AdminTerminalEnum::TENANT, $tenant);
    verify(SmartEditBillingService::unitPoints() === 21 && GeoDiagnosisPricing::markupPercent() === 25.0
        && GeoMediaSyncService::markup() === 1.4 && GeoMediaSyncService::priceExpression() === 'costprice * 1.4', 'shared prices for tenant ' . $tenant);
    verify(SmartEditImsConfigService::getPlatform()['secret_key'] === 'shared-sk'
        && SmartEditVoiceConfigService::getPlatform()['api_key'] === 'shared-voice'
        && SmartEditVoiceService::getPlatform('test-voice')['provider_asset_id'] === 'example'
        && GeoDiagConfigService::getPlatform()['api_key'] === 'shared-geo'
        && PlatformShareConfigService::runtime('douyin')['client_key'] === 'shared-id'
        && PlatformShareConfigService::runtime('xiaohongshu')['app_key'] === 'shared-xhs'
        && WenanConfigService::resolveTextModelId($tenant) === '', 'shared runtime providers for tenant ' . $tenant);
}
try {
    \app\common\service\geo\GeoDiagnosisService::usage(1);
    throw new LogicException('Tenant usage was exposed');
} catch (RuntimeException $error) {
    verify(str_contains($error->getMessage(), '仅限平台'), 'tenant cannot read the shared account balance');
}
fixtureRequest($app, AdminTerminalEnum::PLATFORM);
$controller = new BusinessConfigController($app);
$detail = $controller->detail()->getData();
verify((int)$detail['code'] === 1 && $detail['data']['prices']['smart_edit_points'] === 21
    && !isset($detail['data']['ims']['secret_key']) && !isset($detail['data']['voice']['api_key']) && !isset($detail['data']['media']['key'])
    && $detail['data']['geo']['api_key_set'], 'platform detail excludes provider secrets');
fixtureRequest($app, AdminTerminalEnum::PLATFORM, 0, ['section'=>'prices','smart_edit_points'=>35,'media_markup_percent'=>10,'geo_markup_percent'=>4]);
$controller = new BusinessConfigController($app);
$response = $controller->save()->getData();
verify((int)$response['code'] === 1 && ConfigService::getPlatform('business_price', 'settings')['smart_edit_points'] === 35, 'platform save uses existing price validation');
fixtureRequest($app, AdminTerminalEnum::PLATFORM, 0, ['section'=>'prices','smart_edit_points'=>-1,'media_markup_percent'=>10,'geo_markup_percent'=>4]);
$controller = new BusinessConfigController($app);
$response = $controller->save()->getData();
verify((int)$response['code'] !== 1 && ConfigService::getPlatform('business_price', 'settings')['smart_edit_points'] === 35, 'invalid prices are rejected before config writes');

$max = ['smart_edit_points' => 99999999, 'media_markup_percent' => 9900, 'geo_markup_percent' => 9900];
fixtureRequest($app, AdminTerminalEnum::PLATFORM, 0, ['section' => 'prices'] + $max);
verify((new BusinessConfigController($app))->save()->getData()['code'] === 1, 'maximum prices can be saved');
$zero = ['smart_edit_points' => 0, 'media_markup_percent' => 0, 'geo_markup_percent' => 0];
fixtureRequest($app, AdminTerminalEnum::PLATFORM, 0, ['section' => 'prices'] + $zero);
verify((new BusinessConfigController($app))->save()->getData()['code'] === 1
    && GeoMediaSyncService::markup() === 1.0, 'zero platform markup overrides legacy media prices');

Db::execute('CREATE TABLE test_system_menu (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER, type TEXT, name TEXT, icon TEXT, sort INTEGER, perms TEXT, paths TEXT, component TEXT, is_show INTEGER, is_disable INTEGER DEFAULT 0, source TEXT, source_menu_key TEXT, create_time INTEGER, update_time INTEGER)');
Db::execute('CREATE TABLE test_tenant_system_menu (id INTEGER PRIMARY KEY, component TEXT, is_show INTEGER, is_disable INTEGER, source_menu_key TEXT, update_time INTEGER)');
Db::execute("INSERT INTO test_system_menu (id,pid,type,paths) VALUES (1,0,'M','setting')");
Db::execute("INSERT INTO test_tenant_system_menu VALUES (1,'setting/base-config',1,0,'core_tenant_base_config',0),(2,'geo/article',1,0,'another_menu',0)");
Db::connect()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', static fn () => time());
$menuSql = file_get_contents($tree . '/upgrade/20261123_platform_business_config.sql')
    . file_get_contents($tree . '/upgrade/20261124_use_platform_business_config.sql');
foreach ([1,2] as $_) {
    foreach (SqlMigrationExecutor::split($menuSql) as $statement) {
        Db::execute(str_replace('la_', 'test_', $statement));
    }
}
verify(Db::name('system_menu')->count() === 3
    && (int)Db::name('tenant_system_menu')->where('id',1)->value('is_disable') === 1
    && (int)Db::name('tenant_system_menu')->where('id',2)->value('is_disable') === 0, 'idempotent platform menu and targeted tenant removal');

Db::execute('CREATE TABLE test_admin_role (id INTEGER PRIMARY KEY, admin_id INTEGER, role_id INTEGER)');
Db::execute('CREATE TABLE test_system_role_menu (id INTEGER PRIMARY KEY, role_id INTEGER, menu_id INTEGER)');
$detailId = Db::name('system_menu')->where('source_menu_key', 'core_platform_business_config')->value('id');
Db::name('admin_role')->insert(['admin_id' => 77, 'role_id' => 9]);
Db::name('system_role_menu')->insert(['role_id' => 9, 'menu_id' => $detailId]);

$req = fixtureRequest($app, AdminTerminalEnum::PLATFORM);
$req->setController('setting.BusinessConfig')->setAction('detail')->withServer(['REMOTE_ADDR' => '127.0.0.1']);
$req->adminInfo = ['admin_id' => 77, 'root' => 0, 'login_ip' => '127.0.0.1'];
$req->controllerObject = new BusinessConfigController($app);
$auth = new AuthMiddleware();
$allowed = static fn () => 'allowed';
verify($auth->handle($req, $allowed) === 'allowed', 'platform role with read permission can open the settings');
$readOnlyShare = $req->controllerObject->detail()->getData()['data']['share'];
verify(!isset($readOnlyShare['douyin']['client_secret']) && !isset($readOnlyShare['xiaohongshu']['app_secret'])
    && $readOnlyShare['douyin']['configured'] && $readOnlyShare['xiaohongshu']['configured'], 'read-only role sees shared credential status');
foreach (['save', 'voicePreview'] as $action) {
    $req->setAction($action);
    $result = $auth->handle($req, $allowed);
    verify($result !== 'allowed' && $result->getData()['code'] === 0, "read-only platform role cannot use $action");
}

$saveId = Db::name('system_menu')->where('source_menu_key', 'core_platform_business_config_save')->value('id');
Db::name('admin_role')->insert(['admin_id' => 78, 'role_id' => 10]);
Db::name('system_role_menu')->insertAll([['role_id' => 10, 'menu_id' => $detailId], ['role_id' => 10, 'menu_id' => $saveId]]);
$req = fixtureRequest($app, AdminTerminalEnum::PLATFORM);
$req->setController('setting.BusinessConfig')->setAction('detail')->withServer(['REMOTE_ADDR' => '127.0.0.1']);
$req->adminInfo = ['admin_id' => 78, 'root' => 0, 'login_ip' => '127.0.0.1'];
$req->controllerObject = new BusinessConfigController($app);
$editing = $auth->handle($req, static fn ($request) => $request->controllerObject->detail());
$share = $editing->getData()['data']['share'];
verify($share['douyin']['client_secret'] === 'shared-secret' && $share['xiaohongshu']['app_secret'] === 'shared-xhs-secret',
    'platform editor receives saved share secrets');

$req->setAction('save')->setMethod('POST')->withPost(['section' => 'share', 'platform' => 'douyin', 'client_key' => 'shared-id', 'client_secret' => '']);
$saved = $auth->handle($req, static fn ($request) => $request->controllerObject->save())->getData();
verify($saved['code'] === 1 && $saved['data']['client_secret'] === 'shared-secret', 'share save returns the retained secret');
