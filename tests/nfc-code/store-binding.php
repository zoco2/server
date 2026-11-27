<?php

declare(strict_types=1);

use app\common\enum\AdminTerminalEnum;
use app\common\model\merchant\NfcCode;
use app\common\model\tenant\Tenant;
use app\common\service\merchant\NfcCodeService;

// 完整复用生成与分配回归，所有记录都在 SQLite 内存库，不读取现场数据库。
// 运行：php tests/nfc-code/store-binding.php [vendor/autoload.php]，需启用 pdo_sqlite。
require __DIR__ . '/generate-with-tenant.php';


request()->source = AdminTerminalEnum::PLATFORM;
request()->tenantId = null;
Tenant::query()->insert(['id' => 5, 'name' => '共享表租户', 'sn' => 'shared', 'tactics' => 0, 'disable' => 0, 'delete_time' => null]);

// 门店使用项目原有模型策略，库存始终为平台生成的同一张表。
foreach (['la_merchant_store', 'la_merchant_store_test'] as $table) {
    $connection->execute('CREATE TABLE ' . $table . ' (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        delete_time INTEGER DEFAULT NULL
    )');
}
$connection->execute("INSERT INTO la_merchant_store VALUES (1,5,10,'共享门店',NULL)");
$connection->execute("INSERT INTO la_merchant_store_test VALUES (1,4,10,'分表门店',NULL),(2,4,10,'其他门店',NULL),(3,4,11,'另一用户门店',NULL),(4,4,10,'已删除门店',1)");
$rows = [];
foreach (['TEN401' => 4, 'TEN402' => 4, 'TEN403' => 4, 'TEN501' => 5, 'PLAT01' => null] as $code => $tenantId) {
    $rows[] = [
        'code' => $code, 'batch_no' => 'binding_fixture', 'tenant_id' => $tenantId,
        'created_admin_id' => 7, 'create_time' => 1, 'update_time' => 1,
    ];
}
NfcCode::query()->insertAll($rows);

request()->source = AdminTerminalEnum::USER;
request()->tenantId = 4;
request()->userId = 10;
same(0, NfcCodeService::storeLists(10, ['store_id' => 1])['count'], 'Own store initially has no bound codes');

// 用户只可给自己的未删除门店绑定当前租户已经获得的码牌。
foreach ([0, 3, 4, 999999] as $storeId) {
    rejected(fn () => NfcCodeService::activate(10, ['store_id' => $storeId, 'code' => 'TEN401']), 'Invalid or unowned store accepted');
    rejected(fn () => NfcCodeService::storeLists(10, ['store_id' => $storeId]), 'Unowned store list exposed');
}
foreach (['ABCDEF', '123456', [], 'PLAT01', 'TEN501', 'MISS01'] as $code) {
    rejected(fn () => NfcCodeService::activate(10, ['store_id' => 1, 'code' => $code]), 'Unavailable or invalid code accepted');
}
rejected(fn () => NfcCodeService::activate(0, ['store_id' => 1, 'code' => 'TEN401']), 'Anonymous activation accepted');
// 业务方法不接收租户参数，即使输入夹带 tenant_id，也不能改写中间件确定的上下文。
rejected(fn () => NfcCodeService::activate(10, ['store_id' => 1, 'code' => 'TEN501', 'tenant_id' => 5]), 'Input redirected the model tenant scope');

// 首次绑定只更新库存上的 store_id 和时间，不创建用户归属或修改租户分配。
$first = NfcCodeService::activate(10, ['store_id' => 1, 'code' => ' ten401 ']);
same('TEN401', $first['code'], 'Code is normalized');
same(1, $first['store_id'], 'Code binds to requested own store');
same(true, $first['activated_at'] > 1, 'Binding reports an updated timestamp');
same(['id', 'code', 'store_id', 'activated_at'], array_keys($first), 'Only user-facing code fields are returned');

// 模拟此前已完成的绑定时间，再次提交相同请求必须返回原结果而不覆盖时间。
NfcCode::where('code', 'TEN401')->update(['update_time' => 100]);
$retry = NfcCodeService::activate(10, ['store_id' => 1, 'code' => 'TEN401']);
same(100, $retry['activated_at'], 'Repeated activation is idempotent');
rejected(fn () => NfcCodeService::activate(10, ['store_id' => 2, 'code' => 'TEN401']), 'Existing binding was moved to another store');
rejected(fn () => NfcCodeService::activate(11, ['store_id' => 3, 'code' => 'TEN401']), 'Another user overwrote a binding');

