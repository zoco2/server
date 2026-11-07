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

    /** 贴纸数量上限 */
    public const MAX_STICKERS = 20;

    /** 背景音乐数量上限 */
    public const MAX_AUDIOS = 3;

    /** 贴纸未指定结束时间时的默认展示时长（秒） */
    public const STICKER_DEFAULT_SECONDS = 60.0;

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
        $validated = self::validate($tenantId, $params);
        [$editType, $title, $clips, $coverUri, $aspectRatio, $titleConfig] = $validated;
        $stickerInputs = $validated[6];
        $audioInputs = $validated[7];
        $config = SmartEditImsConfigService::require($tenantId);
        $taskNo = self::newTaskNo();
        [$outputWidth, $outputHeight] = self::resolveOutputSize($aspectRatio);

        $items = [];
        foreach ($clips as $index => $clip) {
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
        // 校验完把 uri 换成 IMS 能直接拉取的 media_url，其余字段原样保留。
        // 贴纸可能来自平台预置（不在 la_tenant_file 里），先查贴纸表；
        // 查不到再回退到素材库校验，兼容现有「从素材库选图片当贴纸」的用法。
        $stickers = [];
        foreach ($stickerInputs as $input) {
            $uri = (string)$input['uri'];
            $sticker = StickerService::resolveForSmartEdit($tenantId, $uri);
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
        $titleConfig = self::resolveTitleFont($config, $titleConfig);
        $timeline = self::buildTimeline($editType, $items, $coverUrl, $titleConfig, $outputHeight, $stickers, $audios);

        $task = SmartEditTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_no' => $taskNo,
            'edit_type' => $editType,
            'title' => $title,
            'params_json' => [
                'edit_type' => $editType,
                'title' => $title,
                'clips' => $clips,
                'cover_uri' => $coverUri,
                'aspect_ratio' => $aspectRatio,
                'title_text' => (string)($titleConfig['text'] ?? ''),
                'title' => $titleConfig,
                'stickers' => $stickerInputs,
                'audios' => $audioInputs,
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

        // 提交动作交给 MNS：接口不再等供应商返回。
        // 投递失败时任务停在 submitting，用户可重试；不在这里判失败，避免消息已发出又被覆盖。
        try {
            SmartEditSubmitJob::dispatch(['task_ids' => [(int)$task['id']]], 0, '', $tenantId);
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 投递失败 task=' . $taskNo . ' ' . $e->getMessage());
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
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 重试投递失败 task=' . $task['task_no'] . ' ' . $e->getMessage());
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
        // 标题：文案与排版全部由前端决定，这里只校验取值范围。
        $titleConfig = self::normalizeTitle($params);
        $stickers = self::normalizeStickers($params['stickers'] ?? []);
        $audios = self::normalizeAudios($params['audios'] ?? $params['audios_tracks'] ?? []);
        return [$editType, mb_substr($title, 0, 100), $normalized, $coverUri, $aspectRatio, $titleConfig, $stickers, $audios];
    }

    /**
     * 标题配置归一化。
     *
     * 兼容两种入参：只给 title_text 的旧格式，以及带完整排版的 title 对象。
     * 文案为空表示不加标题；其余字段缺省时交给 Timeline 构建补默认值，
     * 这里只拦明显非法的输入，避免把错误参数静默传给 IMS。
     */
    private static function normalizeTitle(array $params): array
    {
        $raw = $params['title_overlay'] ?? $params['title'] ?? null;
        // title 参数历史上是任务名称，只有对象形式才当排版配置用。
        $config = is_array($raw) ? $raw : [];
        $text = trim((string)($config['text'] ?? $params['title_text'] ?? ''));
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) > self::MAX_TITLE_TEXT_LENGTH) {
            throw new Exception('标题文案不能超过 ' . self::MAX_TITLE_TEXT_LENGTH . ' 个字');
        }
        $result = ['text' => $text];

        $start = self::numberOrNull($config['timeline_in'] ?? $config['start'] ?? null);
        $end = self::numberOrNull($config['timeline_out'] ?? $config['end'] ?? null);
        if ($start !== null || $end !== null) {
            $start = max(0, (float)($start ?? 0));
            $end = $end === null ? $start + SmartEditTimelineBuilder::TITLE_SECONDS : (float)$end;
            if ($end <= $start) {
                throw new Exception('标题结束时间必须大于开始时间');
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
                throw new Exception('标题字号需在 8 ~ 400 之间');
            }
            $result['font_size'] = (int)round($fontSize);
        }

        $color = trim((string)($config['font_color'] ?? $config['color'] ?? ''));
        if ($color !== '') {
            if (!preg_match('/^#([0-9a-fA-F]{6})$/', $color)) {
                throw new Exception('标题颜色需为 #RRGGBB 格式');
            }
            $result['font_color'] = $color;
        }

        $y = self::numberOrNull($config['y'] ?? null);
        if ($y !== null) {
            if ($y < 0) {
                throw new Exception('标题高度不能为负数');
            }
            $result['y'] = $y;
        }

        $alignment = trim((string)($config['alignment'] ?? ''));
        if ($alignment !== '') {
            // 只接受 IMS 的横向对齐取值；其余一律报错，避免 typo 静默变成默认值。
            $allowed = ['TopLeft', 'TopCenter', 'TopRight', 'Left', 'Center', 'Right',
                'CenterLeft', 'CenterCenter', 'CenterRight', 'BottomLeft', 'BottomCenter', 'BottomRight'];
            if (!in_array($alignment, $allowed, true)) {
                throw new Exception('不支持的标题对齐方式：' . $alignment);
            }
            $result['alignment'] = $alignment;
        }

        // 字体：优先用预置字体库的 key，其次才接受系统字体名 / 自定义地址。
        // font_key 在提交阶段才去下载并上传，避免每次打开页面都拉字体文件。
        $fontKey = trim((string)($config['font_key'] ?? ''));
        if ($fontKey !== '') {
            if (!SmartEditFontService::has($fontKey)) {
                throw new Exception('不支持的标题字体：' . $fontKey);
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
                throw new Exception('标题字体地址必须是完整的 http(s) 地址');
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
     * 把标题的字体 key 解析成 IMS 能直接拉取的字體地址。
     *
     * 预置字体会在首次使用时下载并上传到租户 OSS，之后复用同一对象。
     * 准备失败时退回该字体的系统字体名；再失败就交给 IMS 默认字体，
     * 保证任务能提交，不会因为字体问题整单失败。
     *
     * @param array $config 阿里云配置
     * @param array $title  归一化后的标题配置
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
        // 下载或上传失败：退回系统字体名，至少不会是默认字体
        $alias = SmartEditFontService::aliasOf($key);
        if ($alias !== '') {
            $title['font'] = $alias;
        }
        return $title;
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
        if (count($raw) > self::MAX_STICKERS) {
            throw new Exception('一次最多添加 ' . self::MAX_STICKERS . ' 个贴纸');
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
            // 贴纸必须有明确时长，否则 IMS 找不到可渲染区间会直接忽略这个片段
            if (!isset($row['TimelineIn'])) {
                $row['TimelineIn'] = 0;
            }
            if (!isset($row['TimelineOut'])) {
                $row['TimelineOut'] = self::defaultStickerSeconds($row['TimelineIn']);
            }
            if ($row['TimelineOut'] <= $row['TimelineIn']) {
                throw new Exception('贴纸结束时间必须大于开始时间');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 贴纸未给结束时间时的默认时长。
     *
     * 前端只传位置时不至于整段消失，给一个足够长的区间覆盖常见成片。
     */
    private static function defaultStickerSeconds(float $from): float
    {
        return round($from + self::STICKER_DEFAULT_SECONDS, 4);
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