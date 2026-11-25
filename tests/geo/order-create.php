<?php

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    function generate_sn(...$unused): string { return bin2hex(random_bytes(10)); }
}

namespace app\common\service\point_alert {
    final class AlertService { public static function touch(int $tenantId, int $userId): void {} }
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
        public static function accessToken(): string
        {
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
    use app\common\enum\user\AccountLogEnum;
    use app\common\model\geo\GeoOrder;
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
    $connection = $db->connect();
    $connection->execute('CREATE TABLE config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT)');
    $connection->name('config')->insert(['type' => 'business_price', 'name' => 'settings', 'value' => '{"media_markup_percent":25}']);
    foreach ([
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
            power_log_id INTEGER DEFAULT 0, last_query_time INTEGER DEFAULT 0,
            publish_url TEXT DEFAULT \'\', finish_time INTEGER DEFAULT 0, create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE geo_order_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, order_id INTEGER,
            order_no TEXT, from_status INTEGER, to_status INTEGER, action TEXT, message TEXT,
            create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE user (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_money REAL, delete_time INTEGER NULL,
            create_time INTEGER, update_time INTEGER)',
        'CREATE TABLE user_account_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER,
            sn TEXT, change_type INTEGER, change_object INTEGER, action INTEGER, change_amount REAL, left_amount REAL,
            source_sn TEXT, remark TEXT, extra TEXT, delete_time INTEGER NULL, create_time INTEGER, update_time INTEGER)',
    ] as $sql) {
        $connection->execute($sql);
    }
    $db->name('geo_article')->insert([
        'id' => 14, 'tenant_id' => 7, 'user_id' => 23,
        'title' => '测试稿件', 'content' => '<p>正文</p>',
        'preview_url' => 'https://tenant.example/api/geo.article/preview?token=test',
    ]);
    $db->name('geo_media')->insertAll([
        ['id' => 59479, 'tenant_id' => 7, 'sourceid' => 'single-media', 'resource_type' => 1,
            'name' => '单价媒体', 'costprice' => 322, 'price_ratio' => 1.75,
            'price_type_json' => '[]', 'flag' => 1, 'deleted' => 1],
        ['id' => 59480, 'tenant_id' => 7, 'sourceid' => 'multi-media', 'resource_type' => 12,
            'name' => '多价媒体', 'costprice' => 80, 'price_ratio' => 1.75,
            'price_type_json' => '[{"type":2,"price":80},{"type":3,"price":120}]', 'flag' => 1, 'deleted' => 1],
    ]);

