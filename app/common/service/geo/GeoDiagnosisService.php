<?php

namespace app\common\service\geo;

use app\common\model\geo\GeoDiagBatch;
use app\common\model\geo\GeoDiagRun;
use app\common\model\geo\GeoDiagSample;
use app\common\model\merchant\MerchantBrand;
use app\common\service\user_task\GeoRankingTaskJob;
use Exception;
use think\facade\Log;
use think\facade\Db;

/**
 * 自研 GEO 品牌诊断业务。
 */
class GeoDiagnosisService
{
    public const MAX_QUESTIONS = 20;
    public const MAX_UNITS_PER_QUESTION = 20;
    public const DEFAULT_POLL_AFTER = 10;

    /** @var string[] */
    public const WEB_PLATFORMS = [
        'deepseek', 'doubao', 'kimi', 'qwen', 'yuanbao', 'baiduai', 'antafu', 'quark',
        'weibo_zhisou', 'douyinai', 'chatgpt', 'googleai', 'bingai_overviews',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function platforms(): array
    {
        return GeoDiagProvider::platforms();
    }

    /** @return array<string, mixed> */
    public static function regions(): array
    {
        return GeoDiagProvider::regions();
    }

    /** @return array<string, mixed> */
    public static function usage(): array
    {
        return GeoDiagProvider::usage();
    }

    /**
     * @param int $userId
     * @param array<string, mixed> $params
     * @param int|null $taskRunId 内部计划执行记录 ID，手动检测留空
     * @return array<string, mixed>
     */
    public static function create(int $userId, array $params, ?int $taskRunId = null): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        $brand = MerchantBrand::where('id', $brandId)->findOrEmpty();
        if ($brand->isEmpty() || (int)$brand['user_id'] !== $userId) {
            throw new Exception('品牌不存在或无权限');
        }

        $questions = self::questions($params['questions'] ?? []);
        $platforms = self::platformsInput($params['platforms'] ?? []);
        $repeatCount = min(20, max(1, (int)($params['repeat_count'] ?? 1)));
        $intervalSeconds = min(3600, max(0, (int)($params['interval_seconds'] ?? 60)));
        $screenshot = self::boolParam($params['screenshot'] ?? false);
        $conversationLink = self::boolParam($params['conversation_link'] ?? false);
        $regionCode = trim((string)($params['region_code'] ?? ''));
        if ($regionCode !== '') {
            foreach ($platforms as $platform) {
                if (!in_array($platform, self::WEB_PLATFORMS, true)) {
                    throw new Exception('指定省份仅支持Web平台，请移除手机端平台');
                }
            }
        }
        if (count($platforms) * $repeatCount > self::MAX_UNITS_PER_QUESTION) {
            throw new Exception('单个问题的采集单元不能超过20个，请减少平台或重复次数');
        }

        $aliases = self::aliases((string)$brand['name'], $params['brand_aliases'] ?? []);
        $title = trim((string)($params['title'] ?? '')) ?: ((string)$brand['name'] . '品牌诊断');
        $platformOptions = [];
        if ($screenshot || $conversationLink) {
            foreach ($platforms as $platform) {
                $platformOptions[$platform] = [
                    'screenshot' => $screenshot,
                    'conversationLink' => $conversationLink,
                ];
            }
        }

        return Db::transaction(function () use ($userId, $brand, $questions, $platforms, $repeatCount, $intervalSeconds, $screenshot, $conversationLink, $regionCode, $aliases, $title, $platformOptions, $taskRunId) {
            $now = time();
            $batch = GeoDiagBatch::create([
                'user_id' => $userId,
                'task_run_id' => $taskRunId,
                'merchant_brand_id' => (int)$brand['id'],
                'title' => mb_substr($title, 0, 200),
                'brand_name' => (string)$brand['name'],
                'brand_aliases_json' => self::json($aliases),
                'question_count' => count($questions),
                'platforms_json' => self::json($platforms),
                'region_code' => $regionCode,
                'repeat_count' => $repeatCount,
                'screenshot' => $screenshot ? 1 : 0,
                'conversation_link' => $conversationLink ? 1 : 0,
                'unit_count' => count($questions) * count($platforms) * $repeatCount,
                'status' => GeoDiagBatch::STATUS_QUEUED,
                'next_poll_time' => $now + self::DEFAULT_POLL_AFTER,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $idempotencyKey = self::idempotencyKey((int)$batch['tenant_id'], $userId, (int)$batch['id']);
            if ($taskRunId !== null) {
                $idempotencyKey = 'gdiag-task-' . $batch['tenant_id'] . '-' . $userId . '-' . $taskRunId;
            }
            $items = [];
            foreach ($questions as $index => $prompt) {
                $item = [
                    'prompt' => $prompt,
                    'platforms' => $platforms,
                    'repeatCount' => $repeatCount,
                    'intervalSeconds' => $intervalSeconds,
                ];
                if ($platformOptions !== []) {
                    $item['platformOptions'] = $platformOptions;
                }
                if ($regionCode !== '') {
                    $item['regionCode'] = [$regionCode];
                }
                $items[] = $item;
                GeoDiagRun::create([
                    'tenant_id' => (int)$batch['tenant_id'],
                    'user_id' => $userId,
                    'batch_id' => (int)$batch['id'],
                    'batch_item_index' => $index,
                    'prompt' => $prompt,
                    'platforms_json' => self::json($platforms),
                    'idempotency_key' => $idempotencyKey . '-' . $index,
                    'status' => GeoDiagRun::STATUS_PENDING,
                    'next_poll_time' => $now + self::DEFAULT_POLL_AFTER,
                    'request_json' => self::json($item),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            $request = ['items' => $items];
            $batch->save([
                'idempotency_key' => $idempotencyKey,
                'request_json' => self::json($request),
            ]);

            try {
                $accepted = GeoDiagProvider::createRun($request, $idempotencyKey);
            } catch (GeoDiagProviderException $e) {
                self::markCreateFailed($batch, $e->getMessage(), $e->providerCode(), $e->requestId(), $e->payload());
                throw new Exception($e->getMessage());
            } catch (Exception $e) {
                self::markCreateFailed($batch, $e->getMessage());
                throw $e;
            }

            $payload = is_array($accepted['data']) ? $accepted['data'] : [];
            if ($taskRunId !== null && empty($payload['id'])) {
                throw new Exception('诊断服务未返回批次ID');
            }
            $itemsResult = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $batch->save([
                'upstream_batch_id' => (string)($payload['id'] ?? ''),
                // 自动检测统一在轮询阶段拉取明细，再确认终态。
                'status' => $taskRunId === null
                    ? (string)($payload['status'] ?? GeoDiagBatch::STATUS_QUEUED)
                    : GeoDiagBatch::STATUS_QUEUED,
                'provider_request_id' => (string)$accepted['request_id'],
                'charged_cents' => (int)$accepted['charged_cents'],
                'balance_cents' => (int)$accepted['balance_cents'],
                'response_json' => self::json($payload),
                'next_poll_time' => time() + self::DEFAULT_POLL_AFTER,
                'update_time' => $now,
            ]);
            foreach ($itemsResult as $row) {
                $idx = (int)($row['index'] ?? -1);
                if ($idx < 0) {
                    continue;
                }
                GeoDiagRun::where('batch_id', (int)$batch['id'])->where('batch_item_index', $idx)->update([
                    'upstream_run_id' => (string)($row['runId'] ?? ''),
                    'status' => (string)($payload['status'] ?? GeoDiagRun::STATUS_QUEUED),
                    'provider_request_id' => (string)$accepted['request_id'],
                    'charged_cents' => (int)$accepted['charged_cents'],
                    'balance_cents' => (int)$accepted['balance_cents'],
                    'response_json' => self::json($row),
                    'next_poll_time' => $now + self::DEFAULT_POLL_AFTER,
                    'update_time' => $now,
                ]);
            }

            return self::detail((int)$batch['id'], $userId);
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{count:int,lists:array<int,array<string,mixed>>}
     */
    public static function lists(array $params, int $userId): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $query = GeoDiagBatch::where('user_id', $userId);
        $brandId = (int)($params['brand_id'] ?? 0);
        if ($brandId > 0) {
            $query->where('merchant_brand_id', $brandId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')->whereOr('brand_name', 'like', '%' . $keyword . '%');
            });
        }
        $count = (clone $query)->count();
        $lists = $query->field('id,merchant_brand_id,title,brand_name,question_count,platforms_json,region_code,repeat_count,screenshot,conversation_link,unit_count,status,mention_rate,coverage_rate,citation_rate,completed_count,failed_count,charged_cents,balance_cents,error,finish_time,create_time,update_time')
            ->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        foreach ($lists as &$row) {
            self::presentBatch($row);
        }
        unset($row);
        return ['count' => $count, 'lists' => $lists];
    }

    /** @return array<string, mixed> */
    public static function detail(int $id, int $userId = 0): array
    {
        $query = GeoDiagBatch::where('id', $id);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $batch = $query->findOrEmpty();
        if ($batch->isEmpty()) {
            throw new Exception('诊断记录不存在');
        }
        $data = $batch->toArray();
        self::presentBatch($data);
        $runs = GeoDiagRun::where('batch_id', $id)->order('batch_item_index', 'asc')->select()->toArray();
        $samples = GeoDiagSample::where('batch_id', $id)->order('run_id', 'asc')->order('platform_id', 'asc')->order('sample_index', 'asc')->select()->toArray();
        foreach ($samples as &$sample) {
            self::presentSample($sample);
        }
        unset($sample);
        $byRun = [];
        foreach ($samples as $sample) {
            $byRun[(int)$sample['run_id']][] = $sample;
        }
        foreach ($runs as &$run) {
            $run['platforms'] = self::decode($run['platforms_json'], []);
            $run['samples'] = $byRun[(int)$run['id']] ?? [];
        }
        unset($run);
        $data['runs'] = $runs;
        return $data;
    }

    /** @return array<string, int> */
    public static function syncDue(int $limit = 50): array
    {
        $stat = ['checked' => 0, 'completed' => 0, 'failed' => 0];
        $now = time();
        $batches = GeoDiagBatch::where(static function ($query): void {
            $query->where(static function ($active): void {
                $active->where('terminal', 0)->whereNotIn('status', GeoDiagBatch::TERMINAL_STATUS);
            })->whereOr(static function ($pending): void {
                // 自动批次结果已落库但尚未通知时，下次只补交本地结果。
                $pending->whereNotNull('task_run_id')->where('next_poll_time', '>', 0);
            });
        })
            ->where('next_poll_time', '<=', $now)
            ->order('next_poll_time', 'asc')
            ->limit(max(1, $limit))
            ->select();
        foreach ($batches as $batch) {
            $stat['checked']++;
            try {
                $before = [$batch['status'], $batch['completed_count'], $batch['failed_count']];
                if (empty($batch['terminal'])) {
                    self::syncBatch($batch);
                }
                $terminal = !empty($batch['terminal']);
                $changed = $before !== [$batch['status'], $batch['completed_count'], $batch['failed_count']];
                if ($batch['task_run_id'] && ($changed || $terminal)) {
                    GeoRankingTaskJob::onDiagnosisUpdated($batch->toArray(), $terminal);
                    if ($terminal) {
                        $batch->save(['next_poll_time' => 0]);
                    }
                }
                if ($before[0] !== $batch['status'] || $terminal) {
                    $stat['completed']++;
                }
            } catch (Exception $e) {
                $stat['failed']++;
                if ($batch['task_run_id']) {
                    Log::warning(sprintf('[geo-diag] batch=%d error=%s', $batch['id'], $e->getMessage()));
                }
            }
        }
        return $stat;
    }

    /** @return array<string, mixed> */
    public static function syncOne(int $id, int $userId = 0): array
    {
        $query = GeoDiagBatch::where('id', $id);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $batch = $query->findOrEmpty();
        if ($batch->isEmpty()) {
            throw new \InvalidArgumentException('诊断记录不存在');
        }
        // 自动批次交由现有后台轮询同步和接续。手动批次可以从页面立即刷新。
        if (!$batch['task_run_id'] && !in_array((string)$batch['status'], GeoDiagBatch::TERMINAL_STATUS, true)) {
            self::syncBatch($batch);
        }
        return self::detail($id, $userId);
    }

    private static function syncBatch(GeoDiagBatch $batch): void
    {
        $upstreamBatchId = trim((string)$batch['upstream_batch_id']);
        if ($upstreamBatchId === '') {
            throw new \InvalidArgumentException('诊断记录缺少服务商批次ID');
        }
        $statusResponse = GeoDiagProvider::batchStatus($upstreamBatchId);
        $status = is_array($statusResponse['data']) ? $statusResponse['data'] : [];
        $pollAfter = (int)($status['pollAfterSeconds'] ?? self::DEFAULT_POLL_AFTER);
        $now = time();
        $data = [
            'status' => (string)($status['status'] ?? $batch['status']),
            'terminal' => !empty($status['terminal']) ? 1 : 0,
            'completed_count' => (int)($status['completedChecks'] ?? 0),
            'failed_count' => (int)(($status['failedChecks'] ?? 0) + ($status['cancelledChecks'] ?? 0)),
            'status_json' => self::json($status),
            'provider_request_id' => (string)$statusResponse['request_id'],
            'charged_cents' => (int)$statusResponse['charged_cents'],
            'balance_cents' => (int)$statusResponse['balance_cents'],
            'poll_count' => (int)$batch['poll_count'] + 1,
            'next_poll_time' => $now + max(5, $pollAfter ?: self::DEFAULT_POLL_AFTER),
            'update_time' => $now,
        ];
        self::syncRunsFromStatus((int)$batch['id'], $status);
        if ($data['terminal'] || in_array($data['status'], GeoDiagBatch::TERMINAL_STATUS, true)) {
            // 先持久化样本与汇总，拉取失败时保留原状态供下一次轮询。
            $data = array_replace($data, self::syncBatchResults($batch));
            $data['terminal'] = 1;
            $data['finish_time'] = $now;
            $data['next_poll_time'] = $batch['task_run_id'] ? $now : 0;
        }
        $batch->save($data);
    }

    /** @param array<string, mixed> $status */
    private static function syncRunsFromStatus(int $batchId, array $status): void
    {
        $items = is_array($status['items'] ?? null) ? $status['items'] : [];
        foreach ($items as $item) {
            $idx = (int)($item['index'] ?? -1);
            if ($idx < 0) {
                continue;
            }
            GeoDiagRun::where('batch_id', $batchId)->where('batch_item_index', $idx)->update([
                'upstream_run_id' => (string)($item['runId'] ?? ''),
                'status' => (string)($item['status'] ?? GeoDiagRun::STATUS_RUNNING),
                'terminal' => in_array((string)($item['status'] ?? ''), GeoDiagRun::TERMINAL_STATUS, true) ? 1 : 0,
                'status_json' => self::json($item),
                'update_time' => time(),
            ]);
        }
    }

    private static function syncBatchResults(GeoDiagBatch $batch): array
    {
        $upstreamBatchId = (string)$batch['upstream_batch_id'];
        $page = 1;
        $pages = 1;
        do {
            $resultResponse = GeoDiagProvider::batchDetail($upstreamBatchId, $page);
            $result = is_array($resultResponse['data']) ? $resultResponse['data'] : [];
            $pages = max(1, (int)($result['totalPages'] ?? 1));
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            foreach ($items as $item) {
                $run = is_array($item['run'] ?? null) ? $item['run'] : [];
                self::saveRunResult((int)$batch['id'], (int)($item['index'] ?? -1), $run);
            }
            $page++;
        } while ($page <= $pages);
        self::syncReports((int)$batch['id']);
        return self::summarizeBatch((int)$batch['id']);
    }

    /** @param array<string, mixed> $runData */
    private static function saveRunResult(int $batchId, int $index, array $runData): void
    {
        $run = GeoDiagRun::where('batch_id', $batchId)->where('batch_item_index', $index)->findOrEmpty();
        if ($run->isEmpty()) {
            return;
        }
        $run->save([
            'upstream_run_id' => (string)($runData['id'] ?? $run['upstream_run_id']),
            'status' => (string)($runData['status'] ?? $run['status']),
            'terminal' => in_array((string)($runData['status'] ?? ''), GeoDiagRun::TERMINAL_STATUS, true) ? 1 : 0,
            'result_json' => self::json($runData),
            'update_time' => time(),
        ]);
        $samples = is_array($runData['platformRuns'] ?? null) ? $runData['platformRuns'] : [];
        foreach ($samples as $sample) {
            self::saveSample($batchId, (int)$run['id'], (int)$run['user_id'], (int)$run['tenant_id'], (string)$run['prompt'], $sample);
        }
    }

    /** @param array<string, mixed> $sample */
    private static function saveSample(int $batchId, int $runId, int $userId, int $tenantId, string $prompt, array $sample): void
    {
        $answer = is_array($sample['answer'] ?? null) ? $sample['answer'] : [];
        $provider = is_array($answer['providerResult'] ?? null) ? $answer['providerResult'] : [];
        $aliases = self::batchAliases($batchId);
        $content = (string)($answer['content'] ?? '');
        $localMentioned = self::mentioned($content, $aliases);
        $platformId = (string)($sample['platformId'] ?? '');
        $sampleIndex = (int)($sample['sampleIndex'] ?? 0);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'batch_id' => $batchId,
            'run_id' => $runId,
            'upstream_sample_id' => (string)($sample['id'] ?? ''),
            'prompt' => $prompt,
            'prompt_id' => (string)($sample['promptId'] ?? ''),
            'platform_id' => $platformId,
            'platform_name' => $platformId,
            'client' => self::isMobilePlatform($platformId) ? 'mobile' : 'web',
            'sample_index' => $sampleIndex,
            'status' => (string)($sample['status'] ?? ''),
            'error_code' => (string)($sample['errorCode'] ?? ''),
            'error_message' => mb_substr((string)($sample['errorMessage'] ?? ''), 0, 500),
            'answer_content' => $content,
            'brand_mentioned' => !empty($answer['brandMentioned']) ? 1 : 0,
            'local_mentioned' => $localMentioned ? 1 : 0,
            'mention_count' => (int)($answer['mentionCount'] ?? ($localMentioned ? 1 : 0)),
            'mention_position' => (int)($provider['mentionPosition'] ?? 0),
            'sentiment' => (string)($provider['sentiment'] ?? ''),
            'citations_json' => self::json(is_array($answer['citations'] ?? null) ? $answer['citations'] : []),
            'references_json' => self::json(is_array($provider['references'] ?? null) ? $provider['references'] : []),
            'competitors_json' => self::json(is_array($provider['competitorRankings'] ?? null) ? $provider['competitorRankings'] : []),
            'goods_json' => self::json(is_array($provider['goods'] ?? null) ? $provider['goods'] : []),
            'provider_result_json' => self::json($provider),
            'monitoring_options_json' => self::json(is_array($sample['monitoringOptions'] ?? null) ? $sample['monitoringOptions'] : []),
            'requested_region_code' => self::json($sample['requestedRegionCode'] ?? null),
            'screenshot_url' => (string)($provider['screenshotUrl'] ?? ''),
            'response_url' => (string)($answer['responseUrl'] ?? ''),
            'quoted_price_cents' => (int)($sample['quotedPriceCents'] ?? 0),
            'raw_json' => self::json($sample),
            'update_time' => time(),
        ];
        $row = GeoDiagSample::where('run_id', $runId)->where('platform_id', $platformId)->where('sample_index', $sampleIndex)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            GeoDiagSample::create($data);
        } else {
            $row->save($data);
        }
    }

    private static function syncReports(int $batchId): void
    {
        $runs = GeoDiagRun::where('batch_id', $batchId)->where('upstream_run_id', '<>', '')->select();
        foreach ($runs as $run) {
            try {
                $reportResponse = GeoDiagProvider::runReport((string)$run['upstream_run_id']);
                $report = is_array($reportResponse['data']) ? $reportResponse['data'] : [];
                $run->save(['report_json' => self::json($report), 'update_time' => time()]);
            } catch (Exception) {
                // 报告是汇总增强项，失败不影响样本结果落库。
            }
        }
    }

    private static function summarizeBatch(int $batchId): array
    {
        $samples = GeoDiagSample::where('batch_id', $batchId)->select()->toArray();
        $total = count($samples);
        $completed = 0;
        $failed = 0;
        $mentioned = 0;
        $cited = 0;
        foreach ($samples as $sample) {
            $status = (string)$sample['status'];
            if ($status === 'completed') {
                $completed++;
            } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                $failed++;
            }
            if ($status === 'completed' && ((int)$sample['local_mentioned'] === 1 || (int)$sample['brand_mentioned'] === 1)) {
                $mentioned++;
            }
            $citations = self::decode($sample['citations_json'] ?? '[]', []);
            $references = self::decode($sample['references_json'] ?? '[]', []);
            if ($citations !== [] || $references !== []) {
                $cited++;
            }
        }
        $valid = max(1, $completed);
        return [
            'completed_count' => $completed,
            'failed_count' => $failed,
            'mention_rate' => $completed > 0 ? round($mentioned / $valid, 4) : 0,
            'coverage_rate' => $total > 0 ? round($completed / $total, 4) : 0,
            'citation_rate' => $completed > 0 ? round($cited / $valid, 4) : 0,
            'summary_json' => self::json(['planned' => $total, 'completed' => $completed, 'failed' => $failed, 'mentioned' => $mentioned, 'cited' => $cited]),
        ];
    }

    /** @param mixed $value */
    private static function questions($value): array
    {
        $input = is_array($value) ? $value : explode("\n", (string)$value);
        $questions = [];
        foreach ($input as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $questions[] = mb_substr($item, 0, 2000);
            }
        }
        $questions = array_values(array_unique($questions));
        if ($questions === []) {
            throw new Exception('请填写诊断问题');
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            throw new Exception('一次最多提交20个问题');
        }
        return $questions;
    }

    /** @param mixed $value */
    private static function platformsInput($value): array
    {
        $input = is_array($value) ? $value : explode(',', (string)$value);
        $platforms = [];
        foreach ($input as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $platforms[] = $item;
            }
        }
        $platforms = array_values(array_unique($platforms));
        if ($platforms === []) {
            throw new Exception('请选择诊断平台');
        }
        return $platforms;
    }

    /** @param mixed $value */
    private static function aliases(string $brandName, $value): array
    {
        $input = is_array($value) ? $value : explode(',', (string)$value);
        $aliases = [$brandName];
        foreach ($input as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $aliases[] = mb_substr($item, 0, 80);
            }
        }
        return array_values(array_unique($aliases));
    }

