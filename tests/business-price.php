<?php

declare(strict_types=1);

use app\common\service\pricing\BusinessPriceService as Prices;
use app\tenantapi\controller\setting\BusinessPriceController;
use think\App;
use think\Request;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$app = new App(dirname(__DIR__));
Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
Config::set(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'trigger_sql' => false,
]]], 'database');
Db::execute('CREATE TABLE test_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER)');
Db::name('tenant')->insertAll([['id' => 1, 'tactics' => 0, 'sn' => 'a'], ['id' => 2, 'tactics' => 1, 'sn' => 'b']]);
foreach (['test_tenant_config', 'test_tenant_config_b'] as $table) {
    Db::execute("CREATE TABLE $table (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)");
}

check(Prices::get(1)['smart_edit_points'] === 0, '剪辑初始价格');
Db::name('tenant_config_b')->insert(['tenant_id' => 2, 'type' => 'geo_manuscript', 'name' => 'markup', 'value' => '1.125']);
check(Prices::get(2)['media_markup_percent'] === 12.5, '兼容媒体旧倍率');
$settings = ['smart_edit_points' => 123, 'media_markup_percent' => 22.25, 'geo_markup_percent' => 10.5];
$request = (new Request())->setMethod('POST')->withPost($settings + ['tenant_id' => 1]);
$request->adminInfo = ['tenant_id' => 2, 'admin_id' => 7];
$app->instance('request', $request);
$controller = new BusinessPriceController($app);
check($controller->save()->getData()['code'] === 1, '租户后台保存');
check($controller->save()->getData()['code'] === 1, '重复保存');
$request->setMethod('GET');
check($controller->detail()->getData()['data'] == $settings, '后台页面回读');
check($controller->save()->getData()['code'] === 0, 'GET 不应写入价格');
check(Prices::get(2) == $settings, '价格回读');
check(Db::name('tenant_config_b')->count() === 3 && Db::name('tenant_config')->count() === 0, '租户分表与重复保存');
check((float)Db::name('tenant_config_b')->where('name', 'markup')->value('value') === 1.2225, '媒体目录读取同一倍率');
foreach ([['smart_edit_points' => -1], ['smart_edit_points' => 0.5], ['media_markup_percent' => -5],
          ['geo_markup_percent' => 0.001], ['geo_markup_percent' => null]] as $invalid) {
    try {
        Prices::save(2, $invalid + $settings);
        throw new RuntimeException('无效价格已写入');
    } catch (InvalidArgumentException) {
        check(Prices::get(2) == $settings, '无效保存应整体回滚');
    }
}
echo "Business pricing checks passed\n";
