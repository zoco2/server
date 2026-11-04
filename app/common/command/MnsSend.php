<?php

namespace app\common\command;

use app\common\job\TestJob;
use app\common\service\mq\LightMqService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 投递消息到 MNS 队列（测试用）。
 *
 * 示例：
 *   php think mns:send --tenant=1 --body="hello"
 *   php think mns:send --tenant=1 --queue=test --count=3 --delay=10
 */
class MnsSend extends Command
{
    protected function configure(): void
    {
        $this->setName('mns:send')
            ->setDescription('投递测试消息到 MNS 队列')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, '队列名，默认取 mns.default_queue', '')
            ->addOption('tenant', null, Option::VALUE_OPTIONAL, '租户 ID（必填）', 0)
            ->addOption('body', null, Option::VALUE_OPTIONAL, '消息内容', 'test job message')
            ->addOption('count', null, Option::VALUE_OPTIONAL, '投递条数', 1)
            ->addOption('delay', null, Option::VALUE_OPTIONAL, '延迟秒数', 0);
    }

    protected function execute(Input $input, Output $output): int
    {
        $queue = trim((string)$input->getOption('queue'));
        if ($queue === '') {
            $queue = (string)config('mns.default_queue', 'default');
        }
        $tenantId = (int)$input->getOption('tenant');
        $body = trim((string)$input->getOption('body'));
        $count = max(1, (int)$input->getOption('count'));
        $delay = max(0, (int)$input->getOption('delay'));

        if ($tenantId <= 0) {
            $output->writeln('<error>请传 --tenant=租户ID，否则消费端无法还原租户上下文</error>');
            return 1;
        }

        try {
            for ($i = 1; $i <= $count; $i++) {
                $message = $count > 1 ? $body . ' #' . $i : $body;
                $job = new TestJob([
                    'message' => $message,
                    'sent_at' => time(),
                ]);
                $result = (new LightMqService())->dispatchJob(
                    $queue,
                    $job,
                    $delay
                );
                $output->writeln(sprintf(
                    '[%d/%d] dispatched queue=%s job_id=%s message_id=%s message="%s"',
                    $i,
                    $count,
                    (string)$result['queue'],
                    (string)$result['job_id'],
                    (string)$result['message_id'],
                    $message
                ));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        return 0;
    }
}
