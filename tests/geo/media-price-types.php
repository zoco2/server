<?php

// Exercise catalog and order pricing with platform settings in an isolated database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\model\geo\GeoMedia;
use app\common\service\geo\GeoMediaCatalogService;
use app\common\service\geo\GeoMediaSyncService;
use app\common\service\geo\GeoOrderService;

$db = new \think\DbManager();
$db->setConfig(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'trigger_sql' => false,
]]]);
\think\Model::setDb($db);
$db->execute('CREATE TABLE config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT)');
$db->name('config')->insert(['type' => 'business_price', 'name' => 'settings', 'value' => '{"media_markup_percent":25}']);

function checkPrice(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function mediaFixture(array $overrides = []): GeoMedia
{
    return new GeoMedia($overrides + [
        'id' => 59479,
        'tenant_id' => 7,
        'resource_type' => 1,
        'name' => '单价媒体',
        'costprice' => 322,
        'price_ratio' => 1.75,
        'price_type_json' => [],
    ]);
}

$resolve = new ReflectionMethod(GeoOrderService::class, 'resolvePrice');
$costOf = new ReflectionMethod(GeoOrderService::class, 'costOf');

function rejectPrice(GeoMedia $media, int $type): void
{
    global $resolve;
    try {
        $resolve->invoke(null, $media, $type);
    } catch (Exception $e) {
        checkPrice($e->getMessage() === '该媒体没有价格类别 ' . $type . '，请重新选择', 'Unexpected pricing error');
        return;
    }
    throw new RuntimeException('Unavailable price type must remain rejected');
}

foreach ([[], null, '', '[]', 'null'] as $emptyPrices) {
    foreach ([1, 2, 15] as $resourceType) {
        $media = mediaFixture(['resource_type' => $resourceType, 'price_type_json' => $emptyPrices]);
        checkPrice($resolve->invoke(null, $media, 1) === [322.0, 402.5], 'Single-price media must resolve category 1 using cost and markup');
        checkPrice($costOf->invoke(null, $media, 1) === 322.0, 'Order push must use the same cost as order creation');
        $public = GeoMediaCatalogService::publicRow($media->toArray());
        checkPrice($public['price_types'] === [['type' => 1, 'sale_price' => 402.5]], 'Catalog and order must expose the same default category and sale price');
        foreach (['costprice', 'price_ratio', 'price_type_json'] as $internal) {
            checkPrice(!array_key_exists($internal, $public), 'Public pricing must omit internal fields');
        }
        rejectPrice($media, 2);
    }
}

foreach ([12, 13, 14, 0, 99] as $resourceType) {
    rejectPrice(mediaFixture(['resource_type' => $resourceType]), 1);
}
foreach ([0, -1, null, 'invalid', INF, NAN] as $invalidCost) {
    rejectPrice(mediaFixture(['costprice' => $invalidCost]), 1);
}
foreach (['malformed JSON', [['type' => 0, 'price' => 322]]] as $invalidPrices) {
    rejectPrice(mediaFixture(['price_type_json' => $invalidPrices]), 1);
}

$tiers = [['type' => 2, 'price' => 80], ['type' => 3, 'price' => 120]];
foreach ([$tiers, json_encode($tiers)] as $storedTiers) {
    foreach ([1, 12, 13, 14] as $resourceType) {
        $media = mediaFixture(['resource_type' => $resourceType, 'price_type_json' => $storedTiers]);
        checkPrice($resolve->invoke(null, $media, 2) === [80.0, 100.0], 'Explicit prices must take precedence over the base cost');
        checkPrice($resolve->invoke(null, $media, 3) === [120.0, 150.0], 'Multiple price categories must retain distinct quotes');
        rejectPrice($media, 1);
    }
}

$syncPrices = new ReflectionMethod(GeoMediaSyncService::class, 'priceTypes');
foreach ([12, 13, 14] as $resourceType) {
    foreach ([['costprice' => 100], ['costprice' => 100, 'prices' => []],
        ['costprice' => 100, 'prices' => 'invalid JSON'],
        ['costprice' => 100, 'prices' => [['type' => 2, 'price' => 'invalid']]]] as $raw) {
        $synced = $syncPrices->invoke(null, $raw, $resourceType);
        checkPrice($synced === [], 'Sync must not fabricate category 1 when a multi-price resource has no valid tiers');
        $media = mediaFixture(['resource_type' => $resourceType, 'price_type_json' => $synced]);
        rejectPrice($media, 1);
        $public = GeoMediaCatalogService::publicRow($media->toArray());
        checkPrice(empty($public['price_types']), 'Missing multi-price tiers must stay unavailable to the frontend');
    }
    foreach (['pricetype', 'priceType', 'prices'] as $field) {
        foreach ([$tiers, json_encode($tiers)] as $rawTiers) {
            $synced = $syncPrices->invoke(null, ['costprice' => 999, $field => $rawTiers], $resourceType);
            checkPrice($synced === [['type' => 2, 'price' => 80.0], ['type' => 3, 'price' => 120.0]], 'Sync must preserve explicit tiers, including JSON strings');
            $media = mediaFixture(['resource_type' => $resourceType, 'price_type_json' => $synced]);
            checkPrice($resolve->invoke(null, $media, 3) === [120.0, 150.0], 'Submission must use the selected tier after sync');
            rejectPrice($media, 1);
        }
    }
}
checkPrice($syncPrices->invoke(null, ['costprice' => 322], 1) === [['type' => 1, 'price' => 322.0]], 'Sync must retain the default category for single-price resources');
foreach ([0, -1, 'invalid', INF, NAN] as $invalidCost) {
    checkPrice($syncPrices->invoke(null, ['costprice' => $invalidCost], 1) === [], 'Sync must not invent a free or invalid default quote');
}

echo "PASS: single/multi-price sync, public quotes, order create/push pricing and strict category checks\n";
