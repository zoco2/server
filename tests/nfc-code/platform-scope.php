<?php

declare(strict_types=1);

use app\common\enum\AdminTerminalEnum;
use app\common\model\merchant\NfcCode;
use app\common\model\tenant\Tenant;
use app\common\service\merchant\NfcCodeService;

// 复用内存库和现有回归用例，不读取现场数据库或修改任何真实库存。
// 运行：php tests/nfc-code/platform-scope.php [vendor/autoload.php]，需要 pdo_sqlite。
require __DIR__ . '/tenant-assignment.php';

$allCount = NfcCode::count();
$unassignedCount = NfcCode::whereNull('tenant_id')->count();

// 复现真实 HTTP 链路：平台请求携带 tenant_id 时，公共中间件会填写 tenantId。
// source 仍为 PLATFORM，tenantId 此时是分配目标，不应限制平台库存查询和更新。
request()->source = AdminTerminalEnum::PLATFORM;
request()->tenantId = 4;
request()->tenantResolveMode = 'param_id';
same(['quantity' => 2], NfcCodeService::assignTenant(7, [
    'ids' => [1000, 999], 'tenant_id' => 4,
]), 'Platform assignment must work with resolved target tenant context');
same($allCount, NfcCodeService::adminLists([])['count'], 'Platform still sees every tenant and unassigned stock');
same($unassignedCount - 2, NfcCodeService::adminLists(['status' => 'unassigned'])['count'], 'Platform can query remaining unassigned stock');
same(2, NfcCode::whereIn('id', [999, 1000])->where('tenant_id', 4)->count(), 'Requested codes are assigned to target tenant');
same(2, NfcCode::where('tenant_id', 1)->count(), 'Platform can still see another tenant stock');
same(4, (int)request()->tenantId, 'Model queries must not rewrite the shared request context');

// 仅平台端跨租户；租户端和用户端继续执行 BaseModel 原有的当前租户过滤。
foreach ([AdminTerminalEnum::TENANT, AdminTerminalEnum::USER] as $source) {
    request()->source = $source;
    same(2, NfcCode::count(), 'Non-platform queries remain scoped to current tenant');
    same(0, NfcCode::where('tenant_id', 1)->count(), 'Non-platform queries cannot read another tenant stock');
    same(0, NfcCode::whereNull('tenant_id')->count(), 'Non-platform queries cannot read platform stock');
}

// 即使目标租户采用分表策略，平台也应操作统一库存，不追加目标租户的分表后缀。
request()->source = AdminTerminalEnum::PLATFORM;
Tenant::where('id', 4)->update(['tactics' => 1]);
same($allCount, NfcCodeService::adminLists([])['count'], 'Split-table target does not redirect platform stock queries');
$generated = NfcCodeService::generate(7, 1);
same(1, NfcCode::where('batch_no', $generated['batch_no'])->whereNull('tenant_id')->count(), 'Generation remains platform stock with target tenant context');

echo "PASS NFC platform context assignment, cross-tenant inventory and non-platform tenant isolation\n";
