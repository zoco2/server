<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use app\common\enum\AdminTerminalEnum;
use app\common\model\geo\GeoDiagBatch;
use app\common\model\geo\GeoOrder;
use app\common\service\geo\GeoArticleGenerationService;
use app\common\service\geo\GeoArticleService;
use app\common\service\geo\GeoDiagnosisService;
use app\common\service\geo\GeoOrderService;
use Exception;
use InvalidArgumentException;
use think\facade\Db;
use think\facade\Log;
use Throwable;

final class GeoRankingTaskJob
{
    public const TYPE = 'geo_ranking';

    private const DETECT = 'start_detection';
    private const WAIT_DETECTION = 'waiting_detection';
    private const GENERATE = 'start_generation';
    private const WAIT_GENERATION = 'waiting_generation';
    private const SUBMIT = 'submitting';

    public static function start(int $runId, ?int $expiresAt): void
    {
        Db::transaction(static function () use ($runId, $expiresAt): void {
            $query = Db::name(UserTaskRunService::TABLE)
                ->where(['id' => $runId, 'status' => UserTaskRunService::QUEUED]);
            $now = time();
            if ($query->update(['status' => UserTaskRunService::RUNNING, 'started_at' => $now]) !== 1) {
                return;
            }
            $query = Db::name(UserTaskRunService::TABLE)->where('id', $runId);
            if ($expiresAt !== null && time() >= $expiresAt) {
                $query->update(['status' => 'expired', 'reason' => '计划有效期已结束', 'started_at' => null, 'finished_at' => time()]);
                return;
            }
            $query->update(['workflow_step' => self::DETECT, 'next_step_at' => $now]);
            UserTaskRunProgress::record($runId, 'job', 'running', '开始执行 GEO 计划任务');
        });
    }

    public static function tick(): void
    {
        $now = time();
        $scope = ['status' => UserTaskRunService::RUNNING, 'job_type' => self::TYPE];
        $runs = Db::name(UserTaskRunService::TABLE)->where($scope)->where('next_step_at', '<=', $now)
            ->order(['next_step_at' => 'asc', 'id' => 'asc'])->limit(10)->select()->toArray();

        foreach ($runs as $run) {
            // 清除到期时间即领取当前阶段。只有等到下一阶段明确落库后才会再次执行。
            $claimed = Db::name(UserTaskRunService::TABLE)
                ->where($scope + ['id' => $run['id'], 'workflow_step' => $run['workflow_step']])
                ->where('next_step_at', (int)$run['next_step_at'])
                ->update(['next_step_at' => null]);
            if ($claimed === 1) {
                self::advance($run);
            }
        }
    }

