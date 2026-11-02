<?php

namespace app\common\command;

use app\common\model\app\TenantApp;
use app\common\service\app\AppMenuService;
use app\common\service\app\AppRegistryService;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 按应用手动同步平台菜单或租户菜单。
 */
class SyncAppMenus extends Command
{
    protected function configure(): void
    {
        $this->setName('zoco:sync-menus')
            ->setDescription('按应用手动同步平台或租户菜单')
            ->addOption('app', null, Option::VALUE_REQUIRED, '应用标识；不传则同步全部有对应菜单文件的应用')
            ->addOption('side', null, Option::VALUE_REQUIRED, 'tenant、platform 或 all')
            ->addOption('tenant', null, Option::VALUE_REQUIRED, '租户 ID；仅同步租户菜单时有效，不传则同步已开通该应用的全部租户');
    }

    protected function execute(Input $input, Output $output): int
    {
        $appCode = trim((string)($input->getOption('app') ?? ''));
        $side = strtolower(trim((string)($input->getOption('side') ?? '')));
        $tenantOption = $input->getOption('tenant');
        if (!in_array($side, ['tenant', 'platform', 'all'], true)) {
            $output->error('side 必须是 tenant、platform 或 all');
            return 1;
        }
        if ($tenantOption !== null && !preg_match('/^[1-9]\d*$/', (string)$tenantOption)) {
            $output->error('tenant 必须为正整数');
            return 1;
        }
        $tenantId = (int)$tenantOption;
        if ($side === 'platform' && $tenantId > 0) {
            $output->error('平台菜单同步不使用 tenant');
            return 1;
        }

        try {
            $appCodes = $this->resolveAppCodes($appCode);
        } catch (RuntimeException $e) {
            $output->error($e->getMessage());
            return 1;
        }

        $failed = false;
        foreach ($appCodes as $code) {
            if ($side === 'platform' || $side === 'all') {
                $failed = $this->syncPlatform($code, $appCode !== '', $output) || $failed;
            }
            if ($side === 'tenant' || $side === 'all') {
                $failed = $this->syncTenant($code, $tenantId, $appCode !== '', $output) || $failed;
            }
        }
        if ($appCodes === []) {
            $output->writeln('没有可同步的应用');
        }
        return $failed ? 1 : 0;
    }

    /**
     * 解析要同步的应用标识。
     *
     * @return string[]
     */
    private function resolveAppCodes(string $appCode): array
    {
        if ($appCode !== '') {
            AppRegistryService::assertValidCode($appCode);
            return [$appCode];
        }

        $root = rtrim(base_path(), '/\\') . DIRECTORY_SEPARATOR . 'apps';
        if (!is_dir($root)) {
            return [];
        }
        $codes = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . DIRECTORY_SEPARATOR . $name)) {
                continue;
            }
            try {
                AppRegistryService::assertValidCode($name);
            } catch (RuntimeException) {
                continue;
            }
            $codes[] = $name;
        }
        sort($codes);
        return $codes;
    }

    /**
     * 同步一个应用的平台菜单。
     */
    private function syncPlatform(string $appCode, bool $explicit, Output $output): bool
    {
        $path = $this->menuPath($appCode, 'platform.json');
        if (!is_file($path)) {
            if ($explicit) {
                $output->error($appCode . ' 没有 platform.json');
                return true;
            }
            return false;
        }
        AppMenuService::syncPlatformMenus($appCode);
        $output->writeln($appCode . ' 平台菜单已同步');
        return false;
    }

    /**
     * 同步一个应用的租户菜单。
     */
    private function syncTenant(string $appCode, int $tenantId, bool $explicit, Output $output): bool
    {
        $path = $this->menuPath($appCode, 'tenant.json');
        if (!is_file($path)) {
            if ($explicit) {
                $output->error($appCode . ' 没有 tenant.json');
                return true;
            }
            return false;
        }
        $tenantIds = $tenantId > 0
            ? [$tenantId]
            : array_values(array_unique(array_map('intval', TenantApp::where('app_code', $appCode)->column('tenant_id'))));
        if ($tenantIds === []) {
            $output->writeln($appCode . ' 没有已开通的租户');
            return false;
        }
        foreach ($tenantIds as $id) {
            AppMenuService::syncTenantMenus($id, $appCode);
            $output->writeln($appCode . ' 租户 ' . $id . ' 菜单已同步');
        }
        return false;
    }

    /**
     * 应用菜单文件路径。
     */
    private function menuPath(string $appCode, string $filename): string
    {
        return rtrim(base_path(), '/\\')
            . DIRECTORY_SEPARATOR . 'apps'
            . DIRECTORY_SEPARATOR . $appCode
            . DIRECTORY_SEPARATOR . 'menus'
            . DIRECTORY_SEPARATOR . $filename;
    }
}
