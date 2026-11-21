<?php

namespace app\common\service\pricing;

use app\common\enum\AdminTerminalEnum;
use think\facade\Db;

/** Poll the same business and wallet tables that the tenant's HTTP requests use. */
final class BillingTenantScope
{
    public static function each(string $businessTable, callable $run): void
    {
        $tenants = Db::name('tenant')->whereNull('delete_time')->order('id')->field('id,sn,tactics')->select();
        $tables = array_fill_keys(Db::connect()->getTables(), true);
        $business = Db::name($businessTable)->getTable();
        $wallet = Db::name('user')->getTable();
        $ledger = Db::name('user_account_log')->getTable();
        $request = request();
        $previous = [$request->tenantId ?? null, $request->tenantSn ?? null, $request->source ?? null, $request->tenantResolveMode ?? null];
        $error = null;
        foreach ($tenants as $tenant) {
            $split = (int)$tenant['tactics'] === 1;
            if ($split && !preg_match('/^[a-zA-Z0-9_]+$/D', (string)$tenant['sn'])) {
                throw new \RuntimeException('租户分表标识无效');
            }
            $suffix = $split ? '_' . $tenant['sn'] : '';
            if (!isset($tables[$business . $suffix])) {
                continue;
            }
            try {
                if (!isset($tables[$wallet . $suffix], $tables[$ledger . $suffix])) {
                    throw new \RuntimeException('租户 ' . $tenant['id'] . ' 缺少算力账户或流水表');
                }
                $request->tenantId = (int)$tenant['id'];
                $request->tenantSn = (string)$tenant['sn'];
                $request->source = AdminTerminalEnum::USER;
                $request->tenantResolveMode = 'console';
                $run((int)$tenant['id']);
            } catch (\Throwable $e) {
                $error ??= $e;
            } finally {
                [$request->tenantId, $request->tenantSn, $request->source, $request->tenantResolveMode] = $previous;
            }
        }
        if ($error !== null) {
            throw $error;
        }
    }
}
