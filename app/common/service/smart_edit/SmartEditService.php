<?php

namespace app\common\service\smart_edit;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\model\smart_edit\SmartEditTask;
use app\common\model\smart_edit\SmartEditTaskItem;
use Exception;
use think\facade\Log;

/**
 * 智能剪辑业务：建任务 → 构建 Timeline → 提交 IMS → 轮询 → 落地成片。
 *
 * 第一轮不收费：tenant_cost_points / user_charge_points 恒为 0，也不建 consumption 记录。
 * 表里的计费字段与 app_task_id / consumption_id 已预留，后续开收时只改本类内部。
 */
class SmartEditService
{
    public const MAX_CLIPS = 20;
    public const MAX_POLL_ATTEMPTS = 240;

    /** 标题文案最大字数 */
    public const MAX_TITLE_TEXT_LENGTH = 30;

    /** 默认比例：竖屏 9:16，与短视频场景一致 */
    public const DEFAULT_ASPECT_RATIO = '9:16';

    /**
     * 输出比例 -> 成片宽高。
     *
     * IMS 要求宽高均 ≥128、≤4096，短边 ≤2160，这里统一取 1080 短边档位。
     */
    public const OUTPUT_SIZES = [
        '9:16' => [1080, 1920],
        '16:9' => [1920, 1080],
        '1:1' => [1080, 1080],
        '4:3' => [1440, 1080],
        '3:4' => [1080, 1440],
    ];

    /**
     * 比例标识 -> [宽, 高]；未传或无法识别时回退默认比例。
     *
     * @param string $ratio 如 "9:16"
     * @return array{0:int,1:int}
     */
    public static function resolveOutputSize(string $ratio): array
    {
        $key = trim($ratio);
        if ($key === '' || !isset(self::OUTPUT_SIZES[$key])) {
            $key = self::DEFAULT_ASPECT_RATIO;
        }
        return self::OUTPUT_SIZES[$key];
    }

