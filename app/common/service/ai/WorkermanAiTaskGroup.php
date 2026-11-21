<?php

namespace app\common\service\ai;

use RuntimeException;
use Workerman\Timer;
use Workerman\Worker;

final class WorkermanAiTaskGroup
{
    public static function register(string $siteRoot, string $action, bool $daemon = false): void
    {
        $script = rtrim($siteRoot, '/') . '/scripts/start-workerman-ai-task-group.sh';
        if (in_array($action, ['start', 'restart'], true)
            && (PHP_OS_FAMILY !== 'Linux' || !is_readable($script) || !function_exists('pcntl_exec'))) {
            throw new RuntimeException('Workerman 托管 AI 队列需要 Linux、pcntl_exec 和可读的 start-workerman-ai-task-group.sh');
        }
        if (in_array($action, ['stop', 'restart', 'reload'], true)) {
            // Workerman 5 accepts a single CLI mode. Use graceful stop and
            // separately preserve daemon mode when restarting a daemon.
            global $argv;
            $argv = [$argv[0], $action, '-g'];
            if ($action === 'restart') {
                Worker::$daemonize = $daemon;
            }
        }
        $worker = new Worker();
        $worker->name = 'ai-task-group';
        $worker->count = 1;
        $worker->onWorkerStart = static function (Worker $worker) use ($script): void {
            $owner = posix_getpid();
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('无法启动 AI 队列管理脚本');
            }
            if ($pid === 0) {
                putenv('AI_TASK_WORKER_PARENT_PID=' . $owner);
                putenv('PHP_BIN=' . (getenv('PHP_BIN') ?: PHP_BINARY));
                pcntl_exec('/bin/sh', [$script]);
                exit(1);
            }

            $worker->onWorkerStop = static function () use ($pid): void {
                posix_kill($pid, SIGTERM);
                do {
                    $result = pcntl_waitpid($pid, $status);
                } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);
            };

            Timer::add(2, static function () use ($worker, $pid): void {
                if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                    $worker->onWorkerStop = null;
                    Worker::stopAll(1, 'AI 队列管理脚本已退出，由 Workerman 重新启动');
                }
            });
        };
    }
}
