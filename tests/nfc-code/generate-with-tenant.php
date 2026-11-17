<?php

declare(strict_types=1);

use app\common\model\merchant\NfcCode;
use app\common\service\merchant\NfcCodeService;

// 复用平台上下文、有效/停用/软删除租户和内存库存，顺带回归此前的分配问题。
// 运行：php tests/nfc-code/generate-with-tenant.php [vendor/autoload.php]，需要 pdo_sqlite。
require __DIR__ . '/platform-scope.php';

// 此时仍带目标租户上下文，省略租户、显式 NULL 和清空选择都必须保持平台库存。
foreach ([null, ''] as $emptyTenant) {
    $result = NfcCodeService::generate(7, 1, $emptyTenant);
    same(null, $result['tenant_id'], 'Empty optional tenant leaves platform stock');
    $row = NfcCode::where('batch_no', $result['batch_no'])->findOrEmpty()->getData();
    same(null, $row['tenant_id'], 'No tenant ownership is inferred from request context');
    same(null, $row['tenant_assigned_admin_id'], 'Unassigned batch has no assignment administrator');
    same(null, $row['tenant_assigned_at'], 'Unassigned batch has no assignment timestamp');
}

// 有效租户可在生成时直接接收整批码牌，写入归属及分配审计，仍未绑定门店。
$assignedBatch = NfcCodeService::generate(8, 3, '4');
same(4, $assignedBatch['tenant_id'], 'Response reports actual target tenant');
$rows = NfcCode::where('batch_no', $assignedBatch['batch_no'])->select()->toArray();
same(3, count($rows), 'All generated rows are persisted');
foreach ($rows as $row) {
    same(4, (int)$row['tenant_id'], 'Generated code belongs to selected tenant');
    same(8, (int)$row['created_admin_id'], 'Creating administrator is retained');
    same(8, (int)$row['tenant_assigned_admin_id'], 'Assignment administrator is retained');
    same(true, (int)$row['tenant_assigned_at'] > 0, 'Assignment time is recorded');
    same(null, $row['store_id'], 'Generation does not activate a store');
}
same(1, count(array_unique(array_column($rows, 'tenant_assigned_at'))), 'One batch shares one assignment time');

// 非法、停用、软删除或不存在的目标租户不能留下任何新库存。
foreach ([0, -1, false, 1.5, [], 'bad', 1, 2, 3, 999999] as $invalidTenant) {
    $before = NfcCode::count();
    rejected(fn () => NfcCodeService::generate(7, 2, $invalidTenant), 'Invalid target tenant accepted');
    same($before, NfcCode::count(), 'Invalid tenant must not leave newly generated rows');
}

// 已有触发器在第 251 条插入时失败；选择租户后也必须回滚整批及其分配审计。
$before = NfcCode::count();
try {
    NfcCodeService::generate(99, 251, 4);
    throw new RuntimeException('Simulated chunk failure was ignored');
} catch (think\db\exception\PDOException $error) {
    same(true, str_contains($error->getMessage(), 'simulated write failure'), 'Expected simulated write failure');
}
same($before, NfcCode::count(), 'Generation and assignment roll back together');
same(0, NfcCode::where('tenant_assigned_admin_id', 99)->count(), 'No partial assignment audit remains');

echo "PASS NFC optional generation tenant, direct assignment validation, audit and atomic rollback\n";