    /**
     * 创建并提交任务（同步提交，合成本身异步）。
     */
    public static function generate(int $tenantId, int $userId, array $params): array
    {
        [$editType, $title, $clips, $coverUri, $aspectRatio, $titleText] = self::validate($tenantId, $params);
        $config = SmartEditImsConfigService::require($tenantId);
        $taskNo = self::newTaskNo();
        [$outputWidth, $outputHeight] = self::resolveOutputSize($aspectRatio);

        $items = [];
        foreach ($clips as $index => $clip) {
            $items[] = [
                'media_url' => SmartEditMaterialService::mediaUrl($config, $tenantId, (string)$clip['uri']),
                'in' => (float)($clip['in'] ?? 0),
                'out' => (float)($clip['out'] ?? 0),
                'raw' => $clip,
            ];
        }

        // 封面与正片素材走同一套归属校验与签名，确保 IMS 能拉到。
        $coverUrl = $coverUri === ''
            ? ''
            : SmartEditMaterialService::mediaUrl($config, $tenantId, $coverUri);

        $output = SmartEditMaterialService::outputTarget($config, $taskNo);
        $timeline = self::buildTimeline($editType, $items, $coverUrl, $titleText);

        $task = SmartEditTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_no' => $taskNo,
            'edit_type' => $editType,
            'title' => $title,
            'params_json' => ['edit_type' => $editType, 'title' => $title, 'clips' => $clips, 'cover_uri' => $coverUri, 'aspect_ratio' => $aspectRatio, 'title_text' => $titleText],
            'timeline_json' => $timeline,
            'provider' => 'aliyun_ims',
            'region' => $config['region'],
            'provider_job_id' => '',
            'output_target' => $output['uri'],
            'output_uri' => '',
            'cover_uri' => $coverUri,
            'storage_scope' => 'tenant',
            'storage_engine' => 'aliyun',
            'storage_domain' => $config['domain'],
            // out=0 表示「用完整时长」，本地无法得知真实时长，这里只累加可确定的区间；
            // 成片成功后 markSuccess() 会用 IMS 返回的成片时长回填（拼接不会比素材总长更长）。
            'input_duration' => array_sum(array_map(
                static fn(array $item) => max(0, (float)$item['out'] - (float)$item['in']),
                $items
            )),
            'status' => SmartEditTask::STATUS_SUBMITTING,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $rows = [];
        if ($coverUri !== '') {
            // sort 用 0，视频整体后移，保证明细顺序与成片顺序一致（封面在最前）。
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$task['id'],
                'item_type' => SmartEditTaskItem::TYPE_COVER,
                'sort' => 0,
                'source_uri' => $coverUri,
                'storage_scope' => 'tenant',
                'storage_engine' => 'aliyun',
                'storage_domain' => $config['domain'],
                'in_sec' => 0,
                'out_sec' => 0,
                'duration' => SmartEditTimelineBuilder::COVER_SECONDS,
                'raw_meta' => json_encode(['media_url' => $coverUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ];
        }
        $sortBase = $rows === [] ? 0 : 1;
        foreach ($clips as $index => $clip) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$task['id'],
                'item_type' => SmartEditTaskItem::TYPE_VIDEO,
                'sort' => $sortBase + $index,
                'source_uri' => (string)$clip['uri'],
                'storage_scope' => 'tenant',
                'storage_engine' => 'aliyun',
                'storage_domain' => $config['domain'],
                'in_sec' => (float)($clip['in'] ?? 0),
                'out_sec' => (float)($clip['out'] ?? 0),
                'duration' => max(0, (float)($clip['out'] ?? 0) - (float)($clip['in'] ?? 0)),
                'raw_meta' => json_encode(['media_url' => $items[$index]['media_url']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ];
        }
        if ($rows !== []) {
            // 走查询构造器的批量插入：不触发模型事件，tenant_id/user_id/create_time 已在行内显式写入。
            SmartEditTaskItem::insertAll($rows);
        }

        try {
            $client = new ImsClient($config['access_key'], $config['secret_key'], $config['region']);
            $jobId = $client->submit($timeline, $output['url'], $outputWidth, $outputHeight);
            $task->save([
                'provider_job_id' => $jobId,
                'status' => SmartEditTask::STATUS_PROCESSING,
                'next_poll_time' => time() + 5,
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 提交失败 task=' . $taskNo . ' ' . $e->getMessage());
            $task->save(self::failPayload($e));
        }

        return self::formatTask($task->refresh()->toArray());
    }

    /**
     * 刷新一个任务（worker 或用户打开列表时调用）。
     */
    public static function refresh(int $taskId): ?array
    {
        $task = SmartEditTask::findOrEmpty($taskId);
        if ($task->isEmpty() || !in_array((string)$task['status'], SmartEditTask::RUNNING_STATUSES, true)) {
            return null;
        }
        if ((string)$task['provider_job_id'] === '') {
            return null;
        }
        $attempts = (int)$task['poll_attempts'];
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $task->save([
                'status' => SmartEditTask::STATUS_FAILED,
                'error_code' => 'POLL_TIMEOUT',
                'error_message' => '剪辑任务长时间未完成，请稍后在结果中确认或重新提交',
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            return null;
        }

        try {
            $config = SmartEditImsConfigService::require((int)$task['tenant_id']);
            // 用提交时记录的地域，避免中途改配置导致查不到旧任务
            $region = (string)$task['region'] ?: $config['region'];
            $client = new ImsClient($config['access_key'], $config['secret_key'], $region);
            $job = $client->getJob((string)$task['provider_job_id']);
        } catch (\Throwable $e) {
            // 查询失败只记录并延后重试，不直接判失败
            $task->save([
                'poll_attempts' => $attempts + 1,
                'next_poll_time' => time() + self::backoff($attempts + 1),
                'update_time' => time(),
            ]);
            Log::write('[smart_edit] 查询失败 task=' . $task['task_no'] . ' ' . $e->getMessage());
            return null;
        }

        $status = strtolower($job['status']);
        if (in_array($status, ['success', 'produced', 'finished'], true)) {
            self::markSuccess($task, $job);
            return null;
        }
        if (in_array($status, ['failed', 'producefailed', 'error'], true)) {
            $task->save([
                'status' => SmartEditTask::STATUS_FAILED,
                'error_code' => $job['code'] !== '' ? $job['code'] : 'PRODUCE_FAILED',
                'error_message' => $job['message'] !== '' ? $job['message'] : '剪辑失败',
                'poll_attempts' => $attempts + 1,
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            return null;
        }

        $task->save([
            'poll_attempts' => $attempts + 1,
            'next_poll_time' => time() + self::backoff($attempts + 1),
            'update_time' => time(),
        ]);
        return null;
    }

    /**
     * 重新提交一个失败的任务（沿用已落库的 Timeline）。
     */
    public static function retry(SmartEditTask $task): void
    {
        $params = (array)$task['params_json'];
        $config = SmartEditImsConfigService::require((int)$task['tenant_id']);
        $output = SmartEditMaterialService::outputTarget($config, (string)$task['task_no']);
        $timeline = (array)$task['timeline_json'];
        if ($timeline === []) {
            throw new Exception('任务参数缺失，无法重试');
        }
        // 沿用提交时的比例；老任务没有该字段，resolveOutputSize 会回退默认值。
        [$outputWidth, $outputHeight] = self::resolveOutputSize((string)($params['aspect_ratio'] ?? ''));
        $task->save([
            'status' => SmartEditTask::STATUS_SUBMITTING,
            'provider_job_id' => '',
            'error_code' => '',
            'error_message' => '',
            'poll_attempts' => 0,
            'next_poll_time' => 0,
            'output_target' => $output['uri'],
            'finish_time' => 0,
            'update_time' => time(),
        ]);
        try {
            $client = new ImsClient($config['access_key'], $config['secret_key'], $config['region']);
            $jobId = $client->submit($timeline, $output['url'], $outputWidth, $outputHeight);
            $task->save([
                'provider_job_id' => $jobId,
                'region' => $config['region'],
                'status' => SmartEditTask::STATUS_PROCESSING,
                'next_poll_time' => time() + 5,
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 重试提交失败 task=' . $task['task_no'] . ' ' . $e->getMessage());
            $task->save(self::failPayload($e));
        }
    }

    public static function cancel(int $tenantId, int $userId, int $taskId): void
    {
        $task = self::findOwned($tenantId, $userId, $taskId);
        if (!in_array((string)$task['status'], SmartEditTask::RUNNING_STATUSES, true)) {
            throw new Exception('该任务已结束，无法取消');
        }
        // IMS 没有公开的终止接口；这里只把本地状态置为取消，worker 会跳过后续轮询。
        $task->save([
            'status' => SmartEditTask::STATUS_CANCELED,
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function delete(int $tenantId, int $userId, int $taskId): void
    {
        $task = self::findOwned($tenantId, $userId, $taskId);
        $task->delete();
    }

    public static function findOwned(int $tenantId, int $userId, int $taskId): SmartEditTask
    {
        $task = SmartEditTask::where('id', $taskId)->where('tenant_id', $tenantId)->where('user_id', $userId)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return $task;
    }

    public static function detail(int $tenantId, int $userId, int $taskId): array
    {
        $task = self::findOwned($tenantId, $userId, $taskId);
        self::refreshIfStale($task);
        return self::formatTask($task->refresh()->toArray());
    }

    /**
     * 列表数据统一补全播放地址与素材明细。
     */
    public static function decorateRows(array $rows): array
    {
        $taskIds = array_values(array_filter(array_column($rows, 'id')));
        $itemMap = [];
        if ($taskIds !== []) {
            $items = SmartEditTaskItem::whereIn('task_id', $taskIds)->order(['task_id' => 'asc', 'sort' => 'asc'])->select();
            foreach ($items as $item) {
                $data = $item->toArray();
                // 素材地址同样只用任务落库的域名拼，不依赖其他存储配置
                $domain = (string)($data['storage_domain'] ?? '');
                $data['source_url'] = $domain === '' ? '' : SmartEditImsConfigService::joinUrl($domain, (string)$data['source_uri']);
                $itemMap[(int)$data['task_id']][] = $data;
            }
        }
        foreach ($rows as &$row) {
            $row['output_url'] = self::outputUrl($row);
            $coverUri = (string)($row['cover_uri'] ?? '');
            $coverDomain = (string)($row['storage_domain'] ?? '');
            $row['cover_url'] = ($coverUri === '' || $coverDomain === '')
                ? ''
                : SmartEditImsConfigService::joinUrl($coverDomain, $coverUri);
            $row['status_text'] = self::statusText((string)($row['status'] ?? ''));
            $row['items'] = $itemMap[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    /**
     * 状态码 -> 中文文案。列表与详情共用，避免两边不一致。
     *
     * @param string $status 状态码
     */
    public static function statusText(string $status): string
    {
        return match ($status) {
            SmartEditTask::STATUS_PENDING => '等待提交',
            SmartEditTask::STATUS_SUBMITTING => '提交中',
            SmartEditTask::STATUS_PROCESSING => '剪辑中',
            SmartEditTask::STATUS_SUCCESS => '已完成',
            SmartEditTask::STATUS_FAILED => '失败',
            SmartEditTask::STATUS_CANCELED => '已取消',
            default => $status,
        };
    }

    public static function outputUrl(array $row): string
    {
        $uri = (string)($row['output_uri'] ?? '');
        if ($uri === '') {
            return '';
        }
        // 用任务创建时落库的域名拼地址，不走任何其他存储配置，
        // 也避免之后改配置导致历史成片链接失效。
        $domain = (string)($row['storage_domain'] ?? '');
        if ($domain === '') {
            return '';
        }
        return SmartEditImsConfigService::joinUrl($domain, $uri);
    }

    public static function formatTask(array $row): array
    {
        $row['output_url'] = self::outputUrl($row);
        // 封面同样按任务落库的域名拼，未设置时为空串。
        $coverUri = (string)($row['cover_uri'] ?? '');
        $domain = (string)($row['storage_domain'] ?? '');
        $row['cover_url'] = ($coverUri === '' || $domain === '')
            ? ''
            : SmartEditImsConfigService::joinUrl($domain, $coverUri);
        $row['status_text'] = self::statusText((string)($row['status'] ?? ''));
        return $row;
    }

    // ------------------------------------------------------------------

    private static function validate(int $tenantId, array $params): array
    {
        $editType = trim((string)($params['edit_type'] ?? SmartEditTask::TYPE_MERGE));
        if ($editType !== SmartEditTask::TYPE_MERGE) {
            throw new Exception('暂不支持的剪辑类型：' . $editType);
        }
        $clips = $params['clips'] ?? [];
        if (!is_array($clips) || count($clips) < 2) {
            throw new Exception('至少选择 2 段视频才能合并');
        }
        if (count($clips) > self::MAX_CLIPS) {
            throw new Exception('一次最多合并 ' . self::MAX_CLIPS . ' 段视频');
        }
        $normalized = [];
        foreach (array_values($clips) as $clip) {
            if (!is_array($clip) || trim((string)($clip['uri'] ?? '')) === '') {
                throw new Exception('素材地址不能为空');
            }
            $in = (float)($clip['in'] ?? 0);
            $out = (float)($clip['out'] ?? 0);
            if ($in < 0 || $out < 0) {
                throw new Exception('截取区间不能为负数');
            }
            if ($out > 0 && $out <= $in) {
                throw new Exception('截取结束时间必须大于开始时间');
            }
            $normalized[] = ['uri' => (string)$clip['uri'], 'in' => $in, 'out' => $out];
        }
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            $title = '多视频合并';
        }
        // 封面可选：只接受素材库中的图片，且与正片一样要过归属校验。
        $coverUri = trim((string)($params['cover_uri'] ?? ''));
        // 输出比例：未传则用默认值；传了但不在支持列表里直接报错，避免静默产出非预期尺寸。
        $aspectRatio = trim((string)($params['aspect_ratio'] ?? ''));
        if ($aspectRatio === '') {
            $aspectRatio = self::DEFAULT_ASPECT_RATIO;
        } elseif (!isset(self::OUTPUT_SIZES[$aspectRatio])) {
            throw new Exception('不支持的视频比例：' . $aspectRatio);
        }
        // 标题文案：可选，只限制长度，位置与字号由 Timeline 构建统一控制。
        $titleText = trim((string)($params['title_text'] ?? ''));
        if (mb_strlen($titleText) > self::MAX_TITLE_TEXT_LENGTH) {
            throw new Exception('标题文案不能超过 ' . self::MAX_TITLE_TEXT_LENGTH . ' 个字');
        }
        return [$editType, mb_substr($title, 0, 100), $normalized, $coverUri, $aspectRatio, $titleText];
    }

    private static function buildTimeline(string $editType, array $items, string $coverUrl = '', string $titleText = ''): array
    {
        return match ($editType) {
            SmartEditTask::TYPE_MERGE => SmartEditTimelineBuilder::buildMerge($items, $coverUrl, $titleText),
            default => throw new Exception('暂不支持的剪辑类型：' . $editType),
        };
    }

    private static function markSuccess(SmartEditTask $task, array $job): void
    {
        $uri = (string)$task['output_target'];
        $duration = (float)$job['duration'];
        $inputDuration = (float)$task['input_duration'];
        // 有片段没填 out（用完整时长）时，提交阶段的 input_duration 会偏小甚至为 0；
        // 合并是顺序拼接，成片时长就是素材总时长，这里用成片时长回填，供后续计费使用。
        if ($duration > 0 && $duration > $inputDuration) {
            $inputDuration = $duration;
        }
        $task->save([
            'status' => SmartEditTask::STATUS_SUCCESS,
            'output_uri' => $uri,
            'duration' => $duration > 0 ? $duration : $inputDuration,
            'input_duration' => $inputDuration,
            'error_code' => '',
            'error_message' => '',
            'poll_attempts' => (int)$task['poll_attempts'] + 1,
            'finish_time' => time(),
            'update_time' => time(),
        ]);

        // 成片直接写在配置的 Bucket 上，这里补一条文件记录，和普通上传保持一致。
        $exists = TenantFile::where([
            'tenant_id' => (int)$task['tenant_id'],
            'uri' => $uri,
        ])->findOrEmpty();
        if ($exists->isEmpty()) {
            TenantFile::create([
                'tenant_id' => (int)$task['tenant_id'],
                'cid' => 0,
                'type' => FileEnum::VIDEO_TYPE,
                'name' => basename($uri),
                'uri' => $uri,
                'storage_scope' => (string)$task['storage_scope'],
                'storage_engine' => (string)$task['storage_engine'],
                'storage_domain' => (string)$task['storage_domain'],
                'source' => FileEnum::SOURCE_USER,
                'source_id' => (int)$task['user_id'],
                'create_time' => time(),
            ]);
        }
    }

    private static function refreshIfStale(SmartEditTask $task): void
    {
        if (!in_array((string)$task['status'], SmartEditTask::RUNNING_STATUSES, true)) {
            return;
        }
        if ((int)$task['next_poll_time'] > time()) {
            return;
        }
        try {
            self::refresh((int)$task['id']);
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 详情刷新失败 task=' . $task['task_no'] . ' ' . $e->getMessage());
        }
    }

    private static function backoff(int $attempt): int
    {
        return (int)min(60, max(5, 5 * (int)ceil($attempt / 10)));
    }

    private static function failPayload(\Throwable $e): array
    {
        $code = $e instanceof ImsApiException ? ($e->errorCode !== '' ? $e->errorCode : 'IMS_ERROR') : 'SUBMIT_FAILED';
        return [
            'status' => SmartEditTask::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => mb_substr($e->getMessage(), 0, 480),
            'finish_time' => time(),
            'update_time' => time(),
        ];
    }

    private static function newTaskNo(): string
    {
        return 'SE' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}