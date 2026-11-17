<?php

declare(strict_types=1);

use app\common\model\merchant\NfcCode;
use app\common\model\tenant\Tenant;
use app\common\service\merchant\NfcCodeService;

// 复用生成测试的隔离引导、内存连接和断言，同时回归已合并的生成功能。
// 运行方式：php tests/nfc-code/tenant-assignment.php [vendor/autoload.php]，需启用 pdo_sqlite。
require __DIR__ . '/generation.php';

// 只建立租户选择与有效性校验所需的字段，覆盖正常、停用和软删除三种情况。
$connection->execute('CREATE TABLE la_tenant (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    disable INTEGER NOT NULL DEFAULT 0,
    delete_time INTEGER DEFAULT NULL
)');
Tenant::query()->insertAll([
    ['id' => 1, 'name' => '租户甲', 'disable' => 0, 'delete_time' => null],
    ['id' => 2, 'name' => '停用租户', 'disable' => 1, 'delete_time' => null],
    ['id' => 3, 'name' => '已删除租户', 'disable' => 0, 'delete_time' => 1],
    ['id' => 4, 'name' => '租户乙', 'disable' => 0, 'delete_time' => null],
]);
$ids = NfcCode::order('id')->limit(5)->column('id');

// 所有非法请求都应先失败，库存保持未分配；不接受关联数组、空列表和非正整数。
foreach ([null, [], [0], [-1], [true], [1.5], ['bad'], ['key' => 1], array_fill(0, 1001, 1)] as $selection) {
    rejected(fn () => NfcCodeService::assignTenant(7, ['ids' => $selection, 'tenant_id' => 1]), 'Invalid selection accepted');
}
rejected(fn () => NfcCodeService::assignTenant(0, ['ids' => [$ids[0]], 'tenant_id' => 1]), 'Missing assignment administrator accepted');
foreach ([null, 0, 'bad', [], 2, 3, 999999] as $tenantId) {
    rejected(fn () => NfcCodeService::assignTenant(7, ['ids' => [$ids[0]], 'tenant_id' => $tenantId]), 'Invalid or unavailable tenant accepted');
}
same(0, NfcCode::whereNotNull('tenant_id')->count(), 'Validation must not change stock');

// 选项只返回有效租户；按 ID 的 OR 条件必须仍受 disable 和软删除约束。
same([4, 1], array_column(NfcCodeService::tenantChoices(), 'id'), 'Only available tenants are listed');
same([1], array_column(NfcCodeService::tenantChoices('租户甲'), 'id'), 'Tenant name search');
same([4], array_column(NfcCodeService::tenantChoices(' 4 '), 'id'), 'Tenant ID search');
same([], NfcCodeService::tenantChoices('2'), 'ID search cannot expose disabled tenant');
same([], NfcCodeService::tenantChoices('3'), 'ID search cannot expose deleted tenant');
same(['id', 'name'], array_keys(NfcCodeService::tenantChoices()[0]), 'Only minimal tenant fields are returned');

// 重复选择按一块计算，记录管理员和相同分配时间，编号及原生成批次保持不变。
$before = NfcCode::whereIn('id', [$ids[0], $ids[1]])->order('id')->select()->toArray();
same(['quantity' => 2], NfcCodeService::assignTenant(7, [
    'ids' => [$ids[1], (string)$ids[0], $ids[0]], 'tenant_id' => '1',
]), 'Assignment count deduplicates selected stock');
$assigned = NfcCode::whereIn('id', [$ids[0], $ids[1]])->order('id')->select()->toArray();
same(array_column($before, 'code'), array_column($assigned, 'code'), 'Assignment preserves code');
same(array_column($before, 'batch_no'), array_column($assigned, 'batch_no'), 'Assignment preserves generation batch');
foreach ($assigned as $row) {
    same(1, (int)$row['tenant_id'], 'Selected stock belongs to the target tenant');
    same(7, (int)$row['tenant_assigned_admin_id'], 'Assignment administrator is recorded');
    same(null, $row['store_id'], 'Tenant assignment does not bind a store');
    same(true, (int)$row['tenant_assigned_at'] > 0, 'Assignment timestamp is recorded');
}
same($assigned[0]['tenant_assigned_at'], $assigned[1]['tenant_assigned_at'], 'One assignment shares one timestamp');

// 混入不存在或已分配的码牌时，其他仍可更新的行也必须被回滚。
foreach ([[$ids[2], 999999], [$ids[0], $ids[2]], [$ids[0]]] as $selection) {
    rejected(fn () => NfcCodeService::assignTenant(8, ['ids' => $selection, 'tenant_id' => 4]), 'Conflicting selection must reject the whole batch');
    same(null, NfcCode::where('id', $ids[2])->value('tenant_id'), 'Available stock rolls back on batch conflict');
    same(1, (int)NfcCode::where('id', $ids[0])->value('tenant_id'), 'Existing tenant cannot be overwritten');
    same(7, (int)NfcCode::where('id', $ids[0])->value('tenant_assigned_admin_id'), 'Existing audit data cannot be overwritten');
}
rejected(fn () => NfcCodeService::assignTenant(7, ['ids' => [$ids[0]], 'tenant_id' => 1]), 'Same stock cannot be assigned again');

// 分配和未分配筛选需与总数一致，列表带上名称及分配时间，不存用户归属。
$list = NfcCodeService::adminLists(['status' => 'assigned']);
same(2, $list['count'], 'Assigned count');
same(['租户甲', '租户甲'], array_column($list['lists'], 'tenant_name'), 'Tenant names are batched into the list');
same(NfcCode::whereNull('tenant_id')->count(), NfcCodeService::adminLists(['status' => 'unassigned'])['count'], 'Unassigned count');
same(false, array_key_exists('user_id', $list['lists'][0]), 'Stock does not store user ownership');
rejected(fn () => NfcCodeService::adminLists(['status' => 'invalid']), 'Unknown status must fail');

// 删除租户后保留库存历史归属及时间，但不再展示被软删除租户的名称。
Tenant::where('id', 1)->update(['delete_time' => time()]);
$history = NfcCodeService::adminLists(['status' => 'assigned']);
same(2, $history['count'], 'Deleting tenant preserves stock history');
same('', $history['lists'][0]['tenant_name'], 'Deleted tenant name is hidden');
same(1, (int)$history['lists'][0]['tenant_id'], 'Historical tenant ID is retained');

// 仅通过模型写入测试夹具，验证表结构支持同一门店关联多块码牌。
// 此处不是门店激活接口测试，门店所属用户和租户的绑定校验留待下一阶段实现。
NfcCode::whereIn('id', [$ids[0], $ids[1]])->update(['store_id' => 88]);
same(2, NfcCode::where(['tenant_id' => 1, 'store_id' => 88])->count(), 'One store can be linked to multiple codes');

echo "PASS NFC tenant choices, assignment validation, audit, ownership, filtering and atomic rollback\n";
