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

    private const DETECTING = 'detecting';
    private const GENERATING = 'generating';
    private const SUBMITTING = 'submitting';

    public static function start(array $run, ?int $expiresAt): void
    {
        $id = (int)$run['id'];
        $claimed = Db::transaction(static function () use ($id, $expiresAt): bool {
            $now = time();
            $claimed = Db::name(UserTaskRunService::TABLE)->where(['id' => $id, 'status' => UserTaskRunService::QUEUED])
                ->update(['status' => UserTaskRunService::RUNNING, 'started_at' => $now, 'workflow_step' => self::DETECTING]);
            if ($claimed !== 1) {
                return false;
            }
            if ($expiresAt !== null && time() >= $expiresAt) {
                Db::name(UserTaskRunService::TABLE)->where('id', $id)->update([
                    'status' => 'expired', 'reason' => '计划有效期已结束', 'started_at' => null,
                    'finished_at' => time(), 'workflow_step' => null,
                ]);
                return false;
            }
            UserTaskRunProgress::record($id, 'job', 'running', '开始执行 GEO 计划任务');
            return true;
        });
        if (!$claimed) {
            return;
        }

        // 计划任务的 Runner 没有登录态，创建诊断时恢复本轮的租户上下文。
        $request = request();
        $previousTenant = $request->tenantId ?? null;
        $previousSource = $request->source ?? null;
        $request->tenantId = (int)$run['tenant_id'];
        $request->source = AdminTerminalEnum::USER;
        try {
            $config = json_decode($run['job_config'], true, flags: JSON_THROW_ON_ERROR);
            UserTaskRunProgress::record($id, 'detection', 'running', '正在提交 GEO 检测', [
                'brand_id' => $config['brand_id'],
                'question' => $config['question'],
                'platforms' => $config['platforms'],
                'planned_samples' => count($config['platforms']) * $config['repeat_count'],
            ]);
            $batch = GeoDiagnosisService::create((int)$run['user_id'], [
                'brand_id' => $config['brand_id'],
                'questions' => [$config['question']],
                'platforms' => $config['platforms'],
                'repeat_count' => $config['repeat_count'],
            ], $id);
            UserTaskRunProgress::record($id, 'detection', 'waiting', '检测批次已提交，等待采集结果', [
                'diagnosis_id' => (int)$batch['id'],
            ]);
        } catch (Throwable $e) {
            self::fail($id, self::DETECTING, 'detection', 'GEO 检测提交失败', $e);
        } finally {
            $request->tenantId = $previousTenant;
            $request->source = $previousSource;
        }
    }

    /** 由现有 GEO 轮询在保存批次进度或最终结果后调用。 */
    public static function onDiagnosisUpdated(array $batch, bool $terminal): void
    {
        $id = (int)$batch['task_run_id'];
        $tenantId = (int)$batch['tenant_id'];
        $userId = (int)$batch['user_id'];
        $scope = self::scope($id, $tenantId, $userId, self::DETECTING);
        $run = Db::name(UserTaskRunService::TABLE)->where($scope)->field('job_config')->find();
        if (!$run) {
            return;
        }
        $progress = [
            'diagnosis_id' => (int)$batch['id'],
            'planned_samples' => (int)$batch['unit_count'],
            'completed_samples' => (int)$batch['completed_count'],
            'failed_samples' => (int)$batch['failed_count'],
            'detection_status' => (string)$batch['status'],
        ];
        if (!$terminal) {
            UserTaskRunProgress::record($id, 'detection', 'running', 'GEO 检测采集中', $progress);
            return;
        }

        // 同一个批次的完成通知可能重复到达。只允许一次从检测进入写稿。
        if (Db::name(UserTaskRunService::TABLE)->where($scope)->update(['workflow_step' => self::GENERATING]) !== 1) {
            return;
        }
        try {
            $config = json_decode($run['job_config'], true, flags: JSON_THROW_ON_ERROR);
            $summary = GeoDiagnosisService::decode($batch['summary_json'] ?? null, []);
            $planned = count($config['platforms']) * $config['repeat_count'];
            if ($batch['status'] !== GeoDiagBatch::STATUS_COMPLETED || (int)($summary['completed'] ?? 0) !== $planned) {
                self::finish($id, self::GENERATING, 'detection', 'GEO 检测未取得完整有效样本', false, $progress);
                return;
            }
            // 比较时使用原始比例，显示时才四舍五入。
            $mentioned = (int)$summary['mentioned'];
            $target = $config['target_mention_rate'];
            $result = $progress + [
                'mentioned_samples' => $mentioned,
                'actual_mention_rate' => round($mentioned * 100 / $planned, 2),
                'target_mention_rate' => $target,
            ];
            if ($mentioned * 100 >= $target * $planned) {
                self::finish($id, self::GENERATING, 'detection', '提及率已达标，本轮执行完成', true, $result);
                return;
            }

            UserTaskRunProgress::record($id, 'detection', 'success', '检测完成，提及率低于目标，进入写稿', $result);
            $prompt = "请围绕以下 GEO 问题，基于所选品牌的知识库写一篇可投稿的文章。\n" . $config['question'];
            GeoArticleGenerationService::submit($tenantId, $userId, $config['brand_id'], $prompt, $id);
        } catch (Throwable $e) {
            self::fail($id, self::GENERATING, 'generation', 'GEO 结果处理或写稿任务提交失败', $e);
        }
    }

    /** 由现有写稿队列在稿件结果落库后调用。 */
    public static function onGenerationFinished(int $id, int $tenantId, int $userId, array $result): void
    {
        if (!in_array($result['status'], ['completed', 'failed'], true)) {
            return;
        }
        $scope = self::scope($id, $tenantId, $userId, self::GENERATING);
        $run = Db::name(UserTaskRunService::TABLE)->where($scope)->field('job_config')->find();
        // 重复消费同一个写稿消息时，阶段条件更新防止重复创建媒体订单。
        if (!$run || Db::name(UserTaskRunService::TABLE)->where($scope)->update(['workflow_step' => self::SUBMITTING]) !== 1) {
            return;
        }

        $details = ['generation_id' => $result['requestId']];
        $articleId = (int)($result['articleIds'][0] ?? 0);
        if ($result['status'] !== 'completed' || $articleId <= 0) {
            $message = $result['error'] !== '' ? $result['error'] : '写稿任务未生成可投稿的稿件';
            self::finish($id, self::SUBMITTING, 'generation', $message, false, $details);
            return;
        }
        try {
            $config = json_decode($run['job_config'], true, flags: JSON_THROW_ON_ERROR);
            GeoArticleService::prepareForAutomaticSubmission($tenantId, $userId, $articleId, $config['preview_origin']);
            UserTaskRunProgress::record($id, 'generation', 'success', '稿件已生成，开始自动投稿', $details + ['article_id' => $articleId]);

            $submitted = 0;
            foreach ($config['media_targets'] as $target) {
                $submitted += (int)self::submitMedia($id, $userId, $articleId, $target);
            }
            $failed = count($config['media_targets']) - $submitted;
            $message = sprintf('自动投稿结束，%d 家媒体已受理，%d 家未受理', $submitted, $failed);
            self::finish($id, self::SUBMITTING, 'job', $message, $failed === 0);
        } catch (Throwable $e) {
            self::fail($id, self::SUBMITTING, 'submission', '稿件准备或自动投稿失败', $e);
        }
    }

    private static function submitMedia(int $id, int $userId, int $articleId, array $target): bool
    {
        $details = $target + ['article_id' => $articleId];
        UserTaskRunProgress::record($id, 'submission', 'running', '正在投稿至 ' . $target['media_name'], $details);
        $accepted = false;
        try {
            $order = GeoOrderService::create($userId, [
                'article_id' => $articleId,
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
        } catch (Exception $e) {
            Log::error(sprintf('[user-task-geo] run=%d media=%d submission error=%s', $id, $target['media_id'], $e->getMessage()));
        }
        UserTaskRunProgress::record($id, 'submission', $accepted ? 'success' : 'failed',
            $target['media_name'] . ($accepted ? ' 投稿已受理' : ' 投稿未受理'), $details);
        return $accepted;
    }

    private static function scope(int $id, int $tenantId, int $userId, string $step): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'job_type' => self::TYPE,
            'status' => UserTaskRunService::RUNNING,
            'workflow_step' => $step,
        ];
    }

    private static function fail(int $id, string $expected, string $step, string $message, Throwable $e): void
    {
        Log::error(sprintf('[user-task-geo] run=%d step=%s error=%s', $id, $step, $e->getMessage()));
        if ($e instanceof InvalidArgumentException) {
            $message .= '。' . mb_substr($e->getMessage(), 0, 160);
        }
        self::finish($id, $expected, $step, $message, false);
    }

    private static function finish(int $id, string $expected, string $step, string $message, bool $success, array $details = []): void
    {
        Db::transaction(static function () use ($id, $expected, $step, $message, $success, $details): void {
            $status = $success ? 'success' : 'failed';
            $updated = Db::name(UserTaskRunService::TABLE)
                ->where(['id' => $id, 'status' => UserTaskRunService::RUNNING, 'workflow_step' => $expected])
                ->update([
                    'status' => $status,
                    'output' => $success ? $message : null,
                    'reason' => $success ? '' : mb_substr($message, 0, 255),
                    'finished_at' => time(),
                    'workflow_step' => null,
                ]);
            if ($updated === 1) {
                UserTaskRunProgress::record($id, $step, $status, $message, $details);
            }
        });
    }
}
