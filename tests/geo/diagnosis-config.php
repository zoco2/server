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

    Db::execute('CREATE TABLE test_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT, create_time INTEGER, update_time INTEGER)');
    check(Config::getPlatform() === Config::DEFAULTS, 'Empty platform credentials');
    $request = new Request();
    $request->source = \app\common\enum\AdminTerminalEnum::PLATFORM;
    $app->instance('request', $request);
    Config::savePlatform(['api_key' => 'platform-fixture']);
    check(Config::getPlatform() === ['api_key' => 'platform-fixture'], 'Platform GEO configuration was saved');

    GeoDiagnosisService::usage(0);
    $request = $GLOBALS['geo_requests'][0];
    $endpoint = 'https://118.89.28.187/api/v1';
    check($request[CURLOPT_URL] === $endpoint . '/usage', 'Wrong GEO endpoint');
    check($request[CURLOPT_SSL_VERIFYPEER] === true && $request[CURLOPT_SSL_VERIFYHOST] === 2, 'Certificate and IP must be verified');
    check(in_array('Authorization: Bearer platform-fixture', $request[CURLOPT_HTTPHEADER], true), 'Wrong platform key');
    Db::execute('CREATE TABLE test_geo_diag_batch (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, next_poll_time INTEGER,
        upstream_batch_id TEXT, terminal INTEGER DEFAULT 0, task_run_id INTEGER, completed_count INTEGER, failed_count INTEGER, status_json TEXT,
        provider_request_id TEXT, charged_cents INTEGER, balance_cents INTEGER, poll_count INTEGER, update_time INTEGER)');
    foreach ([1, 3] as $id) {
        Db::name('geo_diag_batch')->insert(['id' => $id, 'tenant_id' => $id, 'status' => 'queued', 'next_poll_time' => $id, 'upstream_batch_id' => 'batch' . $id]);
    }
    $result = GeoDiagnosisService::syncDue(5);
    check($result['checked'] === 2 && $result['failed'] === 0, 'Background polling failed');
    foreach ([1, 2] as $index) {
        $batchId = $index === 1 ? 1 : 3;
        check($GLOBALS['geo_requests'][$index][CURLOPT_URL] === $endpoint . '/batches/batch' . $batchId . '/status', 'Worker used the wrong GEO endpoint');
        check($GLOBALS['geo_requests'][$index][CURLOPT_SSL_VERIFYPEER] === true && $GLOBALS['geo_requests'][$index][CURLOPT_SSL_VERIFYHOST] === 2, 'Worker disabled TLS verification');
        check(in_array('Authorization: Bearer platform-fixture', $GLOBALS['geo_requests'][$index][CURLOPT_HTTPHEADER], true), 'Worker used the wrong platform key');
    }
    echo "PASS platform GEO diagnosis configuration and worker credentials\n";
}
