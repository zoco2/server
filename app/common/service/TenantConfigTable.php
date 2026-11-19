<?php

namespace app\common\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class TenantConfigTable
{
    /** 通过租户 ID 选择配置表，后台任务也可使用。写入时可锁定租户行。 */
    public static function forTenant(int $tenantId, bool $lock = false): string
    {
        $tenant = Db::name('tenant')->where('id', $tenantId)->whereNull('delete_time')->lock($lock)->find();
        if ($tenantId <= 0 || !$tenant) {
            throw new InvalidArgumentException('租户不存在');
        }
        if ((int)($tenant['tactics'] ?? 0) !== 1) {
            return 'tenant_config';
        }
        $sn = (string)($tenant['sn'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $sn)) {
            throw new RuntimeException('租户配置分表标识无效');
        }
        return 'tenant_config_' . $sn;
    }
}