// 一店多码：第二块编号继续绑定到同一个门店，列表按页返回所有已绑定记录。
NfcCodeService::activate(10, ['store_id' => 1, 'code' => 'TEN402']);
$list = NfcCodeService::storeLists(10, ['store_id' => 1, 'page_no' => 2, 'page_size' => 1]);
same(2, $list['count'], 'One store can bind multiple codes');
same(['TEN401'], array_column($list['lists'], 'code'), 'Binding list is paginated by descending ID');
same(0, NfcCodeService::storeLists(10, ['store_id' => 2])['count'], 'Other owned store has a separate binding list');
rejected(fn () => NfcCodeService::storeLists(11, ['store_id' => 1]), 'Another user read a private store list');
rejected(fn () => NfcCodeService::storeLists(10, ['store_id' => 1, 'page_no' => 0]), 'Invalid page accepted');
same(2, NfcCodeService::storeLists(10, ['store_id' => 1, 'tenant_id' => 5])['count'], 'List uses middleware context rather than input tenant');

// 门店主键可能在不同租户表中相同，码牌查询必须同时限定当前租户。
request()->tenantId = 5;
same(0, NfcCodeService::storeLists(10, ['store_id' => 1])['count'], 'Same numeric store ID does not expose another tenant binding');
NfcCodeService::activate(10, ['store_id' => 1, 'code' => 'TEN501']);
same(['TEN501'], array_column(NfcCodeService::storeLists(10, ['store_id' => 1])['lists'], 'code'), 'Current store sees only its own tenant codes');
request()->tenantId = 4;
same(2, NfcCodeService::storeLists(10, ['store_id' => 1])['count'], 'Original store bindings remain intact');

// 门店更换归属后，既有绑定记录展示当前所属用户。
foreach (['la_user', 'la_user_test'] as $table) {
    $connection->execute('CREATE TABLE ' . $table . ' (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        nickname TEXT NOT NULL,
        account TEXT NOT NULL,
        delete_time INTEGER DEFAULT NULL
    )');
}
$connection->execute("INSERT INTO la_user VALUES (10,5,'其他租户用户','outside',NULL)");
$connection->execute("INSERT INTO la_user_test VALUES (10,4,'最初绑定人','original',NULL),(11,4,'','current',NULL)");
$connection->execute('UPDATE la_merchant_store_test SET user_id=11, delete_time=1 WHERE id=1');

request()->source = AdminTerminalEnum::TENANT;
$bound = NfcCodeService::tenantLists([
    'batch_no' => 'binding_fixture', 'status' => 'bound', 'page_no' => 2, 'page_size' => 1, 'tenant_id' => 5,
]);
same(2, $bound['count'], 'Tenant list counts only current tenant matches');
same(['TEN401'], array_column($bound['lists'], 'code'), 'Tenant list paginates matching codes');
$card = $bound['lists'][0];
same(true, $card['is_bound'], 'Bound status survives soft-deleted store');
same('分表门店', $card['store_name'], 'Store lookup uses current split tenant table');
same(11, (int)$card['bound_user_id'], 'Tenant list resolves the current store owner ID');
same('current', $card['bound_user_name'], 'Tenant list falls back to the new owner account');
same(100, $card['activated_at'], 'Tenant list reports original activation time');

$second = NfcCodeService::tenantLists(['code' => ' ten402 '])['lists'][0];
same('current', $second['bound_user_name'], 'Multiple cards on a store show the same current owner');
$unbound = NfcCodeService::tenantLists(['batch_no' => 'binding_fixture', 'status' => 'unbound']);
same(['TEN403'], array_column($unbound['lists'], 'code'), 'Unbound filter excludes other tenants and platform stock');
same(false, $unbound['lists'][0]['is_bound'], 'Unbound status derived from missing store');
same(null, $unbound['lists'][0]['activated_at'], 'Unbound code has no activation time');

request()->tenantId = 5;
$other = NfcCodeService::tenantLists(['batch_no' => 'binding_fixture'])['lists'][0];
same('TEN501', $other['code'], 'Shared-table tenant sees its own card');
same('共享门店', $other['store_name'], 'Overlapping store IDs are resolved in the correct tenant');
same('其他租户用户', $other['bound_user_name'], 'Overlapping user IDs are resolved in the correct tenant');
rejected(fn () => NfcCodeService::tenantLists(['status' => 'invalid']), 'Invalid list status accepted');

request()->source = AdminTerminalEnum::PLATFORM;
rejected(fn () => NfcCodeService::tenantLists([]), 'Tenant list accepted an unscoped platform context');
request()->source = AdminTerminalEnum::TENANT;
request()->tenantId = null;
rejected(fn () => NfcCodeService::tenantLists([]), 'Tenant list accepted missing tenant context');
request()->source = AdminTerminalEnum::USER;

// 缺少认证租户时直接拒绝，不允许退化成无租户限制的库存查询。
request()->tenantId = null;
same(0, NfcCode::count(), 'Missing tenant context cannot read allocated codes');
rejected(fn () => NfcCodeService::storeLists(10, ['store_id' => 1]), 'Missing tenant context accepted');

echo "PASS NFC store binding, tenant lists, current store ownership and tenant isolation\n";
