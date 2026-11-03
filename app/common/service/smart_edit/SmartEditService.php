<?php

namespace app\common\service\smart_edit;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\model\smart_edit\SmartEditTask;
use app\common\model\smart_edit\SmartEditTaskItem;
use app\common\service\FileService;
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

    /**
     * 创建并提交任务（同步提交，合成本身异步）。
     */
    public static function generate(int $tenantId, int $userId, array $params): array
    {
        [$editType, $title, $clips] = self::validate($tenantId, $params);
        $oss = SmartEditMaterialService::ossConfig($tenantId);
        $taskNo = self::newTaskNo();

        $items = [];
        foreach ($clips as $index => $clip) {
            $items[] = [
                'media_url' => SmartEditMaterialService::mediaUrl($tenantId, (string)$clip['uri']),
                'in' => (float)($clip['in'] ?? 0),
                'out' => (float)($clip['out'] ?? 0),
                'raw' => $clip,
            ];
        }

        $output = SmartEditMaterialService::outputTarget($tenantId, $taskNo);
        $timeline = self::buildTimeline($editType, $items);

        $task = SmartEditTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_no' => $taskNo,
            'edit_type' => $editType,
            'title' => $title,
            'params_json' => ['edit_type' => $editType, 'title' => $title, 'clips' => $clips],
            'timeline_json' => $timeline,
            'provider' => 'aliyun_ims',
            'region' => $oss['region'],
            'provider_job_id' => '',
            'output_target' => $output['uri'],
            'output_uri' => '',
            'storage_scope' => $oss['scope'],
            'storage_engine' => 'aliyun',
            'storage_domain' => $oss['domain'],
            'input_duration' => array_sum(array_map(
                static fn(array $item) => max(0, (float)$item['out'] - (float)$item['in']),
                $items
            )),
            'status' => SmartEditTask::STATUS_SUBMITTING,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);

        $rows = [];
        foreach ($clips as $index => $clip) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$task['id'],
                'item_type' => SmartEditTaskItem::TYPE_VIDEO,
                'sort' => $index,
                'source_uri' => (string)$clip['uri'],
                'storage_scope' => $oss['scope'],
                'storage_engine' => 'aliyun',
                'storage_domain' => $oss['domain'],
                'in_sec' => (float)($clip['in'] ?? 0),
                'out_sec' => (float)($clip['out'] ?? 0),
                'duration' => max(0, (float)($clip['out'] ?? 0) - (float)($clip['in'] ?? 0)),
                'raw_meta' => json_encode(['media_url' => $items[$index]['media_url']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ];
        }
        if ($rows !== []) {
            (new SmartEditTaskItem())->insertAll($rows);
        }

        try {
            $client = new ImsClient($oss['access_key'], $oss['secret_key'], $oss['region']);
            $jobId = $client->submit($timeline, $output['url']);
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
            $oss = SmartEditMaterialService::ossConfig((int)$task['tenant_id']);
            $client = new ImsClient($oss['access_key'], $oss['secret_key'], (string)$task['region'] ?: $oss['region']);
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
        $oss = SmartEditMaterialService::ossConfig((int)$task['tenant_id']);
        $output = SmartEditMaterialService::outputTarget((int)$task['tenant_id'], (string)$task['task_no']);
        $timeline = (array)$task['timeline_json'];
        if ($timeline === []) {
            throw new Exception('任务参数缺失，无法重试');
        }
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
            $client = new ImsClient($oss['access_key'], $oss['secret_key'], $oss['region']);
            $jobId = $client->submit($timeline, $output['url']);
            $task->save([
                'provider_job_id' => $jobId,
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
                $data['source_url'] = FileService::getFileUrlByStorage(
                    (string)$data['source_uri'],
                    (string)($data['storage_scope'] ?? ''),
                    (string)($data['storage_engine'] ?? ''),
                    (string)($data['storage_domain'] ?? '')
                );
                $itemMap[(int)$data['task_id']][] = $data;
            }
        }
        foreach ($rows as &$row) {
            $row['output_url'] = self::outputUrl($row);
            $row['items'] = $itemMap[(int)$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    public static function outputUrl(array $row): string
    {
        $uri = (string)($row['output_uri'] ?? '');
        if ($uri === '') {
            return '';
        }
        return FileService::getFileUrlByStorage(
            $uri,
            (string)($row['storage_scope'] ?? ''),
            (string)($row['storage_engine'] ?? ''),
            (string)($row['storage_domain'] ?? '')
        );
    }

    public static function formatTask(array $row): array
    {
        $row['output_url'] = self::outputUrl($row);
        $row['status_text'] = match ((string)$row['status']) {
            SmartEditTask::STATUS_PENDING => '等待提交',
            SmartEditTask::STATUS_SUBMITTING => '提交中',
            SmartEditTask::STATUS_PROCESSING => '剪辑中',
            SmartEditTask::STATUS_SUCCESS => '已完成',
            SmartEditTask::STATUS_FAILED => '失败',
            SmartEditTask::STATUS_CANCELED => '已取消',
            default => (string)$row['status'],
        };
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
        return [$editType, mb_substr($title, 0, 100), $normalized];
    }

    private static function buildTimeline(string $editType, array $items): array
    {
        return match ($editType) {
            SmartEditTask::TYPE_MERGE => SmartEditTimelineBuilder::buildMerge($items),
            default => throw new Exception('暂不支持的剪辑类型：' . $editType),
        };
    }

    private static function markSuccess(SmartEditTask $task, array $job): void
    {
        $uri = (string)$task['output_target'];
        $duration = (float)$job['duration'];
        $task->save([
            'status' => SmartEditTask::STATUS_SUCCESS,
            'output_uri' => $uri,
            'duration' => $duration > 0 ? $duration : (float)$task['input_duration'],
            'error_code' => '',
            'error_message' => '',
            'poll_attempts' => (int)$task['poll_attempts'] + 1,
            'finish_time' => time(),
            'update_time' => time(),
        ]);

        // 成片直接写在租户 OSS 上，这里补一条文件记录，和普通上传保持一致。
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