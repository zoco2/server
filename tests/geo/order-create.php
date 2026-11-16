<?php

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
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
        public static bool $reject = false;
        public static function accessToken(int $tenantId): string
        {
            if ($tenantId !== 7) throw new \RuntimeException('Wrong tenant');
            return 'test-token';
        }
        public static function createOrder(string $token, array $order): void
        {
            if ($token !== 'test-token') throw new \RuntimeException('Wrong token');
            self::$submissions[] = $order;
            if (self::$reject) throw new \Exception('资源价格不匹配');
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
    $db->setConfig([
        'default' => 'test',
        'connections' => ['test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'fields_strict' => true, 'break_reconnect' => false,
        ]],
    ]);
    $connection = $db->connect();
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
            create_time INTEGER, update_time INTEGER)',
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
    $db->name('geo_media')->insertAll([
        ['id' => 59479, 'tenant_id' => 7, 'sourceid' => 'single-media', 'resource_type' => 1,
            'name' => '单价媒体', 'costprice' => 322, 'price_ratio' => 1.25,
            'price_type_json' => '[]', 'flag' => 1, 'deleted' => 1],
        ['id' => 59480, 'tenant_id' => 7, 'sourceid' => 'multi-media', 'resource_type' => 12,
            'name' => '多价媒体', 'costprice' => 80, 'price_ratio' => 1.25,
            'price_type_json' => '[{"type":2,"price":80},{"type":3,"price":120}]', 'flag' => 1, 'deleted' => 1],
    ]);

    $GLOBALS['geoOrderSuffixes'] = [123, 123, 124, 125];
    $first = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($first['status'] === 0 && $first['remote_flag'] === 0, 'Successful submission must be pending');
    checkOrder($first['cost_price'] === 322.0 && $first['sale_price'] === 402.5, 'Single-price order quote must match');
    checkOrder(array_column($first['logs'], 'action') === ['create', 'push'], 'Create and push logs must persist');
    checkOrder(preg_match('/\AGEO\d{20}\z/', $first['order_no']) === 1, 'Order number format must be preserved');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['price'] === 322.0, 'Gateway must receive cost, not sale price');
    checkOrder(AigcGeoManuscriptService::$submissions[0]['content'] === $first['content_url'], 'Gateway must receive preview URL');

    $second = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59480, 'price_type' => 3]);
    checkOrder($second['order_no'] !== $first['order_no'] && str_ends_with($second['order_no'], '000124'), 'Existing order number must cause a retry');
    checkOrder($db->name('geo_order')->count() === 2, 'Collision retry must create exactly one additional order');
    checkOrder($second['cost_price'] === 120.0 && $second['sale_price'] === 150.0, 'Selected multi-price category must survive create and push');
    checkOrder(AigcGeoManuscriptService::$submissions[1]['priceType'] === 3, 'Gateway must receive selected category');

    AigcGeoManuscriptService::$reject = true;
    $rejected = GeoOrderService::create(23, ['article_id' => 14, 'media_id' => 59479, 'price_type' => 1]);
    checkOrder($rejected['status'] === 2 && $rejected['fail_reason'] === '资源价格不匹配', 'Gateway rejection must remain inspectable');
    checkOrder(!$rejected['can_retry'] && count($rejected['logs']) === 2, 'Permanent rejection must retain logs without automatic retries');
    checkOrder($db->name('geo_order')->count() === 3 && $GLOBALS['geoOrderSuffixes'] === [], 'All creation paths must complete');

    echo "PASS: real ORM order creation, number collision retry, single/multi-price submission and rejected-order persistence\n";
}
