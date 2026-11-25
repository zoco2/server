<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\WorkspaceAssetService;
use app\common\service\WorkspaceCanvasAssetService;
use think\facade\Db;

/** 发现默认组中的媒体，并复用作品库的素材归属与可见性校验。 */
final class PublishTaskMediaPool
{
    private const EXTENSIONS = [
        'video' => ['mp4', 'mov'],
        'finished_image' => ['jpg', 'jpeg', 'png', 'gif'],
    ];

    public function __construct(private int $tenantId, private int $userId)
    {
    }

    /** @return list<string> */
    public function refs(string $type): array
    {
        $scope = ['tenant_id' => $this->tenantId, 'user_id' => $this->userId];
        $tables = array_fill_keys(Db::connect()->getTables(), true);
        $prefix = (string)Db::connect()->getConfig('prefix');
        $kind = $type === 'video' ? 'video' : 'image';

        $refs = [];

        if ($type === 'video' && isset($tables[$prefix . 'smart_edit_task'])) {
            $films = SmartEditTask::where($scope)->where('status', SmartEditTask::STATUS_SUCCESS)
                ->where('output_uri', '<>', '')->column('id');
            foreach ($films as $id) {
                $refs[] = 'smart_edit:task:' . $id;
            }
        }
        if ($type === 'finished_image' && isset($tables[$prefix . 'content_library_item'])) {
            $images = Db::name('content_library_item')->where($scope)
                ->where(['kind' => 'finished_image', 'delete_time' => 0, 'hidden_time' => 0])->column('id');
            foreach ($images as $id) {
                $refs[] = 'content_library_item:' . $id;
            }
        }
        if (isset($tables[$prefix . 'aigc_canvas_run'])) {
            $page = 1;
            do {
                $result = WorkspaceCanvasAssetService::lists($this->tenantId, $this->userId, $page++);
                foreach ($result['lists'] as $item) {
                    if (($item['raw']['asset_type'] ?? '') === 'canvas_' . $kind) {
                        $refs[] = $item['ref'];
                    }
                }
            } while ($result['has_more']);
        }

        // 已安装的生成工具各自保存结果。使用同一套作品来源映射和稳定引用。
        foreach ($tables as $table => $_) {
            $name = substr($table, strlen($prefix));
            if (!str_starts_with($table, $prefix)
                || !preg_match('/^([a-z_]+)_(result|asset)$/D', $name, $parts)) {
                continue;
            }
            $source = WorkspaceAssetService::sourceForApp($parts[1]);
            $isAsset = in_array($source, ['canvas', 'short_drama'], true);
            if ($source === null || $isAsset !== ($parts[2] === 'asset')
                || (!$isAsset && !isset($tables[$prefix . $parts[1] . '_task']))) {
                continue;
            }
            $uri = $isAsset ? 'uri' : $kind . '_uri';
            $query = Db::name($name);
            $fields = $query->getTableFields();
            if (array_diff(['id', 'tenant_id', 'user_id', 'delete_time', $uri], $fields)) {
                continue;
            }
            $query->where($scope)->where('delete_time', 0)->where($uri, '<>', '');
            if ($isAsset && in_array('status', $fields, true)) {
                $query->where($source === 'short_drama' ? ['status' => 'ready'] : [['status', '<>', 'deleted']]);
            }
            foreach ($query->column('id') as $id) {
                $refs[] = $source . ':result:' . $id;
            }
        }
        return $refs;
    }

    /** @return array{ref:string,title:string,url:string}|null */
    public function resolve(string $type, string $ref): ?array
    {
        if (preg_match('/^content_library_item:([1-9][0-9]*)$/D', $ref, $match)) {
            return $type === 'finished_image' ? $this->resolveLibraryImage($ref, (int)$match[1]) : null;
        }
        if (!preg_match('/^[a-z_]+:(?:result|task):[1-9][0-9]*(?::[1-9][0-9]*)?$/D', $ref)) {
            return null;
        }
        $asset = WorkspaceAssetService::visibleAsset($this->tenantId, $this->userId, $ref);
        if ($asset === null) {
            return null;
        }
        $raw = $asset['raw'];
        $status = $raw['status'] ?? '';
        if (in_array($status, ['failed', 'error', 'deleted'], true)
            || ($asset['source'] === 'short_drama' && $status !== 'ready')) {
            return null;
        }
        $urlKey = $type === 'video' ? 'video_url' : 'image_url';
        $url = (string)(($raw[$urlKey] ?? '') ?: ($raw['url'] ?? ''));
        $parts = parse_url($url);
        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array($extension, self::EXTENSIONS[$type], true)) {
            return null;
        }
        $title = (string)(($raw['title'] ?? '') ?: ($raw['prompt'] ?? '未命名素材'));
        return ['ref' => $ref, 'title' => mb_substr($title, 0, 255), 'url' => $url];
    }

    private function resolveLibraryImage(string $ref, int $id): ?array
    {
        $query = Db::name('content_library_item');
        if (!in_array($query->getTable(), Db::connect()->getTables(), true)) {
            // 旧部署可能没有独立成品图片库。
            return null;
        }
        $item = $query->where([
            'tenant_id' => $this->tenantId, 'user_id' => $this->userId, 'id' => $id,
            'kind' => 'finished_image', 'delete_time' => 0, 'hidden_time' => 0,
        ])->field('name,file_ids')->find();
        $fileIds = $item ? json_decode((string)$item['file_ids'], true) : null;
        foreach (is_array($fileIds) ? $fileIds : [] as $fileId) {
            $file = $this->resolve('finished_image', 'upload:result:' . (int)$fileId);
            if ($file !== null) {
                return ['ref' => $ref, 'title' => (string)$item['name'], 'url' => $file['url']];
            }
        }
        return null;
    }
}