    private static function boolParam($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
    }

    private static function idempotencyKey(int $tenantId, int $userId, int $batchId): string
    {
        return 'gdiag-' . $tenantId . '-' . $userId . '-' . $batchId;
    }

    /** @param mixed $value */
    private static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /** @param mixed $fallback */
    public static function decode($value, $fallback)
    {
        if (!is_string($value) || $value === '') {
            return $fallback;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    /** @param array<string, mixed> $payload */
    private static function markCreateFailed(GeoDiagBatch $batch, string $message, string $code = '', string $requestId = '', array $payload = []): void
    {
        $batch->save([
            'status' => GeoDiagBatch::STATUS_FAILED,
            'terminal' => 1,
            'error' => mb_substr(($code !== '' ? $code . ': ' : '') . $message, 0, 500),
            'provider_request_id' => $requestId,
            'response_json' => self::json($payload),
            'finish_time' => time(),
            'next_poll_time' => 0,
            'update_time' => time(),
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function presentBatch(array &$row): void
    {
        $row['platforms'] = self::decode($row['platforms_json'] ?? '[]', []);
        $row['brand_aliases'] = self::decode($row['brand_aliases_json'] ?? '[]', []);
        $row['summary'] = self::decode($row['summary_json'] ?? '{}', []);
        $row['progress'] = (int)($row['unit_count'] ?? 0) > 0 ? round(((int)($row['completed_count'] ?? 0) + (int)($row['failed_count'] ?? 0)) / max(1, (int)$row['unit_count']), 4) : 0;
    }

    /** @param array<string, mixed> $row */
    private static function presentSample(array &$row): void
    {
        $row['citations'] = self::decode($row['citations_json'] ?? '[]', []);
        $row['references'] = self::decode($row['references_json'] ?? '[]', []);
        $row['competitors'] = self::decode($row['competitors_json'] ?? '[]', []);
        $row['goods'] = self::decode($row['goods_json'] ?? '[]', []);
        $row['monitoring_options'] = self::decode($row['monitoring_options_json'] ?? '{}', []);
        $row['provider_result'] = self::decode($row['provider_result_json'] ?? '{}', []);
    }

    /** @return string[] */
    private static function batchAliases(int $batchId): array
    {
        $raw = (string)GeoDiagBatch::where('id', $batchId)->value('brand_aliases_json');
        $aliases = self::decode($raw, []);
        return is_array($aliases) ? array_values(array_filter(array_map('strval', $aliases))) : [];
    }

    private static function isMobilePlatform(string $platformId): bool
    {
        return substr($platformId, -7) === '_mobile' || in_array($platformId, ['qianwen_mobile', 'baidu_mobile', 'xiaohongshu_mobile'], true);
    }

    /** @param string[] $aliases */
    private static function mentioned(string $content, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias !== '' && mb_stripos($content, $alias) !== false) {
                return true;
            }
        }
        return false;
    }
}