    $db->name('user')->insert(['id' => 23, 'tenant_id' => 7, 'user_money' => 100000]);
    $balance = static fn (): int => (int)$db->name('user')->where('id', 23)->value('user_money');
    $ledger = static fn (array $order): array => $db->name('user_account_log')
        ->whereLike('source_sn', $order['order_no'] . '-%')->order('id')->select()->toArray();
    $GLOBALS['geoOrderSuffixes'] = [123, 123, 124, 125, 126, 127];
    $first = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($first['status'] === 0 && $first['remote_flag'] === 0, 'Successful submission must be pending');
    checkOrder($first['cost_price'] === 322.0 && $first['sale_price'] === 402.5, 'Single-price order quote must match');
    checkOrder($first['charged_points'] === 40250 && $balance() === 59750, 'Reserve the marked-up price in points');
    checkOrder(array_column($first['logs'], 'action') === ['create', 'push'], 'Create and push logs must persist');
    checkOrder(preg_match('/\AGEO\d{20}\z/', $first['order_no']) === 1, 'Order number format must be preserved');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['price'] === 322.0, 'Gateway must receive cost, not sale price');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['content'] === $first['content_url'], 'Gateway must receive preview URL');
    AigcGeoManuscriptService::$flag = GeoOrder::STATUS_DONE;
    $published = GeoOrderService::sync(GeoOrder::find($first['id']));
    checkOrder((int)$published['status'] === GeoOrder::STATUS_DONE
        && $balance() === 59750 && count($ledger($first)) === 1, 'Successful publishing keeps the reservation without a second debit');

    $second = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59480, 'price_type' => 3]);
    checkOrder($second['order_no'] !== $first['order_no'] && str_ends_with($second['order_no'], '000124'), 'Existing order number must cause a retry');
    checkOrder($db->name('geo_order')->count() === 2, 'Collision retry must create exactly one additional order');
    checkOrder($second['cost_price'] === 120.0 && $second['sale_price'] === 150.0, 'Selected multi-price category must survive create and push');
    checkOrder(AigcGeoManuscriptService::$submissions[1]['priceType'] === 3, 'Gateway must receive selected category');
    checkOrder($second['charged_points'] === 15000 && $balance() === 44750, 'Reserve the selected tier only');

    AigcGeoManuscriptService::$error = '资源价格不匹配';
    $rejected = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($rejected['status'] === 2 && $rejected['fail_reason'] === '资源价格不匹配', 'Gateway rejection must remain inspectable');
    checkOrder(!$rejected['can_retry'] && count($rejected['logs']) === 2, 'Permanent rejection must retain logs without automatic retries');
    checkOrder($rejected['charged_points'] === 0 && $balance() === 44750, 'Permanent rejection returns the reservation');

    $db->name('geo_media')->where('id', 59479)->update(['costprice' => 2.42]);
    AigcGeoManuscriptService::$error = '账户余额不足';
    $providerRejected = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'expected_points' => 303]);
    $rows = $ledger($providerRejected);
    checkOrder($providerRejected['status'] === GeoOrder::STATUS_REJECTED && $providerRejected['can_retry']
        && $providerRejected['fail_reason'] === '账户余额不足' && $providerRejected['charged_points'] === 0, 'Provider balance failure is recorded');
    checkOrder($balance() === 44750
        && array_map('intval', array_column($rows, 'change_amount')) === [303, 303]
        && array_map('intval', array_column($rows, 'change_type')) === [AccountLogEnum::UM_DEC_APP_CONSUME, AccountLogEnum::UM_INC_APP_REFUND],
        'Provider balance failure debits and returns exactly 303 points');

    AigcGeoManuscriptService::$error = '';
    AigcGeoManuscriptService::$uncertain = true;
    $retry = GeoOrder::find($providerRejected['id']);
    $pending = GeoOrderService::push($retry);
    checkOrder((int)$pending['status'] === GeoOrder::STATUS_PENDING && $pending['order_no'] === $providerRejected['order_no']
        && $balance() === 44447 && count($ledger($providerRejected)) === 3, 'A timed-out retry keeps one reservation on the original order');
    try {
        GeoOrderService::push($retry);
        throw new \LogicException('Stale retry should be rejected');
    } catch (\Exception $e) {
        checkOrder(str_contains($e->getMessage(), '订单状态已变化'), 'A stale retry cannot charge or push again');
    }
    AigcGeoManuscriptService::$flag = GeoOrder::STATUS_MISSING;
    GeoOrderService::sync($pending);
    GeoOrderService::sync($pending);
    checkOrder($balance() === 44750 && count($ledger($providerRejected)) === 4, 'Repeated failure queries return the renewed reservation once');

    try {
        GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'expected_points' => 1]);
        throw new \LogicException('Stale quote should be rejected');
    } catch (\Exception $e) {
        checkOrder(str_contains($e->getMessage(), '报价已变动'), 'Price changes require a fresh quote');
    }
    $db->name('user')->where('id', 23)->update(['user_money' => 302]);
    try {
        GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479]);
        throw new \LogicException('Insufficient user balance should be rejected');
    } catch (\RuntimeException $e) {
        checkOrder(str_contains($e->getMessage(), '算力不足'), 'User balance is checked before provider submission');
    }
    checkOrder($balance() === 302 && $db->name('geo_order')->count() === 4 && count(AigcGeoManuscriptService::$submissions) === 5,
        'Local validation failures do not create orders or submit to the provider');

    $legacy = GeoOrder::create(['user_id' => 23, 'order_no' => 'GEO-legacy', 'sale_price' => 3.03,
        'status' => GeoOrder::STATUS_PENDING, 'remote_flag' => GeoOrder::STATUS_PENDING]);
    GeoOrderService::sync($legacy);
    checkOrder($balance() === 302 && $ledger($legacy->toArray()) === [], 'Old orders without a reservation produce no refund');

    echo "PASS: media order pricing, reservation, provider balance rejection, retry and idempotent refund\n";
}
