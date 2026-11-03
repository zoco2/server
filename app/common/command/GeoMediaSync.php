<?php

namespace app\common\command;

use app\common\service\geo\GeoMediaSyncService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 海讯社媒体资源同步。
 *
 * 用法：
 *   php think geo:media-sync                  # 所有租户，全部 15 类
 *   php think geo:media-sync --tenant=1 --type=1
 *   php think geo:media-sync --tenant=1 --type=1,2 --state  # 顺带校正状态
 */
class GeoMediaSync extends Command
{
    protected function configure()
    {
        $this->setName('geo:media-sync')
            ->addOption('tenant', 't', Option::VALUE_REQUIRED, '租户ID，不传则遍历已配置海讯账号的租户')
            ->addOption('type', null, Option::VALUE_REQUIRED, '资源类型，多个用逗号分隔，默认全部')
            ->addOption('state', null, Option::VALUE_NONE, '额外用 stateON 校正本次资源的上下架状态')
            ->setDescription('同步海讯社媒体资源到本地 la_geo_media');
    }

    protected function execute(Input $input, Output $output)
    {
        $tenantId = (int)$input->getOption('tenant');
        $types = $this->parseTypes((string)$input->getOption('type'));
        $withState = (bool)$input->getOption('state');

        $tenantIds = $tenantId > 0 ? [$tenantId] : $this->configuredTenants();
        if ($tenantIds === []) {
            $output->writeln('<comment>没有已配置海讯社账号的租户</comment>');
            return 0;
        }

        foreach ($tenantIds as $id) {
            $output->writeln(sprintf('<info>租户 %d 开始同步</info>', $id));
            try {
                $result = GeoMediaSyncService::syncAll($id, $types);
            } catch (\Throwable $e) {
                $output->writeln('<error>同步失败：' . $e->getMessage() . '</error>');
                continue;
            }
            foreach ($result as $type => $row) {
                if (isset($row['error'])) {
                    $output->writeln(sprintf('  类型 %d 失败：%s', $type, $row['error']));
                    continue;
                }
                $output->writeln(sprintf(
                    '  类型 %d：海讯 %d 条，新增 %d，更新 %d，标记下线 %d',
                    $type,
                    $row['total'],
                    $row['created'],
                    $row['updated'],
                    $row['gone']
                ));
            }
            if ($withState) {
                $ids = (array)\app\common\model\geo\GeoMedia::where(['tenant_id' => $id])
                    ->where('sync_status', '<>', 'gone')
                    ->order('last_sync_time', 'desc')
                    ->limit(200)
                    ->column('sourceid');
                $state = GeoMediaSyncService::refreshStates($id, $ids);
                $output->writeln(sprintf('  状态校正：检查 %d，下架 %d，已删除 %d', $state['checked'], $state['down'], $state['removed']));
            }
        }
        return 0;
    }

    /**
     * @param string $value 逗号分隔
     * @return array<int>
     */
    private function parseTypes(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $value)), static fn(int $type): bool => isset(GeoMediaSyncService::RESOURCE_TYPES[$type])));
    }

    /**
     * 已配置 geo_manuscript.account 的租户。租户配置表按 tenant_id 存一行，
     * 这里直接查 la_tenant_config 里 name=account 且 type=geo_manuscript 的记录。
     *
     * @return array<int>
     */
    private function configuredTenants(): array
    {
        $ids = \think\facade\Db::name('tenant_config')
            ->where(['type' => 'geo_manuscript', 'name' => 'account'])
            ->whereLike('value', '%"token"%')
            ->column('tenant_id');
        return array_values(array_filter(array_map('intval', $ids)));
    }
}