<?php

namespace app\common\command;

use app\common\service\geo\GeoOrderService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * GEO 投稿单状态同步定时任务。
 *
 * 用法：
 *   php think geo:order-sync
 *   php think geo:order-sync --limit=50
 *
 * 建议 cron：每 5~10 分钟一次
 *   */5 * * * * cd /path/to/project && php think geo:order-sync >> storage/logs/geo-order-sync.log 2>&1
 */
class GeoOrderSync extends Command
{
    protected function configure(): void
    {
        $this->setName('geo:order-sync')
            ->setDescription('同步海讯社投稿单状态（推送待推送订单 + 轮询在途订单）')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '单轮最多处理订单数', 100)
            ->addOption('no-progress', null, Option::VALUE_NONE, '静默运行，不输出进度');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, (int)$input->getOption('limit'));
        $quiet = (bool)$input->getOption('no-progress');

        if (!$quiet) {
            $output->writeln('<info>开始同步投稿单状态...</info>');
        }

        try {
            $stat = GeoOrderService::syncDue($limit);
            if (!$quiet) {
                $output->writeln(sprintf(
                    '<info>同步完成：推送 %d 单，查询 %d 单，状态变化 %d 单，失败 %d 单</info>',
                    $stat['pushed'],
                    $stat['queried'],
                    $stat['changed'],
                    $stat['failed']
                ));
            }
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->error('同步失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
