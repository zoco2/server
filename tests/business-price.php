<?php

declare(strict_types=1);

use app\common\service\ConfigService;
use app\common\service\pricing\BusinessPriceService;
use app\tenantapi\controller\setting\BusinessPriceController;
use think\App;
use think\exception\HttpResponseException;
use think\facade\Config;
use think\facade\Db;
use think\Model;
use think\Request;
use think\service\ValidateService;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$app = new App(dirname(__DIR__));
Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
Config::set(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'trigger_sql' => false,
]]], 'database');
(new ValidateService($app))->boot();
Model::setDb($app->make('db'));
Model::maker(static fn(Model $model) => $model->setDateFormat('Y-m-d H:i:s'));
Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER)');
Db::name('tenant')->insertAll([
    ['id' => 1, 'tactics' => 0, 'sn' => 'shared'], ['id' => 2, 'tactics' => 0, 'sn' => 'another'],
]);
Db::execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT,
    name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');

$call = static function (int $tenant, string $method, string $action, array $params = []) use ($app): array {
    $request = (new Request())->setMethod($method)->withPost($params);
    $request->tenantId = $tenant;
    $request->adminInfo = ['tenant_id' => $tenant, 'admin_id' => 7];
    $app->instance('request', $request);
    try {
        return (new BusinessPriceController($app))->$action()->getData();
    } catch (HttpResponseException $response) {
        return $response->getResponse()->getData();
    }
};
$read = static function (int $tenant) use ($call): array {
    $response = $call($tenant, 'GET', 'detail');
    check($response['code'] === 1, '后台价格读取失败');
    return $response['data'];
};

$zero = ['smart_edit_points' => 0, 'media_markup_percent' => 0, 'geo_markup_percent' => 0];
$max = ['smart_edit_points' => 99999999, 'media_markup_percent' => 9900, 'geo_markup_percent' => 9900];
$decimal = ['smart_edit_points' => 123, 'media_markup_percent' => 22.25, 'geo_markup_percent' => 0.01];
check($read(1) == $zero && $read(2) == $zero, '未配置的三个价格都应为 0');
check($call(2, 'GET', 'save', $max)['code'] === 0, 'GET 不应写入价格');
check($call(2, 'POST', 'detail')['code'] === 0, '读取接口只接受 GET');
check($call(1, 'POST', 'save', $zero)['code'] === 1, '第一个租户接受零值');
check($call(2, 'POST', 'save', $max + ['tenant_id' => 1])['code'] === 1, '第二个租户接受上限值');
check($read(1) == $zero && $read(2) == $max, '表单参数不能覆盖登录租户');

foreach ([$zero, $decimal, $decimal] as $settings) {
    check($call(2, 'POST', 'save', $settings)['code'] === 1, '第二个租户更新失败');
    check(ConfigService::get('business_price', 'settings') == $settings, 'ConfigService 未读回完整定价数组');
    check($read(1) == $zero && $read(2) == $settings, '更新写入其他租户或价格回读有误');
}
check($call(1, 'POST', 'save', $max)['code'] === 1, '第一个租户更新失败');
check($read(1) == $max && $read(2) == $decimal, '更新覆盖了其他租户的价格');
check(BusinessPriceService::smartEditPoints() === 123, '剪辑报价读回后台保存的单价');
check(BusinessPriceService::mediaMarkupRatio() === 1.2225, '媒体报价读回后台保存的溢价率');
check(BusinessPriceService::pointsForMarkup(100, BusinessPriceService::geoMarkupPercent()) === 10001, 'GEO 报价读回后台保存的溢价率');
foreach ([1 => $max, 2 => $decimal] as $tenant => $expected) {
    $query = Db::name('tenant_config')->where(['tenant_id' => $tenant, 'type' => 'business_price', 'name' => 'settings']);
    check((clone $query)->count() === 1, '一个租户只应有一行价格配置');
    check(json_decode($query->value('value'), true, 512, JSON_THROW_ON_ERROR) == $expected, '配置 JSON 与接口值不同');
}

foreach ([
    'smart_edit_points' => [-1, 0.5, 100000000, 'abc'],
    'media_markup_percent' => [-0.01, 9900.01, 0.001, null],
    'geo_markup_percent' => [-0.01, 9900.01, 0.001, null],
] as $field => $invalidValues) {
    foreach ($invalidValues as $value) {
        $params = array_replace($max, [$field => $value]);
        check($call(2, 'POST', 'save', $params)['code'] === 0, "$field 应拒绝 " . json_encode($value));
        check($read(2) == $decimal, '无效保存修改了已有价格');
    }
}
echo "PASS tenant pricing defaults, method restrictions, validation and isolated config updates\n";
