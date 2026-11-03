<?php

namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 启动 Workerman 服务
 *
 * 移植自 kkvoice_server 的 app/worker/Start.php，只保留 Hello World，不含业务代码。
 */
class Workerman extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('workerman')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status|connections', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'daemon method run --daemon -d')
            ->setDescription('Workerman Service');
    }

    /**
     * 执行命令
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        // 配置 Workerman 日志位置
        $logDir = app()->getRootPath() . 'runtime/log/workerman/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // 设置 Workerman 日志文件路径
        Worker::$logFile = $logDir . 'workerman.log';
        Worker::$stdoutFile = $logDir . 'stdout.log';
        Worker::$pidFile = $logDir . 'workerman.pid';

        $worker = new Worker('http://0.0.0.0:2346');
        $worker->name = 'zoco-hello';
        $worker->count = 1;
        $worker->onWorkerStart = function () {
            echo "[workerman] hello world " . date('Y-m-d H:i:s') . PHP_EOL;
            Timer::add(10, function () {
                echo "[workerman] hello world " . date('Y-m-d H:i:s') . PHP_EOL;
            });
        };
        $worker->onMessage = function ($connection, $request) {
            $connection->send('hello world');
        };

        Worker::runAll();
        return 0;
    }
}
