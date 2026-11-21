<?php

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    function request(): object {
        return (object)['source' => \app\common\enum\AdminTerminalEnum::USER, 'tenantId' => 7];
    }
    function generate_sn(...$unused): string { return bin2hex(random_bytes(12)); }
}

namespace app\common\service\point_alert {
    final class AlertService { public static function touch(int $tenant, int $user): void {} }
}

namespace app\common\service {
    class PointUnitService { public static function unit(): string { return '算力'; } }
}

namespace app\common\model {
    // Use the real ORM and GEO models with an isolated database, without app tenant scopes.
    class BaseModel extends \think\Model
    {
        protected $autoWriteTimestamp = 'int';
        public static function onBeforeInsert($model): void { $model->tenant_id = 7; }
    }
}

namespace app\common\service\geo {
    // Deterministic candidates allow exercising a real order-number collision.
    function date(string $format): string { return \date($format, 1790000000); }
    function random_int(int $min, int $max): int
    {
        if ($GLOBALS['geoOrderSuffixes'] === []) throw new \RuntimeException('Unexpected order-number retry');
        return array_shift($GLOBALS['geoOrderSuffixes']);
    }

    class AigcGeoManuscriptService
    {
        public const RESOURCE_TYPES = [1 => '软文', 12 => '短视频'];
        public static array $submissions = [];
        public static string $error = '';
        public static bool $uncertain = false;
        public static int $flag = 0;
        public static function publicMessage(string $message): string
        {
            return $message;
        }
        public static function accessToken(int $tenantId): string
        {
            if ($tenantId !== 7) throw new \RuntimeException('Wrong tenant');
            return 'test-token';
        }
        public static function createOrder(string $token, array $order): void
        {
            if ($token !== 'test-token') throw new \RuntimeException('Wrong token');
            self::$submissions[] = $order;
            if (self::$uncertain) throw new \UnexpectedValueException('提交超时');
            if (self::$error !== '') throw new \Exception(self::$error);
        }
        public static function queryOrder(string $token, string $orderNo): array
        {
            return ['flag' => self::$flag, 'status' => '测试', 'result' => '', 'url' => ''];
        }
    }
}

namespace {
    use app\common\service\geo\AigcGeoManuscriptService;
    use app\common\service\geo\GeoOrderService;
    use think\DbManager;

    function checkOrder(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    $db = new DbManager();
    \think\Container::getInstance()->instance(DbManager::class, $db);
    $db->setConfig([
        'default' => 'test',
        'connections' => ['test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'fields_strict' => true, 'break_reconnect' => false,
        ]],
    ]);
    \think\Model::setDb($db);
    $connection = $db->connect();
    foreach ([
        'CREATE TABLE tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)',
        'CREATE TABLE geo_article (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER,
            title TEXT, content TEXT, preview_url TEXT, delete_time INTEGER NULL, create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE geo_media (id INTEGER PRIMARY KEY, tenant_id INTEGER, sourceid TEXT, resource_type INTEGER,
            name TEXT, costprice REAL, price_ratio REAL, price_type_json TEXT, flag INTEGER, deleted INTEGER,
            create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE geo_order (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER,
            order_no TEXT UNIQUE, article_id INTEGER, media_id INTEGER, sourceid TEXT, resource_type INTEGER,
            media_name TEXT, price_type INTEGER, cost_price REAL, sale_price REAL, title TEXT, content_url TEXT,
            remark TEXT, origin TEXT, status INTEGER, remote_flag INTEGER, retryable INTEGER DEFAULT 0,
            push_attempts INTEGER DEFAULT 0, last_push_time INTEGER DEFAULT 0, fail_reason TEXT DEFAULT \'\',
            power_log_id INTEGER DEFAULT 0, last_query_time INTEGER DEFAULT 0, publish_url TEXT DEFAULT \'\',
            create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE user (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_money REAL, delete_time INTEGER NULL,
            create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE user_account_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER,
            sn TEXT, change_type INTEGER, change_object INTEGER, action INTEGER, change_amount REAL, left_amount REAL,
            source_sn TEXT, remark TEXT, extra TEXT, delete_time INTEGER NULL, create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE geo_order_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, order_id INTEGER,
            order_no TEXT, from_status INTEGER, to_status INTEGER, action TEXT, message TEXT,
            create_time INTEGER, update_time INTEGER)',
    ] as $sql) {
        $connection->execute($sql);
    }
    $db->name('geo_article')->insert([
        'id' => 14, 'tenant_id' => 7, 'user_id' => 23,
        'title' => '测试稿件', 'content' => '<p>正文</p>',
        'preview_url' => 'https://tenant.example/api/geo.article/preview?token=test',
    ]);
    $db->name('tenant_config')->insert([
        'tenant_id' => 7, 'type' => 'business_price', 'name' => 'settings', 'value' => '{"media_markup_percent":25}',
    ]);
    $db->name('geo_media')->insertAll([
        ['id' => 59479, 'tenant_id' => 7, 'sourceid' => 'single-media', 'resource_type' => 1,
            'name' => '单价媒体', 'costprice' => 322, 'price_ratio' => 1.1,
            'price_type_json' => '[]', 'flag' => 1, 'deleted' => 1],
        ['id' => 59480, 'tenant_id' => 7, 'sourceid' => 'multi-media', 'resource_type' => 12,
            'name' => '多价媒体', 'costprice' => 80, 'price_ratio' => 1.1,
            'price_type_json' => '[{"type":2,"price":80},{"type":3,"price":120}]', 'flag' => 1, 'deleted' => 1],
    ]);

    $db->name('user')->insert(['id' => 23, 'tenant_id' => 7, 'user_money' => 100000]);
    $balance = static fn (): int => (int)$db->name('user')->where('id', 23)->value('user_money');
    $GLOBALS['geoOrderSuffixes'] = [123, 123, 124, 125, 126, 127];
    $first = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($first['status'] === 0 && $first['remote_flag'] === 0, 'Successful submission must be pending');
    checkOrder($first['cost_price'] === 322.0 && $first['sale_price'] === 402.5, 'Single-price order quote must match');
    checkOrder($first['charged_points'] === 40250 && $balance() === 59750, 'Charge marked-up sale in points');
    checkOrder(array_column($first['logs'], 'action') === ['create', 'push'], 'Create and push logs must persist');
    checkOrder(preg_match('/\AGEO\d{20}\z/', $first['order_no']) === 1, 'Order number format must be preserved');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['price'] === 322.0, 'Gateway must receive cost, not sale price');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['content'] === $first['content_url'], 'Gateway must receive preview URL');

