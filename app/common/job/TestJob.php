<?php

namespace app\common\job;

use think\facade\Log;

/**
 * 示例 Job：用于验证消息队列链路是否打通。
 *
 * 可随时删除，不影响基础设施。
 */
class TestJob extends Job
{
    /**
     * 执行任务。
     */
    public function handle()
    {
        $dataText = json_encode($this->data, JSON_UNESCAPED_UNICODE);
        Log::channel('mns')->info(sprintf(
            '[job] test handled id=%s tenant=%s data=%s',
            $this->id,
            (string)($this->tenantId ?? ''),
            $dataText === false ? '[]' : $dataText
        ));
    }

    /**
     * 最终失败回调。
     */
    public function failed()
    {
        Log::channel('mns')->error(sprintf(
            '[job] test failed id=%s tenant=%s data=%s',
            $this->id,
            (string)($this->tenantId ?? ''),
            json_encode($this->data, JSON_UNESCAPED_UNICODE) ?: '[]'
        ));
    }
}
