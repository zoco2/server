<?php

namespace app\common\job;

use app\common\model\smart_edit\SmartEditGroup;
use app\common\service\smart_edit\SmartEditDubbingService;
use think\facade\Log;

/**
 * 智能剪辑镜头组：生成 TTS 口播，完成后转入展开阶段。
 *
 * 配音是整组共用的（一个音色一套口播），必须在展开成成百上千个任务之前做完，
 * 否则每条成片都会各自去合成一遍，成本和耗时都不可控。
 *
 * 没有选配音演员时直接跳过这一步，保持旧流程。
 */
class SmartEditDubbingJob extends Job
{
    /** 配音最多尝试 2 次（首次 + 一次重投） */
    public const MAX_ATTEMPTS = 2;

    /** 重投间隔（秒） */
    private const RETRY_DELAY = 30;

    /** 逐句合成是串行 HTTP，文案多时耗时较长 */
    protected int $timeout = 900;

    public function handle()
    {
        $batchNo = trim((string)($this->data['batch_no'] ?? ''));
        if ($batchNo === '') {
            throw new \RuntimeException('SmartEditDubbingJob 缺少 batch_no');
        }
        $tenantId = (int)($this->tenantId ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('SmartEditDubbingJob 缺少租户 ID');
        }
        $attempt = (int)($this->data['attempt'] ?? 0);

        $group = SmartEditGroup::where('tenant_id', $tenantId)->where('batch_no', $batchNo)->findOrEmpty();
        if ($group->isEmpty()) {
            Log::channel('mns')->warning('[smart_edit] dubbing skipped, group not found batch=' . $batchNo);
            return;
        }
        // 用户可能在排队期间删了组；已进入展开的组不重复配音
        if ((string)$group['status'] !== SmartEditGroup::STATUS_DUBBING) {
            Log::channel('mns')->info(sprintf(
                '[smart_edit] dubbing skipped batch=%s status=%s',
                $batchNo,
                (string)$group['status']
            ));
            return;
        }

        $shared = (array)$group['config_json'];
        $voiceKeys = (array)($shared['voices'] ?? []);
        $subtitles = (array)($shared['subtitle_groups'][0] ?? []);
        $gap = isset($shared['subtitle_gap']) && is_numeric($shared['subtitle_gap'])
            ? (float)$shared['subtitle_gap']
            : SmartEditDubbingService::DEFAULT_GAP;

        Log::channel('mns')->info(sprintf(
            '[smart_edit] dubbing start batch=%s voices=%d subtitles=%d attempt=%d',
            $batchNo,
            count($voiceKeys),
            count($subtitles),
            $attempt
        ));

        try {
            SmartEditDubbingService::generate(
                $tenantId,
                (int)$group['user_id'],
                $batchNo,
                $subtitles,
                $voiceKeys,
                $gap
            );
        } catch (\Throwable $e) {
            Log::channel('mns')->error(sprintf(
                '[smart_edit] dubbing failed batch=%s attempt=%d %s',
                $batchNo,
                $attempt,
                $e->getMessage()
            ));
            $this->retryOrFail($group, $attempt, $tenantId, $e);
            return;
        }

        // 配音完成，转入展开
        $group->save([
            'status' => SmartEditGroup::STATUS_EXPANDING,
            'error_code' => '',
            'error_message' => '',
            'update_time' => time(),
        ]);
        SmartEditGroupExpandJob::dispatch(['batch_no' => $batchNo], 0, '', $tenantId);

        Log::channel('mns')->info('[smart_edit] dubbing done, expand dispatched batch=' . $batchNo);
    }

    /**
     * 失败后按次数重投，用尽才把组置失败。
     */
    private function retryOrFail(SmartEditGroup $group, int $attempt, int $tenantId, \Throwable $e): void
    {
        $nextAttempt = $attempt + 1;
        if ($nextAttempt < self::MAX_ATTEMPTS) {
            try {
                self::dispatch(
                    ['batch_no' => (string)$group['batch_no'], 'attempt' => $nextAttempt],
                    self::RETRY_DELAY,
                    '',
                    $tenantId
                );
                return;
            } catch (\Throwable $dispatchError) {
                Log::channel('mns')->error(
                    '[smart_edit] dubbing retry dispatch failed batch=' . $group['batch_no']
                    . ' ' . $dispatchError->getMessage()
                );
            }
        }

        $group->save([
            'status' => SmartEditGroup::STATUS_FAILED,
            'error_code' => 'DUBBING_FAILED',
            'error_message' => mb_substr('配音生成失败：' . $e->getMessage(), 0, 480),
            'update_time' => time(),
        ]);
    }
}
