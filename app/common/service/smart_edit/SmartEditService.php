<?php

namespace app\common\service\smart_edit;

use app\common\enum\FileEnum;
use app\common\job\SmartEditSubmitJob;
use app\common\model\file\TenantFile;
use app\common\model\smart_edit\SmartEditTask;
use app\common\model\smart_edit\SmartEditTaskItem;
use app\common\service\sticker\StickerService;
use Exception;
use think\facade\Log;

/**
 * 智能剪辑业务：建任务 → 构建 Timeline → 投递 MNS 提交 IMS → 轮询 → 落地成片。
 *
 * 提交走 MNS job，接口只做参数校验、落库和投递，不在请求里等供应商。
 * 轮询仍由 smart-edit:worker 与详情 / 列表读路径同步完成。
 *
 * 第一轮不收费：tenant_cost_points / user_charge_points 恒为 0，也不建 consumption 记录。
 * 表里的计费字段与 app_task_id / consumption_id 已预留，后续开收时只改本类内部。
 */
class SmartEditService
{
    public const MAX_CLIPS = 20;
    public const MAX_POLL_ATTEMPTS = 240;

    /** 单组内最多贴纸数（对齐竞品） */
    public const MAX_STICKERS_PER_GROUP = 2;

    /** 最多贴纸组数；笛卡尔积成片按 combo 序号循环取组 */
    public const MAX_STICKER_GROUPS = 50;

    /** @deprecated 兼容旧常量名，等同每组上限 */
    public const MAX_STICKERS = 2;

    /** 背景音乐数量上限 */
    public const MAX_AUDIOS = 3;

    /** 标题文案最大字数 */
    public const MAX_TITLE_TEXT_LENGTH = 30;

    /** 单组内最多叠加的标题条数 */
    public const MAX_TITLES = 10;

    /** 最多标题组数；笛卡尔积成片按 combo 序号循环取组 */
    public const MAX_TITLE_GROUPS = 50;

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
        $validated = self::validate($tenantId, $params);
        [$editType, $title, $clips, $coverUri, $aspectRatio, $titleConfig] = $validated;

        $shared = [
            'edit_type' => $editType,
            'title' => $title,
            'cover_uri' => $coverUri,
            'aspect_ratio' => $aspectRatio,
            'title_overlay' => $titleConfig,
            'stickers' => $validated[6],
            'audios' => $validated[7],
        ];
        $task = self::createTask($tenantId, $userId, $shared, $clips);

        // 提交动作交给 MNS：接口不再等供应商返回。
        // 投递失败时任务停在 submitting，用户可重试；不在这里判失败，避免消息已发出又被覆盖。
        try {
            SmartEditSubmitJob::dispatch(['task_ids' => [(int)$task['id']]], 0, '', $tenantId);
            Log::channel('mns')->info('[smart_edit] submit dispatched task=' . (string)$task['task_no'] . ' id=' . (int)$task['id']);
        } catch (\Throwable $e) {
            Log::channel('mns')->error('[smart_edit] 投递失败 task=' . (string)$task['task_no'] . ' ' . $e->getMessage());
            $task->save(self::failPayload($e));
        }

