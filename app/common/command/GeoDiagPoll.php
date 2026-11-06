<?php

namespace app\common\command;

use app\common\service\geo\GeoDiagnosisService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * GEO 诊断状态轮询。
 */
class GeoDiagPoll extends Command
{
    protected function configure(): void
    {
        $this->setName('geo:diag-poll')
            ->setDescription('同步GEO品牌诊断进行中的任务')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '单轮最多处理诊断批次数', 50)
            ->addOption('no-progress', null, Option::VALUE_NONE, '静默运行，不输出进度');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, (int)$input->getOption('limit'));
        $quiet = (bool)$input->getOption('no-progress');
        if (!$quiet) {
            $output->writeln('<info>开始同步GEO诊断...</info>');
        }
        try {
            $stat = GeoDiagnosisService::syncDue($limit);
            if (!$quiet) {
                $output->writeln(sprintf('<info>同步完成：检查 %d 个，完成/变化 %d 个，失败 %d 个</info>', $stat['checked'], $stat['completed'], $stat['failed']));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->error('同步失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
