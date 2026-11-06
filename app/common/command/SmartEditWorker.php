<?php

namespace app\common\command;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 智能剪辑轮询：查询 IMS 作业状态并把成片落地。
 *
 * 用法：
 *   php think smart-edit:worker              守护进程，持续轮询
 *   php think smart-edit:worker --once       跑一轮就退出（调试 / cron）
 */
class SmartEditWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('smart-edit:worker')
            ->setDescription('轮询阿里云云剪辑作业，写入成片结果')
            ->addOption('once', null, Option::VALUE_NONE, '只执行一轮')
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, '空轮询休眠秒数', 3)
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '单轮处理数量', 50);
    }

    protected function execute(Input $input, Output $output): int
    {
        $once = (bool)$input->getOption('once');
        $sleep = max(1, (int)$input->getOption('sleep'));
        $batch = max(1, min(200, (int)$input->getOption('batch')));
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$running): void { $running = false; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $output->writeln('smart-edit worker started');
        while ($running) {
            $tasks = SmartEditTask::whereIn('status', SmartEditTask::RUNNING_STATUSES)
                ->where('provider_job_id', '<>', '')
                ->where('next_poll_time', '<=', time())
                ->order('next_poll_time', 'asc')
                ->limit($batch)
                ->select();
            $count = 0;
            foreach ($tasks as $task) {
                try {
                    SmartEditService::refresh((int)$task['id']);
                    $count++;
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('[%d] error: %s', (int)$task['id'], $e->getMessage()));
                }
            }
            if ($count > 0) {
                $output->writeln(sprintf('polled %d task(s)', $count));
            }
            if ($once) {
                break;
            }
            if ($count === 0) {
                sleep($sleep);
            }
        }
        return 0;
    }
}