        return self::formatTask($task->refresh()->toArray());
    }

    /**
     * 只建任务：解析素材地址 → 构建 Timeline → 落任务行与明细。
     *
     * 不投递 MNS，调用方决定何时提交。单任务与镜头组共用这一条建任务路径。
     *
     * @param int    $tenantId   租户 ID
     * @param int    $userId     用户 ID
     * @param array  $shared     共用配置（edit_type/title/cover_uri/aspect_ratio/title_overlay/stickers/audios）
     * @param array  $clips      片段列表（uri/in/out/mute）
     * @param string $batchNo    所属组号，空串表示单任务
     * @param int    $comboIndex 组内组合序号，从 1 起
     * @return SmartEditTask
     */
    public static function createTask(
        int $tenantId,
        int $userId,
        array $shared,
        array $clips,
        string $batchNo = '',
        int $comboIndex = 0
    ): SmartEditTask {
        $editType = (string)($shared['edit_type'] ?? SmartEditTask::TYPE_MERGE);
        $title = (string)($shared['title'] ?? '多视频合并');
        $coverUri = (string)($shared['cover_uri'] ?? '');
        $aspectRatio = (string)($shared['aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO);
        $titleConfig = (array)($shared['title_overlay'] ?? []);
        $stickerInputs = (array)($shared['stickers'] ?? []);
        $audioInputs = (array)($shared['audios'] ?? []);

        $config = SmartEditImsConfigService::require($tenantId);
        $taskNo = self::newTaskNo();
        [, $outputHeight] = self::resolveOutputSize($aspectRatio);

        $items = [];
        foreach ($clips as $clip) {
            $items[] = [
                'media_url' => SmartEditMaterialService::mediaUrl($config, $tenantId, (string)$clip['uri']),
                'in' => (float)($clip['in'] ?? 0),
                'out' => (float)($clip['out'] ?? 0),
                // 消除原声：交给 Timeline 构建给该片段加 Volume=0 效果
                'mute' => !empty($clip['mute']),
                'raw' => $clip,
            ];
        }

        // 封面与正片素材走同一套归属校验与签名，确保 IMS 能拉到。
        $coverUrl = $coverUri === ''
            ? ''
            : SmartEditMaterialService::mediaUrl($config, $tenantId, $coverUri);

        // 贴纸与背景音乐同样要过归属校验，避免任意 URL 被塞进成片。
        // 贴纸先查贴纸表；查不到再回退到素材库校验，兼容「从素材库选图片当贴纸」的用法。
        $stickers = [];
        foreach ($stickerInputs as $input) {
            $uri = (string)$input['uri'];
            $sticker = StickerService::resolveForSmartEdit($uri);
            if ($sticker !== null) {
                $mediaUrl = $sticker['url'] !== ''
                    ? $sticker['url']
                    : SmartEditImsConfigService::objectUrl($config, $sticker['uri']);
            } else {
                $mediaUrl = SmartEditMaterialService::mediaUrl($config, $tenantId, $uri);
            }
            $stickers[] = ['media_url' => $mediaUrl] + array_diff_key($input, ['uri' => true]);
        }
        $audios = [];
        foreach ($audioInputs as $input) {
            $mediaUrl = SmartEditMaterialService::mediaUrl($config, $tenantId, (string)$input['uri']);
            $audios[] = ['media_url' => $mediaUrl] + array_diff_key($input, ['uri' => true]);
        }

        $output = SmartEditMaterialService::outputTarget($config, $taskNo);
        $titleConfig = self::resolveTitleFonts($config, $titleConfig);

        // 贴纸是图片，没有自身时长；未指定区间时铺满正片，跨出正片的也裁到正片以内，
        // 否则 IMS 会按贴纸轨的结束点决定成片时长，正片之后留下一段黑屏。
        // 没有贴纸时不推算也不校验，保持正片按素材自身时长出片的旧路径。
        if ($stickers !== []) {
            $stickers = self::alignStickerTimeline($stickers, self::mainTrackDuration($items, $coverUrl !== ''));
        }

        $timeline = self::buildTimeline($editType, $items, $coverUrl, $titleConfig, $outputHeight, $stickers, $audios);

        $task = SmartEditTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_no' => $taskNo,
            'edit_type' => $editType,
            'title' => $title,
            'batch_no' => $batchNo,
            'combo_index' => $comboIndex,
            'params_json' => [
                'edit_type' => $editType,
                'title' => $title,
                'clips' => $clips,
                'cover_uri' => $coverUri,
                'aspect_ratio' => $aspectRatio,
                'title_text' => implode(' / ', array_column($titleConfig, 'text')),
                'title_overlay' => $titleConfig,
                'title_group_index' => (int)($shared['title_group_index'] ?? 0),
                'sticker_group_index' => (int)($shared['sticker_group_index'] ?? 0),
                'stickers' => $stickerInputs,
                'audios' => $audioInputs,
                'batch_no' => $batchNo,
                'combo_index' => $comboIndex,
            ],
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
            // 成片成功后 markSuccess() 会用 IMS 返回的成片时长回填。
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
        // 贴纸与音频也落明细，任务详情能直接回显用户当时用了什么素材。
        $extraSort = $sortBase + count($clips);
        foreach ($stickers as $index => $sticker) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$task['id'],
                'item_type' => SmartEditTaskItem::TYPE_STICKER,
                'sort' => $extraSort + $index,
                'source_uri' => (string)($stickerInputs[$index]['uri'] ?? ''),
                'storage_scope' => 'tenant',
                'storage_engine' => 'aliyun',
                'storage_domain' => $config['domain'],
                'in_sec' => (float)($sticker['TimelineIn'] ?? 0),
                'out_sec' => (float)($sticker['TimelineOut'] ?? 0),
                'duration' => max(0, (float)($sticker['TimelineOut'] ?? 0) - (float)($sticker['TimelineIn'] ?? 0)),
                'raw_meta' => json_encode($sticker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ];
        }
        $extraSort += count($stickers);
        foreach ($audios as $index => $audio) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$task['id'],
                'item_type' => SmartEditTaskItem::TYPE_AUDIO,
                'sort' => $extraSort + $index,
                'source_uri' => (string)($audioInputs[$index]['uri'] ?? ''),
                'storage_scope' => 'tenant',
                'storage_engine' => 'aliyun',
                'storage_domain' => $config['domain'],
                'in_sec' => (float)($audio['In'] ?? 0),
                'out_sec' => (float)($audio['Out'] ?? 0),
                'duration' => max(0, (float)($audio['Out'] ?? 0) - (float)($audio['In'] ?? 0)),
                'raw_meta' => json_encode($audio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => time(),
            ];
        }
        if ($rows !== []) {
            // 走查询构造器的批量插入：不触发模型事件，tenant_id/user_id/create_time 已在行内显式写入。
            SmartEditTaskItem::insertAll($rows);
        }

        return $task;
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
        $config = SmartEditImsConfigService::require((int)$task['tenant_id']);
        $output = SmartEditMaterialService::outputTarget($config, (string)$task['task_no']);
        if ((array)$task['timeline_json'] === []) {
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
        // 与首次提交同一条链路：投递给 MNS，不在请求里等 IMS。
        $tenantId = (int)$task['tenant_id'];
        try {
            SmartEditSubmitJob::dispatch(['task_ids' => [(int)$task['id']]], 0, '', $tenantId);
            Log::channel('mns')->info('[smart_edit] retry dispatched task=' . $task['task_no'] . ' id=' . (int)$task['id']);
        } catch (\Throwable $e) {
            Log::channel('mns')->error('[smart_edit] 重试投递失败 task=' . $task['task_no'] . ' ' . $e->getMessage());
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
            $normalized[] = [
                'uri' => (string)$clip['uri'],
                'in' => $in,
                'out' => $out,
                // 消除原声：前端取消勾选「保留原声」时传 mute=true
                'mute' => !empty($clip['mute']),
            ];
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
        // 标题组：文案与排版全部由前端决定，这里只校验取值范围。
        $titleConfig = self::normalizeTitles($params);
        $stickers = self::normalizeStickers($params['stickers'] ?? []);
        $audios = self::normalizeAudios($params['audios'] ?? $params['audios_tracks'] ?? []);
        return [$editType, mb_substr($title, 0, 100), $normalized, $coverUri, $aspectRatio, $titleConfig, $stickers, $audios];
    }

    /**
     * 镜头组共用配置归一化：只处理整组共用的部分，片段由调用方按组合传入。
     *
     * 与 validate() 共用同一套子方法，保证组和单任务对同名字段的理解一致。
     *
     * @param array $params 前端参数
     * @return array{edit_type: string, title: string, cover_uri: string, aspect_ratio: string, title_overlay: array, title_groups: array, stickers: array, sticker_groups: array, audios: array}
     */
    public static function normalizeSharedForGroup(array $params): array
    {
        $editType = trim((string)($params['edit_type'] ?? SmartEditTask::TYPE_MERGE));
        if ($editType !== SmartEditTask::TYPE_MERGE) {
            throw new Exception('暂不支持的剪辑类型：' . $editType);
        }
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            $title = '镜头组';
        }
        $coverUri = trim((string)($params['cover_uri'] ?? ''));
        $aspectRatio = trim((string)($params['aspect_ratio'] ?? ''));
        if ($aspectRatio === '') {
            $aspectRatio = self::DEFAULT_ASPECT_RATIO;
        } elseif (!isset(self::OUTPUT_SIZES[$aspectRatio])) {
            throw new Exception('不支持的视频比例：' . $aspectRatio);
        }

        $titleGroups = self::normalizeTitleGroups($params);
        $stickerGroups = self::normalizeStickerGroups($params);
        return [
            'edit_type' => $editType,
            'title' => mb_substr($title, 0, 100),
            'cover_uri' => $coverUri,
            'aspect_ratio' => $aspectRatio,
            // 默认落第一组，展开时再按 combo 循环替换
            'title_overlay' => $titleGroups[0] ?? [],
            'title_groups' => $titleGroups,
            'stickers' => $stickerGroups[0] ?? [],
            'sticker_groups' => $stickerGroups,
            'audios' => self::normalizeAudios($params['audios'] ?? $params['audios_tracks'] ?? []),
        ];
    }

    /**
     * 按组合序号循环取标题组 / 贴纸组，写回供建任务使用。
     *
     * combo_index 从 1 起：C1→G0，C2→G1，…，用完再从头。
     * 标题组与贴纸组各自独立循环。
     *
     * @param array $shared     normalizeSharedForGroup 的结果
     * @param int   $comboIndex 组合序号（从 1 起）
     */
    public static function sharedForCombo(array $shared, int $comboIndex): array
    {
        $titleGroups = $shared['title_groups'] ?? null;
        if (is_array($titleGroups) && $titleGroups !== []) {
            $count = count($titleGroups);
            $index = $comboIndex > 0 ? ($comboIndex - 1) % $count : 0;
            $picked = $titleGroups[$index] ?? [];
            $shared['title_overlay'] = is_array($picked) ? $picked : [];
            $shared['title_group_index'] = $index;
        }

        $stickerGroups = $shared['sticker_groups'] ?? null;
        if (is_array($stickerGroups) && $stickerGroups !== []) {
            $count = count($stickerGroups);
            $index = $comboIndex > 0 ? ($comboIndex - 1) % $count : 0;
            $picked = $stickerGroups[$index] ?? [];
            $shared['stickers'] = is_array($picked) ? $picked : [];
            $shared['sticker_group_index'] = $index;
        }

        return $shared;
    }

    /**
     * 建组前的素材预检：封面 / 贴纸 / 背景音乐必须归属当前租户且能被 IMS 拉到。
     *
     * 这些素材整组共用，先在这里校验一次，避免上千条任务逐条失败。
     *
     * @param int   $tenantId 租户 ID
     * @param array $shared   normalizeSharedForGroup() 的结果
     */
    public static function assertSharedResolvable(int $tenantId, array $shared): void
    {
        $config = SmartEditImsConfigService::require($tenantId);

        $coverUri = (string)($shared['cover_uri'] ?? '');
        if ($coverUri !== '') {
            SmartEditMaterialService::mediaUrl($config, $tenantId, $coverUri);
        }

        $stickerList = [];
        $stickerGroups = $shared['sticker_groups'] ?? null;
        if (is_array($stickerGroups) && $stickerGroups !== []) {
            foreach ($stickerGroups as $group) {
                if (is_array($group)) {
                    foreach ($group as $sticker) {
                        $stickerList[] = $sticker;
                    }
                }
            }
        } else {
            $stickerList = (array)($shared['stickers'] ?? []);
        }
        foreach ($stickerList as $sticker) {
            if (!is_array($sticker)) {
                continue;
            }
            $uri = (string)($sticker['uri'] ?? '');
            if ($uri === '') {
                continue;
            }
            if (StickerService::resolveForSmartEdit($uri) === null) {
                SmartEditMaterialService::mediaUrl($config, $tenantId, $uri);
            }
        }

        foreach ((array)($shared['audios'] ?? []) as $audio) {
            $uri = (string)($audio['uri'] ?? '');
            if ($uri !== '') {
                SmartEditMaterialService::mediaUrl($config, $tenantId, $uri);
            }
        }
    }

    /**
     * 多组标题归一化。
     *
     * - title_groups: [[{text,...}, ...], ...] 多组，成片按 combo 循环取组
     * - 仅 title_overlay / title_text：视为单组（兼容旧协议）
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function normalizeTitleGroups(array $params): array
    {
        $raw = $params['title_groups'] ?? null;
        if (is_array($raw) && $raw !== [] && array_is_list($raw)) {
            if (count($raw) > self::MAX_TITLE_GROUPS) {
                throw new Exception('一次最多添加 ' . self::MAX_TITLE_GROUPS . ' 组标题');
            }
            $groups = [];
            foreach ($raw as $index => $group) {
                if (!is_array($group)) {
                    continue;
                }
                $titles = self::normalizeTitles(['title_overlay' => $group]);
                if ($titles !== []) {
                    $groups[] = $titles;
                }
            }
            return $groups;
        }

        $single = self::normalizeTitles($params);
        return $single === [] ? [] : [$single];
    }

    /**
     * 单组内多条标题归一化（叠到同一条成片）。
     *
     * 兼容三种入参：
     * - title_overlay: [{text, ...}, ...] 标题列表
     * - title_overlay: {text, ...} 单条（旧协议）
     * - title_text：补成单条
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeTitles(array $params): array
    {
        $raw = $params['title_overlay'] ?? null;
        $items = [];
        if (is_array($raw) && $raw !== []) {
            if (array_is_list($raw) && isset($raw[0]) && is_array($raw[0])) {
                $items = $raw;
            } elseif (isset($raw['text']) || isset($raw['font_key']) || isset($raw['font_size'])) {
                $items = [$raw];
            }
        } elseif (trim((string)($params['title_text'] ?? '')) !== '') {
            $items = [['text' => (string)$params['title_text']]];
        }

        if (count($items) > self::MAX_TITLES) {
            throw new Exception('一组最多添加 ' . self::MAX_TITLES . ' 条标题');
        }

        $result = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = self::normalizeTitleItem($item, $index + 1);
            if ($normalized !== []) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    /**
     * 单条标题归一化。文案为空则跳过（返回 []）。
     *
     * @param array $config 单条标题配置
     * @param int   $index  1-based，用于报错定位
     * @return array<string, mixed>
     */
    private static function normalizeTitleItem(array $config, int $index = 1): array
    {
        $text = trim((string)($config['text'] ?? ''));
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) > self::MAX_TITLE_TEXT_LENGTH) {
            throw new Exception('标题' . $index . '文案不能超过 ' . self::MAX_TITLE_TEXT_LENGTH . ' 个字');
        }
        $result = ['text' => $text];

        $start = self::numberOrNull($config['timeline_in'] ?? $config['start'] ?? null);
        $end = self::numberOrNull($config['timeline_out'] ?? $config['end'] ?? null);
        if ($start !== null || $end !== null) {
            $start = max(0, (float)($start ?? 0));
            $end = $end === null ? $start + SmartEditTimelineBuilder::TITLE_SECONDS : (float)$end;
            if ($end <= $start) {
                throw new Exception('标题' . $index . '结束时间必须大于开始时间');
            }
            $result['timeline_in'] = $start;
            $result['timeline_out'] = $end;
        } elseif (isset($config['duration']) && self::numberOrNull($config['duration']) !== null) {
            $result['timeline_in'] = 0;
            $result['timeline_out'] = max(0.1, (float)$config['duration']);
        }

        $fontSize = self::numberOrNull($config['font_size'] ?? null);
        if ($fontSize !== null) {
            if ($fontSize < 8 || $fontSize > 400) {
                throw new Exception('标题' . $index . '字号需在 8 ~ 400 之间');
            }
            $result['font_size'] = (int)round($fontSize);
        }

        $color = trim((string)($config['font_color'] ?? $config['color'] ?? ''));
        if ($color !== '') {
            if (!preg_match('/^#([0-9a-fA-F]{6})$/', $color)) {
                throw new Exception('标题' . $index . '颜色需为 #RRGGBB 格式');
            }
            $result['font_color'] = $color;
        }

        $y = self::numberOrNull($config['y'] ?? null);
        if ($y !== null) {
            if ($y < 0) {
                throw new Exception('标题' . $index . '高度不能为负数');
            }
            $result['y'] = $y;
        }

        $alignment = trim((string)($config['alignment'] ?? ''));
        if ($alignment !== '') {
            $allowed = ['TopLeft', 'TopCenter', 'TopRight', 'Left', 'Center', 'Right',
                'CenterLeft', 'CenterCenter', 'CenterRight', 'BottomLeft', 'BottomCenter', 'BottomRight'];
            if (!in_array($alignment, $allowed, true)) {
                throw new Exception('标题' . $index . '不支持的对齐方式：' . $alignment);
            }
            $result['alignment'] = $alignment;
        }

        $fontKey = trim((string)($config['font_key'] ?? ''));
        if ($fontKey !== '') {
            if (!SmartEditFontService::has($fontKey)) {
                throw new Exception('标题' . $index . '不支持的字体：' . $fontKey);
            }
            $result['font_key'] = $fontKey;
        }
        $font = trim((string)($config['font'] ?? ''));
        if ($font !== '' && $fontKey === '') {
            $result['font'] = mb_substr($font, 0, 80);
        }
        $fontUrl = trim((string)($config['font_url'] ?? $config['fontUrl'] ?? ''));
        if ($fontUrl !== '' && $fontKey === '') {
            if (!preg_match('#^https?://#i', $fontUrl)) {
                throw new Exception('标题' . $index . '字体地址必须是完整的 http(s) 地址');
            }
            $result['font_url'] = $fontUrl;
        }

        return $result;
    }

    /** 转成数字；非数字或空值统一返回 null，便于区分「没传」和 0。 */
    private static function numberOrNull($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    /**
     * 标题组：逐条把 font_key 解析成 FontURL。
     *
     * @param array $config  阿里云配置
     * @param array $titles  归一化后的标题列表
     * @return array<int, array<string, mixed>>
     */
    private static function resolveTitleFonts(array $config, array $titles): array
    {
        $result = [];
        foreach ($titles as $title) {
            if (!is_array($title)) {
                continue;
            }
            $result[] = self::resolveTitleFont($config, $title);
        }
        return $result;
    }

    /**
     * 把单条标题的字体 code 解析成 IMS 能直接拉取的 FontURL。
     *
     * @param array $config 阿里云配置
     * @param array $title  归一化后的单条标题
     */
    private static function resolveTitleFont(array $config, array $title): array
    {
        $key = (string)($title['font_key'] ?? '');
        if ($key === '') {
            return $title;
        }
        unset($title['font_key']);

        $fontUrl = SmartEditFontService::prepare($config, $key);
        if ($fontUrl !== '') {
            $title['font_url'] = $fontUrl;
            return $title;
        }
        $alias = SmartEditFontService::aliasOf($key);
        if ($alias !== '') {
            $title['font'] = $alias;
        }
        return $title;
    }

    /**
     * 多组贴纸归一化。
     *
     * - sticker_groups: [[{uri,...}, ...], ...] 多组，成片按 combo 循环取组
     * - 仅 stickers：视为单组（兼容旧协议）
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function normalizeStickerGroups(array $params): array
    {
        $raw = $params['sticker_groups'] ?? null;
        if (is_array($raw) && $raw !== [] && array_is_list($raw)) {
            if (count($raw) > self::MAX_STICKER_GROUPS) {
                throw new Exception('一次最多添加 ' . self::MAX_STICKER_GROUPS . ' 组贴纸');
            }
            $groups = [];
            foreach ($raw as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $stickers = self::normalizeStickers($group);
                if ($stickers !== []) {
                    $groups[] = $stickers;
                }
            }
            return $groups;
        }

        $single = self::normalizeStickers($params['stickers'] ?? []);
        return $single === [] ? [] : [$single];
    }

    /**
     * 贴纸片段归一化。
     *
     * 只负责「取值是否合法」，不替前端决定位置：
     * X/Y/Width/Height/Opacity 传什么就用什么，缺省则交给 IMS 默认值。
     * 时间轴区间可省略，省略即铺满整段成片。
     */
    private static function normalizeStickers($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        if (count($raw) > self::MAX_STICKERS_PER_GROUP) {
            throw new Exception('一组最多添加 ' . self::MAX_STICKERS_PER_GROUP . ' 个贴纸');
        }
        // 归一化后的键名直接用 IMS 字段名，再拼到 Timeline 时不必二次映射。
        // 前端可以传小写（x/y/width）也可以直接传 IMS 风格的大写（X/Y/Width），
        // 两种写法都要认，否则位置参数会被静默丢掉。
        $map = [
            'x' => 'X',
            'y' => 'Y',
            'width' => 'Width',
            'height' => 'Height',
            'opacity' => 'Opacity',
            'timeline_in' => 'TimelineIn',
            'timeline_out' => 'TimelineOut',
        ];
        $rows = [];
        foreach (array_values($raw) as $item) {
            if (!is_array($item)) {
                throw new Exception('贴纸参数格式错误');
            }
            $uri = trim((string)($item['uri'] ?? ''));
            if ($uri === '') {
                throw new Exception('贴纸素材不能为空');
            }
            $row = ['uri' => $uri];
            foreach ($map as $key => $field) {
                // 小写键与 IMS 风格大写键都接受，先看小写再看大写
                $value = $item[$key] ?? $item[$field] ?? null;
                if ($value === '' || $value === null) {
                    continue;
                }
                if (!is_numeric($value)) {
                    throw new Exception('贴纸参数 ' . $key . ' 必须是数字');
                }
                $row[$field] = (float)$value;
            }
            // 贴纸必须有明确区间，否则 IMS 找不到可渲染区间会直接忽略这个片段。
            // 但时长不在这里猜：结束时间留空时由 alignStickerTimeline() 铺满正片，
            // 写死一个固定秒数会把成片按贴纸轨的结束点拉长，正片之后就是黑屏。
            if (!isset($row['TimelineIn'])) {
                $row['TimelineIn'] = 0;
            }
            if (isset($row['TimelineOut']) && $row['TimelineOut'] <= $row['TimelineIn']) {
                throw new Exception('贴纸结束时间必须大于开始时间');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 把贴纸区间对齐到正片时长。
     *
     * 贴纸是图片，没有自身时长，IMS 必须拿到明确的 TimelineOut 与 Duration 才会渲染；
     * 而 IMS 的成片时长取各轨最晚结束点，贴纸一旦越过正片，多出来的部分就是黑屏。
     * 所以未指定结束时间时铺满正片，指定了也裁到正片以内。
     *
     * @param array $stickers     normalizeStickers 的结果
     * @param float $mainDuration 正片（含封面）总时长，必须 > 0
     */
    private static function alignStickerTimeline(array $stickers, float $mainDuration): array
    {
        foreach ($stickers as $index => $sticker) {
            $timelineIn = round((float)($sticker['TimelineIn'] ?? 0), 4);
            $timelineOut = isset($sticker['TimelineOut'])
                ? round((float)$sticker['TimelineOut'], 4)
                : $mainDuration;
            $timelineOut = min($timelineOut, $mainDuration);
            if ($timelineOut <= $timelineIn) {
                throw new Exception('贴纸开始时间超出视频总时长');
            }
            $stickers[$index]['TimelineIn'] = $timelineIn;
            $stickers[$index]['TimelineOut'] = $timelineOut;
        }
        return $stickers;
    }

    /**
     * 正片（含封面）在成片里占用的总时长。
     *
     * 合并是顺序拼接，所以等于各片段截取长度之和再加封面固定长度。
     * 前端提交的每个片段都带结束时间；拿不到有效区间说明参数不合预期，
     * 直接报错，不用写死的秒数把成片拉长。
     *
     * @param array $items    片段列表（in/out 已归一化）
     * @param bool  $hasCover 是否加了封面
     */
    private static function mainTrackDuration(array $items, bool $hasCover): float
    {
        $total = $hasCover ? SmartEditTimelineBuilder::COVER_SECONDS : 0.0;
        foreach ($items as $item) {
            $in = (float)($item['in'] ?? 0);
            $out = (float)($item['out'] ?? 0);
            if ($out <= $in) {
                throw new Exception('视频片段的截取区间无效，无法计算贴纸时长');
            }
            $total += $out - $in;
        }
        return round($total, 4);
    }

    /**
     * 背景音乐片段归一化。
     *
     * 音量统一折算成 0~1 的 gain；传 >1 的值按百分比理解（如 60 -> 0.6），
     * 这样前端用滑块（0~100）或小数都能直接对接。
     */
    private static function normalizeAudios($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        if (count($raw) > self::MAX_AUDIOS) {
            throw new Exception('一次最多添加 ' . self::MAX_AUDIOS . ' 段背景音乐');
        }
        $map = [
            'in' => 'In',
            'out' => 'Out',
            'timeline_in' => 'TimelineIn',
            'timeline_out' => 'TimelineOut',
        ];
        $rows = [];
        foreach (array_values($raw) as $item) {
            if (!is_array($item)) {
                throw new Exception('背景音乐参数格式错误');
            }
            $uri = trim((string)($item['uri'] ?? ''));
            if ($uri === '') {
                throw new Exception('背景音乐素材不能为空');
            }
            $row = ['uri' => $uri];
            foreach ($map as $key => $field) {
                if (!array_key_exists($key, $item) || $item[$key] === '' || $item[$key] === null) {
                    continue;
                }
                if (!is_numeric($item[$key])) {
                    throw new Exception('背景音乐参数 ' . $key . ' 必须是数字');
                }
                $row[$field] = (float)$item[$key];
            }
            if (isset($row['In'], $row['Out']) && $row['Out'] <= $row['In']) {
                throw new Exception('背景音乐截取结束时间必须大于开始时间');
            }
            if (isset($row['TimelineIn'], $row['TimelineOut']) && $row['TimelineOut'] <= $row['TimelineIn']) {
                throw new Exception('背景音乐结束时间必须大于开始时间');
            }
            $volume = $item['volume'] ?? $item['gain'] ?? null;
            if ($volume !== null && $volume !== '') {
                if (!is_numeric($volume)) {
                    throw new Exception('背景音乐音量必须是数字');
                }
                $gain = (float)$volume;
                // 大于 1 视为百分制，前端用 0~100 的滑块时不必自己折算。
                if ($gain > 1) {
                    $gain /= 100;
                }
                $row['gain'] = max(0.0, min(1.0, $gain));
            }
            if (array_key_exists('loop', $item)) {
                $row['loop'] = (bool)$item['loop'];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function buildTimeline(
        string $editType,
        array $items,
        string $coverUrl = '',
        array $title = [],
        int $outputHeight = 0,
        array $stickers = [],
        array $audios = []
    ): array {
        return match ($editType) {
            SmartEditTask::TYPE_MERGE => SmartEditTimelineBuilder::buildMerge(
                $items,
                $coverUrl,
                $title,
                $outputHeight,
                $stickers,
                $audios
            ),
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
        if ((string)$task['provider_job_id'] === '') {
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