<?php

declare(strict_types=1);

// 记录真正传给 cURL 的参数，使用模拟响应，避免请求服务商或消耗诊断额度。
namespace app\common\service\geo {
    function curl_setopt_array($handle, array $options): bool
    {
        $GLOBALS['geo_requests'][] = $options;
        return true;
    }
    function curl_exec($handle): string { return '{"status":"running","items":[]}'; }
    function curl_getinfo($handle, int $option): int { return $option === CURLINFO_HTTP_CODE ? 200 : 0; }
}

namespace {
    use app\common\service\geo\GeoDiagConfigService as Config;
    use app\common\service\geo\GeoDiagnosisService;
    use app\common\service\smart_edit\SmartEditImsConfigService;
    use app\common\service\smart_edit\SmartEditVoiceConfigService;
    use app\tenantapi\controller\geo\DiagnosisConfigController;
    use app\tenantapi\listener\OperationLog;
    use think\App;
    use think\facade\Config as AppConfig;
    use think\facade\Db;
    use think\facade\Env;
    use think\Model;
    use think\Request;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

    function check(bool $ok, string $message): void
    {
        if (!$ok) throw new RuntimeException($message);
    }
    $app = new App(dirname(__DIR__, 2));
    AppConfig::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
    AppConfig::set(['default' => 'fixture', 'connections' => ['fixture' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_', 'trigger_sql' => false,
    ]]], 'database');
    Env::set(['database' => ['prefix' => 'test_'], 'geo_diag' => ['api_key' => 'unused-env-fixture', 'base_url' => 'https://env.example.test']]);
    Model::setDb($app->make('db'));
    Db::execute('CREATE TABLE test_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER)');
    foreach ([1, 2, 3] as $id) {
        Db::name('tenant')->insert(['id' => $id, 'tactics' => $id === 3 ? 1 : 0, 'sn' => 't' . $id]);
    }
    foreach (['test_tenant_config', 'test_tenant_config_t3'] as $table) {
        Db::execute("CREATE TABLE $table (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)");
    }

    check(Config::get(1) === Config::DEFAULTS, 'Empty tenant unexpectedly inherited global credentials');
    $one = array_replace(Config::DEFAULTS, ['api_key' => 'one-fixture']);
    Config::save(1, $one, true);
    try {
        Config::save(1, array_replace($one, ['api_key' => 'overwritten']), true);
        throw new LogicException('Repeated migration overwrote a saved configuration');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), '跳过'), 'Unexpected migration error');
    }
    $split = ['api_key' => 'split-fixture'];
    Db::name('tenant_config_t3')->insert([
        'tenant_id' => 3, 'type' => 'geo_diag', 'name' => 'provider',
        'value' => json_encode($split + ['base_url' => 'https://legacy.example.test', 'ssl_verify' => false]),
    ]);
    check(Config::get(2) === Config::DEFAULTS && Config::get(3) === $split, 'Tenant isolation failed');
    check(Db::name('tenant_config_t3')->where('tenant_id', 3)->count() === 1, 'Missing split config');

    $post = (new Request())->setMethod('POST')->withPost(array_replace($one, [
        'api_key' => 'updated-fixture', 'tenant_id' => 3, 'base_url' => 'https://override.example.test', 'ssl_verify' => false,
    ]));
    $post->adminInfo = ['tenant_id' => 1, 'admin_id' => 10];
    $app->instance('request', $post);
    check((new DiagnosisConfigController($app))->save()->getData()['code'] === 1, 'Admin save failed');
    $post->setMethod('GET');
    $post->setController('geo.DiagnosisConfig')->setAction('detail');
    $post->controllerObject = new DiagnosisConfigController($app);
    $response = $post->controllerObject->detail();
    $expected = ['api_key' => 'updated-fixture'];
    check($response->getData()['data'] === $expected, 'Form must return only the saved key');
    $stored = Db::name('tenant_config')->where('tenant_id', 1)->value('value');
    check(json_decode($stored, true, 512, JSON_THROW_ON_ERROR) === $expected, 'Only the API key should be persisted');
    check(Config::get(3) === $split && Db::name('tenant_config')->count() === 1, 'Unexpected overwrite');
    Db::execute('CREATE TABLE test_operation_log (id INTEGER PRIMARY KEY, admin_id INTEGER, admin_name TEXT,
        action TEXT, account TEXT, url TEXT, type TEXT, params TEXT, ip TEXT, result TEXT, create_time INTEGER, update_time INTEGER)');
    check((bool)(new OperationLog())->handle($response), 'Operation log missing');
    $log = Db::name('operation_log')->where('admin_id', 10)->find();
    check(is_array($log) && json_decode($log['result'], true)['data'] === [], 'Logged response must omit credential');
    check(!str_contains($log['result'] . $log['params'], 'updated-fixture'), 'Form credential copied into log');

    $app->instance('request', new Request());
    foreach (['aliyun' => ['secret_key' => 'ims-fixture'], 'volcengine' => ['api_key' => 'voice-fixture']] as $name => $value) {
        Db::name('tenant_config')->insert(['tenant_id' => 1, 'type' => 'smart_edit', 'name' => $name, 'value' => json_encode($value)]);
    }
    check(SmartEditImsConfigService::forDisplay(1)['secret_key'] === 'ims-fixture', 'IMS SecretKey missing from form');
    check(SmartEditVoiceConfigService::forDisplay(1)['api_key'] === 'voice-fixture', 'Voice API Key missing from form');

    GeoDiagnosisService::usage(3);
    $request = $GLOBALS['geo_requests'][0];
    $endpoint = 'https://118.89.28.187/api/v1';
    check($request[CURLOPT_URL] === $endpoint . '/usage', 'Wrong GEO endpoint');
    check($request[CURLOPT_SSL_VERIFYPEER] === true && $request[CURLOPT_SSL_VERIFYHOST] === 2, 'Certificate and IP must be verified');
    check(in_array('Authorization: Bearer split-fixture', $request[CURLOPT_HTTPHEADER], true), 'Wrong tenant key');
    Db::execute('CREATE TABLE test_geo_diag_batch (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, next_poll_time INTEGER,
        upstream_batch_id TEXT, terminal INTEGER DEFAULT 0, task_run_id INTEGER, completed_count INTEGER, failed_count INTEGER, status_json TEXT,
        provider_request_id TEXT, charged_cents INTEGER, balance_cents INTEGER, poll_count INTEGER, update_time INTEGER)');
    foreach ([1, 3] as $id) {
        Db::name('geo_diag_batch')->insert(['id' => $id, 'tenant_id' => $id, 'status' => 'queued', 'next_poll_time' => $id, 'upstream_batch_id' => 'batch' . $id]);
    }
    $result = GeoDiagnosisService::syncDue(5);
    check($result['checked'] === 2 && $result['failed'] === 0, 'Background polling failed');
    foreach ([1 => 'updated-fixture', 2 => 'split-fixture'] as $index => $key) {
        $batchId = $index === 1 ? 1 : 3;
        check($GLOBALS['geo_requests'][$index][CURLOPT_URL] === $endpoint . '/batches/batch' . $batchId . '/status', 'Worker used the wrong GEO endpoint');
        check($GLOBALS['geo_requests'][$index][CURLOPT_SSL_VERIFYPEER] === true && $GLOBALS['geo_requests'][$index][CURLOPT_SSL_VERIFYHOST] === 2, 'Worker disabled TLS verification');
        check(in_array('Authorization: Bearer ' . $key, $GLOBALS['geo_requests'][$index][CURLOPT_HTTPHEADER], true), 'Worker used the wrong tenant key');
    }
    echo "PASS tenant diagnosis config, admin read/write, import protection, split storage, worker credentials and smart edit key display\n";
}
