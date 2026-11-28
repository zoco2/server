<?php

declare(strict_types=1);

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    function request(): object { return $GLOBALS['modelHubRequest']; }
    function env($key, $default = null) { return $GLOBALS['modelHubEnv'][$key] ?? $default; }
    function generate_sn(...$unused): string { return bin2hex(random_bytes(10)); }
}

namespace app\common\service\point_alert {
    // 测试只检查余额和流水，通知交给独立的告警测试。
    final class AlertService { public static function touch(int $tenant, int $user = 0): void {} }
}

namespace {
    use app\common\enum\AdminTerminalEnum;
    use app\common\service\model_hub\ModelHubRuntimeService;
    use think\Container;
    use think\DbManager;

    function check(bool $passed, string $message): void
    {
        if (!$passed) {
            throw new RuntimeException($message);
        }
    }

    $request = new class {
        public int $tenantId = 1;
        public int $userId = 1;
        public string $source = AdminTerminalEnum::USER;
        public function header(string $key): ?string { return null; }
    };
    $GLOBALS['modelHubRequest'] = $request;
    Container::getInstance()->instance('request', $request);
    $db = new DbManager();
    Container::getInstance()->instance(DbManager::class, $db);
    $db->setConfig(['default' => 'test', 'connections' => ['test' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true,
    ]]]);

    $db->execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER, point_balance REAL, delete_time INTEGER, update_time INTEGER)');
    $db->execute('CREATE TABLE la_user (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_money REAL, delete_time INTEGER, create_time INTEGER, update_time INTEGER)');
    $db->execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)');
    $db->execute('CREATE TABLE la_tenant_point_bucket (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER, expire_time INTEGER, remaining_points REAL)');
    $db->execute('CREATE TABLE la_tenant_point_log (id INTEGER PRIMARY KEY, tenant_id INTEGER, sn TEXT, change_type TEXT, action INTEGER,
        change_amount REAL, left_amount REAL, source_sn TEXT, remark TEXT, extra TEXT, create_time INTEGER, update_time INTEGER)');
    $db->execute('CREATE TABLE la_user_account_log (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, sn TEXT,
        change_object INTEGER, change_type INTEGER, action INTEGER, change_amount REAL, left_amount REAL, source_sn TEXT,
        remark TEXT, extra TEXT, delete_time INTEGER, create_time INTEGER, update_time INTEGER)');
    $db->execute("CREATE TABLE la_model_hub_call (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        call_key TEXT NOT NULL UNIQUE, request_hash TEXT NOT NULL, capability TEXT NOT NULL, model TEXT NOT NULL,
        unit_price NUMERIC NOT NULL, estimated_units INTEGER NOT NULL, reserved_points NUMERIC NOT NULL,
        actual_units INTEGER, charged_points NUMERIC NOT NULL DEFAULT 0, status TEXT NOT NULL,
        reason TEXT NOT NULL DEFAULT '', result TEXT, create_time INTEGER, update_time INTEGER)");
    $db->name('tenant')->insert(['id' => 1, 'sn' => 'test', 'tactics' => 0, 'point_balance' => 40]);
    $db->name('user')->insert(['id' => 1, 'tenant_id' => 1, 'user_money' => 35]);
    $db->name('tenant')->insert(['id' => 2, 'sn' => 'other', 'tactics' => 0, 'point_balance' => 20]);
    $db->name('user')->insert(['id' => 2, 'tenant_id' => 2, 'user_money' => 20]);
    $balance = static fn(): array => [
        (float)$db->name('tenant')->where('id', 1)->value('point_balance'),
        (float)$db->name('user')->where('id', 1)->value('user_money'),
    ];

    $input = [
        'capability' => 'chat', 'model' => 'model-hub-demo', 'estimated_units' => 10,
        'parameters' => ['prompt' => 'hello'],
    ];
    $dispatches = 0;
    $unexpected = static function () use (&$dispatches): array {
        $dispatches++;
        return ['status' => 'failed'];
    };
    $duringDispatch = null;
    $call = ModelHubRuntimeService::run('chat-1', $input, static function (
        string $capability, string $model, array $parameters
    ) use ($balance, $input, $unexpected, &$dispatches, &$duringDispatch): array {
        $dispatches++;
        $duringDispatch = [$capability, $model, $parameters, $balance(),
            ModelHubRuntimeService::run('chat-1', $input, $unexpected)->status];
        return ['status' => 'success', 'usage_units' => 3, 'result' => ['reply' => 'hello']];
    });
    check($duringDispatch === ['chat', 'model-hub-demo', $input['parameters'], [30.0, 25.0], 'running'],
        '执行前两方已预占 10 点，重复请求读取同一笔执行中的调用');
    check($call['status'] === 'settled' && (float)$call['charged_points'] === 3.0 && $balance() === [37.0, 32.0],
        '实际扣 3 点，两方各返还 7 点');
    $replay = ModelHubRuntimeService::run('chat-1', $input, $unexpected);
    check($replay['id'] === $call['id'] && $replay['result'] === ['reply' => 'hello'] && $dispatches === 1,
        '完成后重试复用原结果和账单');
    try {
        ModelHubRuntimeService::run('chat-1', array_replace($input, ['parameters' => ['prompt' => 'changed']]), $unexpected);
        throw new RuntimeException('同请求号异参应被拒绝');
    } catch (InvalidArgumentException) {
        check($dispatches === 1, '同请求号异参未派发');
    }

    $failed = ModelHubRuntimeService::run('chat-2', $input, static fn(): array => ['status' => 'failed']);
    check($failed['status'] === 'refunded' && $balance() === [37.0, 32.0], '明确失败返还全部预占');
    $extra = ModelHubRuntimeService::run('chat-3', $input, static fn(): array => ['status' => 'success', 'usage_units' => 12]);
    check($extra['status'] === 'settled' && (float)$extra['charged_points'] === 12.0 && $balance() === [25.0, 20.0],
        '实际 12 点时只补扣 2 点');

    try {
        ModelHubRuntimeService::run('chat-4', array_replace($input, ['estimated_units' => 21]), $unexpected);
        throw new LogicException('余额不足应被拒绝');
    } catch (RuntimeException) {
        check($balance() === [25.0, 20.0] && $dispatches === 1 && $db->name('model_hub_call')->count() === 3,
            '用户余额不足时租户预占和建单一并回滚');
    }
    $pending = ModelHubRuntimeService::run('chat-5', $input, static fn(): array => ['status' => 'success', 'usage_units' => 21]);
    check($pending['status'] === 'needs_review' && (int)$pending['actual_units'] === 21 && $balance() === [15.0, 10.0],
        '补扣失败保留用量和原预占');
    $unknown = ModelHubRuntimeService::run('chat-6', $input, static function (): never {
        throw new RuntimeException('simulated timeout');
    });
    check($unknown['status'] === 'needs_review' && $unknown['reason'] === 'upstream_unknown' && $balance() === [5.0, 0.0],
        '超时保留预占供核对');
    check($db->name('tenant_point_log')->count() === 8 && $db->name('user_account_log')->count() === 8,
        '两边流水都只记录已提交的预占、差额或释放');

    $request->tenantId = 2;
    $request->userId = 2;
    $other = ModelHubRuntimeService::run('chat-1', $input, static fn(): array => [
        'status' => 'success', 'result' => ['reply' => 'usage is missing'],
    ]);
    check($other['id'] !== $call['id'] && (int)$other['tenant_id'] === 2 && $balance() === [5.0, 0.0],
        '其他租户可以使用相同的请求号，原租户余额不受影响');
    check($other['status'] === 'needs_review' && $other['reason'] === 'usage_unconfirmed' &&
        $other['result'] === ['reply' => 'usage is missing'] &&
        (float)$db->name('user')->where('id', 2)->value('user_money') === 10.0,
        '缺少实际用量时保留返回内容和预占');

    echo "PASS: Model Hub Runtime 预占、执行、结算和退款\n";

    require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
    $app = new \think\App(dirname(__DIR__));
    $app->setNamespace('app\api');
    $app->instance(DbManager::class, $db);
    $app->config->set(array_merge(
        require dirname(__DIR__) . '/config/route.php',
        require dirname(__DIR__) . '/app/api/config/route.php'
    ), 'route');
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'model-hub-route-' . bin2hex(random_bytes(8));
    $app->config->set(['default' => 'file', 'stores' => ['file' => [
        'type' => 'File', 'path' => $cachePath, 'cache_subdir' => false,
    ]]], 'cache');

    $cache = $app->cache->store();
    $token = 'model-hub-fixture';
    $cacheKey = 'token_user_' . $token;
    $cache->set($cacheKey, ['user_id' => 2, 'tenant_id' => 2, 'expire_time' => time() + 3600], 3600);
    $send = static function (array $body, string $token = '', string $method = 'POST') use ($app): array {
        $path = 'v1.model_hub/chatTest';
        $request = new \app\Request();
        $request->tenantId = 2;
        $request->setMethod($method)->setPathinfo('api/' . $path);
        $request->withHeader(['content-type' => 'application/json', 'token' => $token]);
        $request->withInput(json_encode($body, JSON_THROW_ON_ERROR));
        $GLOBALS['modelHubRequest'] = $request;
        $app->instance('request', $request);

        $response = (new \app\common\http\middleware\OpenGatewayMiddleware())->handle(
            $request,
            static function ($request) use ($app, $path) {
                // 多应用路由收到的是去掉 /api 前缀的相对路径。
                $request->setPathinfo($path);
                return $app->route->dispatch($request);
            }
        );
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    };

    try {
        $body = ['request_id' => 'frontend-1', 'prompt' => '你好'];
        check($send($body)['code'] === 0, '约定路由需要现有登录令牌');
        check($send($body, $token)['msg'] === '当前租户未开启 Model Hub 测试', '测试租户未配置时关闭');
        $GLOBALS['modelHubEnv']['model_hub.test_tenant_id'] = 1;
        check($send($body, $token)['msg'] === '当前租户未开启 Model Hub 测试', '非测试租户无法提交');
        $GLOBALS['modelHubEnv']['model_hub.test_tenant_id'] = 2;
        check($send($body, $token, 'GET')['msg'] === '仅支持 POST 请求', '约定路由下 GET 不提交调用');
        check($send(['request_id' => [], 'prompt' => '你好'], $token)['code'] === 0, '无效请求号被拒绝');
        check($db->name('model_hub_call')->count() === 6, '拒绝的测试请求不创建调用单');

        $response = $send($body, $token);
        $data = $response['data'];
        check($response['code'] === 1 && $data['mock'] === true && $data['status'] === 'settled'
            && $data['reply'] === '模拟回复：你好' && $data['reserved_points'] === '10.00'
            && $data['charged_points'] === '3.00' && $data['actual_units'] === 3,
            '约定路由返回模拟回复和已结算的算力');
        check($send($body, $token)['data']['call_id'] === $data['call_id'], '原请求重放返回同一调用单');
        check($send(array_replace($body, ['prompt' => '换一个问题']), $token)['code'] === 0, '同请求号异参失败');
        check((float)$db->name('user')->where('id', 2)->value('user_money') === 7.0,
            '前端重试后仍只扣 3 点');
    } finally {
        $cache->delete($cacheKey);
        if (is_dir($cachePath)) {
            rmdir($cachePath);
        }
    }
    echo "PASS: Model Hub v1 路由、登录、租户限制和模拟结算\n";
}