    $second = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59480, 'price_type' => 3]);
    checkOrder($second['order_no'] !== $first['order_no'] && str_ends_with($second['order_no'], '000124'), 'Existing order number must cause a retry');
    checkOrder($db->name('geo_order')->count() === 2, 'Collision retry must create exactly one additional order');
    checkOrder($second['cost_price'] === 120.0 && $second['sale_price'] === 150.0, 'Selected multi-price category must survive create and push');
    checkOrder(AigcGeoManuscriptService::$submissions[1]['priceType'] === 3, 'Gateway must receive selected category');

    AigcGeoManuscriptService::$error = '资源价格不匹配';
    $rejected = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($rejected['status'] === 2 && $rejected['fail_reason'] === '资源价格不匹配', 'Gateway rejection must remain inspectable');
    checkOrder(!$rejected['can_retry'] && count($rejected['logs']) === 2, 'Permanent rejection must retain logs without automatic retries');
    checkOrder($rejected['charged_points'] === 0 && $balance() === 44750, 'Reject must return the full original charge');

    AigcGeoManuscriptService::$error = '服务暂不可用';
    $retry = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479]);
    checkOrder($retry['can_retry'] && $balance() === 44750, 'Explicit retryable failure is refunded');
    AigcGeoManuscriptService::$error = '';
    AigcGeoManuscriptService::$uncertain = true;
    $retryOrder = \app\common\model\geo\GeoOrder::find($retry['id']);
    $pending = GeoOrderService::push($retryOrder);
    checkOrder((int)$pending['status'] === 0 && $balance() === 4500, 'Ambiguous submission is charged once while polling');

    try {
        GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59480, 'price_type' => 3]);
        throw new RuntimeException('Should reject insufficient funds');
    } catch (RuntimeException $e) {
        checkOrder(str_contains($e->getMessage(), '不足'), 'Check available balance before sending');
    }
    checkOrder($db->name('geo_order')->count() === 4 && count(AigcGeoManuscriptService::$submissions) === 5, 'Insufficient balance rolls back before push');
    AigcGeoManuscriptService::$flag = 100;
    GeoOrderService::sync($pending);
    GeoOrderService::sync($pending);
    checkOrder($balance() === 44750, 'Polling a definitive failure twice refunds exactly once');
    try {
        GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'expected_points' => 1]);
        throw new RuntimeException('Changed quote must require confirmation');
    } catch (\Exception $e) {
        checkOrder(str_contains($e->getMessage(), '报价已变动'), 'Reject stale quote before charging');
    }

    echo "PASS: real ORM media pricing, order creation, precharge, rejection, retry, insufficient balance and idempotent refund\n";
}