    private static function advance(array $run): void
    {
        $id = (int)$run['id'];
        $tenantId = (int)$run['tenant_id'];
        $userId = (int)$run['user_id'];
        $request = request();
        $previousTenant = $request->tenantId ?? null;
        $previousSource = $request->source ?? null;
        // 与队列消费者使用相同的租户上下文，GEO 模型按租户路由查询和写入。
        $request->tenantId = $tenantId;
        $request->source = AdminTerminalEnum::USER;
        $data = [];
        try {
            $config = json_decode($run['job_config'], true, flags: JSON_THROW_ON_ERROR);
            $data = json_decode($run['workflow_data'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            match ($run['workflow_step']) {
                self::DETECT => self::startDetection($id, $userId, $config),
                self::WAIT_DETECTION => self::pollDetection($id, $userId, $config, $data),
                self::GENERATE => self::startGeneration($id, $tenantId, $userId, $config, $data),
                self::WAIT_GENERATION => self::pollGeneration($id, $tenantId, $userId, $config, $data),
                self::SUBMIT => self::submitMedia($id, $userId, $config, $data),
            };
        } catch (Throwable $e) {
            Log::error(sprintf('[user-task-geo] run=%d step=%s error=%s', $id, $run['workflow_step'], $e->getMessage()));
            $reason = match ($run['workflow_step']) {
                self::DETECT => 'GEO 检测提交失败',
                self::WAIT_DETECTION => 'GEO 检测结果处理失败',
                self::GENERATE => '写稿任务提交失败',
                self::WAIT_GENERATION => '稿件生成或准备失败',
                self::SUBMIT => '自动投稿处理失败',
                default => 'GEO 任务执行失败',
            };
            if ($e instanceof InvalidArgumentException) {
                $reason .= '。' . mb_substr($e->getMessage(), 0, 160);
            }
            self::finish($id, $data, $run['workflow_step'], $reason, false);
        } finally {
            $request->tenantId = $previousTenant;
            $request->source = $previousSource;
        }
    }

    private static function startDetection(int $id, int $userId, array $config): void
    {
        UserTaskRunProgress::record($id, 'detection', 'running', '正在提交 GEO 检测', [
            'brand_id' => $config['brand_id'],
            'question' => $config['question'],
            'platforms' => $config['platforms'],
            'planned_samples' => count($config['platforms']) * $config['repeat_count'],
        ]);
        $batch = GeoDiagnosisService::create($userId, [
            'brand_id' => $config['brand_id'],
            'questions' => [$config['question']],
            'platforms' => $config['platforms'],
            'repeat_count' => $config['repeat_count'],
        ], $id);
        $data = ['diagnosis_id' => (int)$batch['id']];
        self::move($id, self::WAIT_DETECTION, $data, (int)$batch['next_poll_time'],
            ['detection', 'waiting', '检测批次已提交，等待采集结果', $data]);
    }

    private static function pollDetection(int $id, int $userId, array $config, array $data): void
    {
        try {
            $batch = GeoDiagnosisService::syncOne($data['diagnosis_id'], $userId, $id);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Exception $e) {
            // 这里只重试读取既有批次的结果，同一轮始终只有一次检测提交。
            $event = null;
            if (empty($data['poll_error'])) {
                Log::warning(sprintf('[user-task-geo] run=%d polling detection: %s', $id, $e->getMessage()));
                $event = ['detection', 'waiting', '暂时无法读取检测结果，等待下次查询', ['diagnosis_id' => $data['diagnosis_id']]];
            }
            $data['poll_error'] = true;
            self::move($id, self::WAIT_DETECTION, $data, time() + 15, $event);
            return;
        }
        $progress = [
            'diagnosis_id' => $data['diagnosis_id'],
            'planned_samples' => (int)$batch['unit_count'],
            'completed_samples' => (int)$batch['completed_count'],
            'failed_samples' => (int)$batch['failed_count'],
            'detection_status' => (string)$batch['status'],
        ];
        if (empty($batch['terminal']) && !in_array($batch['status'], GeoDiagBatch::TERMINAL_STATUS, true)) {
            $changed = $progress !== ($data['detection_progress'] ?? null) || !empty($data['poll_error']);
            $data['detection_progress'] = $progress;
            unset($data['poll_error']);
            self::move($id, self::WAIT_DETECTION, $data, max(time() + 5, (int)$batch['next_poll_time']),
                $changed ? ['detection', 'running', 'GEO 检测采集中', $progress] : null);
            return;
        }

        $summary = $batch['summary'] ?? [];
        $planned = count($config['platforms']) * $config['repeat_count'];
        if ($batch['status'] !== GeoDiagBatch::STATUS_COMPLETED || (int)($summary['completed'] ?? 0) !== $planned) {
            self::finish($id, $data, 'detection', 'GEO 检测未取得完整有效样本', false, $progress);
            return;
        }
        // 用完成样本的精确比例比较阈值。显示时才四舍五入，达到阈值即结束。
        $mentioned = (int)$summary['mentioned'];
        $target = $config['target_mention_rate'];
        $belowTarget = $mentioned * 100 < $target * $planned;
        $result = $progress + [
            'mentioned_samples' => $mentioned,
            'actual_mention_rate' => round($mentioned * 100 / $planned, 2),
            'target_mention_rate' => $target,
        ];
        if (!$belowTarget) {
            self::finish($id, $data, 'detection', '提及率已达标，本轮执行完成', true, $result);
            return;
        }
        self::move($id, self::GENERATE, $data, time(),
            ['detection', 'success', '检测完成，提及率低于目标，进入写稿', $result]);
    }

    private static function startGeneration(int $id, int $tenantId, int $userId, array $config, array $data): void
    {
        UserTaskRunProgress::record($id, 'generation', 'running', '正在提交品牌知识库写稿');
        $prompt = "请围绕以下 GEO 问题，基于所选品牌的知识库写一篇可投稿的文章。\n" . $config['question'];
        $result = GeoArticleGenerationService::submit($tenantId, $userId, $config['brand_id'], $prompt);
        $data['generation_id'] = $result['requestId'];
        self::move($id, self::WAIT_GENERATION, $data, time() + 5,
            ['generation', 'waiting', '写稿任务已提交', ['generation_id' => $data['generation_id']]]);
    }

    private static function pollGeneration(int $id, int $tenantId, int $userId, array $config, array $data): void
    {
        $details = ['generation_id' => $data['generation_id']];
        $result = GeoArticleGenerationService::detail($tenantId, $userId, $data['generation_id']);
        if (in_array($result['status'], ['queued', 'running'], true)) {
            $event = $result['status'] === 'running' && empty($data['generation_running'])
                ? ['generation', 'running', '正在根据品牌知识库生成稿件', $details] : null;
            $data['generation_running'] = $result['status'] === 'running';
            self::move($id, self::WAIT_GENERATION, $data, time() + 5, $event);
            return;
        }
        $articleId = (int)($result['articleIds'][0] ?? 0);
        if ($result['status'] !== 'completed' || $articleId <= 0) {
            $message = $result['error'] !== '' ? $result['error'] : '写稿任务未生成可投稿的稿件';
            self::finish($id, $data, 'generation', $message, false, $details);
            return;
        }

        GeoArticleService::prepareForAutomaticSubmission($tenantId, $userId, $articleId, $config['preview_origin']);
        $data += ['article_id' => $articleId, 'media_index' => 0, 'submitted' => 0, 'submission_failed' => 0];
        self::move($id, self::SUBMIT, $data, time(),
            ['generation', 'success', '稿件已生成，开始自动投稿', $details + ['article_id' => $articleId]]);
    }

    private static function submitMedia(int $id, int $userId, array $config, array $data): void
    {
        $targets = $config['media_targets'];
        $target = $targets[$data['media_index']];
        $details = $target + ['article_id' => $data['article_id']];
        UserTaskRunProgress::record($id, 'submission', 'running', '正在投稿至 ' . $target['media_name'], $details);
        try {
            $order = GeoOrderService::create($userId, [
                'article_id' => $data['article_id'],
                'media_id' => $target['media_id'],
                'price_type' => $target['price_type'],
            ]);
            $accepted = in_array((int)$order['status'], [
                GeoOrder::STATUS_PENDING, GeoOrder::STATUS_ARRANGED, GeoOrder::STATUS_PUBLISHED,
                GeoOrder::STATUS_FEEDBACK, GeoOrder::STATUS_DONE,
            ], true);
            $details += [
                'order_id' => (int)$order['id'],
                'order_no' => (string)$order['order_no'],
                'order_status' => (string)$order['status_text'],
                'sale_price' => (float)$order['sale_price'],
            ];
            if (!$accepted && !empty($order['fail_reason'])) {
                $details['failure_reason'] = (string)$order['fail_reason'];
            }
        } catch (InvalidArgumentException $e) {
            $details['failure_reason'] = $e->getMessage();
            $accepted = false;
        } catch (Exception $e) {
            Log::error(sprintf('[user-task-geo] run=%d media=%d submission error=%s', $id, $target['media_id'], $e->getMessage()));
            $accepted = false;
        }
        $data[$accepted ? 'submitted' : 'submission_failed']++;
        $data['media_index']++;
        // 下一家媒体使用新的到期时间，已读到旧阶段的 Worker 无法再次领取。
        self::move($id, self::SUBMIT, $data, time() + 1, [
            'submission', $accepted ? 'success' : 'failed',
            $target['media_name'] . ($accepted ? ' 投稿已受理' : ' 投稿未受理'), $details,
        ], $data['media_index'] === count($targets));
    }

    private static function move(int $id, string $step, array $data, int $at, ?array $event = null, bool $lastMedia = false): void
    {
        Db::transaction(static function () use ($id, $step, $data, $at, $event, $lastMedia): void {
            if ($event !== null) {
                UserTaskRunProgress::record($id, ...$event);
            }
            if ($lastMedia) {
                $message = sprintf('自动投稿结束，%d 家媒体已受理，%d 家未受理', $data['submitted'], $data['submission_failed']);
                self::finish($id, $data, 'job', $message, $data['submission_failed'] === 0);
                return;
            }
            Db::name(UserTaskRunService::TABLE)->where('id', $id)->update([
                'workflow_step' => $step,
                'workflow_data' => json_encode($data, JSON_THROW_ON_ERROR),
                'next_step_at' => $at,
            ]);
        });
    }

    private static function finish(int $id, array $data, string $step, string $message, bool $success, array $details = []): void
    {
        Db::transaction(static function () use ($id, $data, $step, $message, $success, $details): void {
            $status = $success ? 'success' : 'failed';
            Db::name(UserTaskRunService::TABLE)->where('id', $id)->update([
                'status' => $status,
                'output' => $success ? $message : null,
                'reason' => $success ? '' : mb_substr($message, 0, 255),
                'finished_at' => time(),
                'workflow_step' => null,
                'workflow_data' => json_encode($data, JSON_THROW_ON_ERROR),
                'next_step_at' => null,
            ]);
            UserTaskRunProgress::record($id, $step, $status, $message, $details);
        });
    }
}
