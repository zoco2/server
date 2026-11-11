<?php

namespace app\common\service\geo;

use app\common\model\geo\GeoArticle;
use app\common\model\geo\GeoOrder;
use app\common\service\FileService;
use Exception;

/**
 * GEO 稿件。前台用户写稿，后台可查看管理。
 *
 * 预览地址是投稿的关键：海讯社建单的 content 传的是 URL，由对方去抓取正文，
 * 所以预览页必须是服务端直出 HTML、无需登录、公网可访问。
 */
class GeoArticleService
{
    /** 详情返回时去掉的字段（正文在内容里单独给） */
    private const SECRET_FIELDS = ['delete_time'];

    /**
     * 我的稿件列表 / 后台全部稿件列表。
     *
     * 租户隔离由 BaseModel 全局作用域负责，这里只管用户维度。
     *
     * @param array<string, mixed> $params 查询参数
     * @param int $userId 用户 ID，0 表示不按用户过滤（后台视角）
     * @return array{count:int, lists:array<int, array<string, mixed>>}
     */
    public static function lists(array $params, int $userId = 0): array
    {
        $query = GeoArticle::field(GeoArticle::LIST_FIELDS);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        } else {
            // 后台可以按创建人筛选，0 表示后台创建的（历史数据）
            if (isset($params['user_id']) && $params['user_id'] !== '') {
                $query->where('user_id', (int)$params['user_id']);
            }
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')
                    ->whereOr('digest', 'like', '%' . $keyword . '%');
            });
        }

        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $count = (int)(clone $query)->count();
        $rows = $query
            ->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['status_text'] = GeoArticle::STATUS_TEXT[$row['status']] ?? $row['status'];
        }
        unset($row);
        return ['count' => $count, 'lists' => $rows];
    }

    /**
     * 稿件详情。
     *
     * @param int $id 稿件 ID
     * @param int $userId 用户 ID，0 表示后台不校验归属
     * @return array<string, mixed>
     */
    public static function detail(int $id, int $userId = 0): array
    {
        $row = GeoArticle::where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('稿件不存在');
        }
        if ($userId > 0 && (int)$row['user_id'] !== $userId) {
            throw new Exception('稿件不存在');
        }
        $data = $row->toArray();
        foreach (self::SECRET_FIELDS as $field) {
            unset($data[$field]);
        }
        $data['status_text'] = GeoArticle::STATUS_TEXT[$data['status']] ?? $data['status'];
        return $data;
    }

    /**
     * 新建 / 编辑稿件。
     *
     * @param int $userId 当前用户，0 表示后台创建
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function save(int $userId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            throw new Exception('请填写稿件标题');
        }
        $content = self::normalizeContent((string)($params['content'] ?? ''));
        if ($content === '') {
            throw new Exception('请填写稿件正文');
        }

        $data = [
            'title' => mb_substr($title, 0, 255),
            'digest' => mb_substr(trim((string)($params['digest'] ?? '')), 0, 500),
            'content' => $content,
            'source_question' => mb_substr(trim((string)($params['source_question'] ?? '')), 0, 2000),
            'source' => (string)($params['source'] ?? 'manual'),
            'remark' => mb_substr(trim((string)($params['remark'] ?? '')), 0, 500),
            'update_time' => time(),
        ];

        if ($id > 0) {
            $row = GeoArticle::where('id', $id)->findOrEmpty();
            if ($row->isEmpty() || ($userId > 0 && (int)$row['user_id'] !== $userId)) {
                throw new Exception('稿件不存在');
            }
            if ($row['source'] === 'ai') {
                $data['source'] = 'ai';
                if (!isset($params['source_question'])) {
                    $data['source_question'] = $row['source_question'];
                }
                if ($row['status'] === GeoArticle::STATUS_DRAFT && (string)$row['preview_url'] === '') {
                    $data['status'] = GeoArticle::STATUS_READY;
                    $data['preview_token'] = $row['preview_token'] ?: self::newToken();
                    $data['preview_url'] = self::buildPreviewUrl($data['preview_token']);
                }
            }
            // 已投稿/已发布的稿件允许改正文，但预览 token 保持不变，避免旧订单的链接失效。
            $row->save($data);
            return self::detail($id, $userId);
        }

        $data['user_id'] = $userId;
        $data['status'] = GeoArticle::STATUS_READY;
        $data['preview_token'] = self::newToken();
        $data['preview_url'] = self::buildPreviewUrl($data['preview_token']);
        $data['create_time'] = time();
        $row = GeoArticle::create($data);
        return self::detail((int)$row['id'], $userId);
    }

    /** 将模型返回的纯文本存为稿件草稿，用户保存后才启用公开预览。 */
    public static function createGeneratedDraft(int $tenantId, int $userId, string $prompt, array $article): int
    {
        $paragraphs = preg_split('/\R{2,}/u', $article['content']) ?: [$article['content']];
        $content = implode("\n", array_map(
            static fn(string $text): string => '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            $paragraphs
        ));
        $row = GeoArticle::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $article['title'],
            'digest' => $article['digest'],
            'content' => $content,
            'source_question' => mb_substr($prompt, 0, 2000, 'UTF-8'),
            'source' => 'ai',
            'status' => GeoArticle::STATUS_DRAFT,
            'preview_token' => self::newToken(),
            'preview_url' => '',
        ]);
        $id = (int)$row['id'];
        if ($id <= 0) {
            throw new Exception('稿件保存失败');
        }
        return $id;
    }

    /**
     * 删除稿件（软删除）。有进行中的投稿单时不允许删除。
     *
     * @param int $id 稿件 ID
     * @param int $userId 用户 ID，0 表示后台
     */
    public static function delete(int $id, int $userId = 0): void
    {
        $row = GeoArticle::where('id', $id)->findOrEmpty();
        if ($row->isEmpty() || ($userId > 0 && (int)$row['user_id'] !== $userId)) {
            throw new Exception('稿件不存在');
        }
        $active = GeoOrder::where('article_id', $id)
            ->whereIn('status', [-1, 0, 1, 3, 4])
            ->count();
        if ((int)$active > 0) {
            throw new Exception('该稿件还有进行中的投稿单，不能删除');
        }
        $row->delete();
    }

    /**
     * 预览页数据。按 token 取，不需要登录。
     *
     * @param string $token 预览 token
     * @return array<string, mixed>
     */
    public static function preview(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new Exception('预览地址无效');
        }
        $row = GeoArticle::where('preview_token', $token)->findOrEmpty();
        if ($row->isEmpty() || ($row['status'] === GeoArticle::STATUS_DRAFT && (string)$row['preview_url'] === '')) {
            throw new Exception('预览地址已失效');
        }
        return [
            'title' => (string)$row['title'],
            'digest' => (string)$row['digest'],
            'content' => self::renderContent((string)$row['content']),
            'update_time' => (int)$row['update_time'],
        ];
    }

    /**
     * 稿件正文补全图片/视频域名，供预览页和后台详情展示。
     *
     * @param string $content 富文本 HTML
     */
    public static function renderContent(string $content): string
    {
        return get_file_domain($content);
    }

    /**
     * 生成预览 token。
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * 预览地址。取当前租户域名，保证海讯社能公网访问。
     *
     * @param string $token 预览 token
     */
    public static function buildPreviewUrl(string $token): string
    {
        $domain = rtrim((string)request()->domain(), '/');
        return $domain . '/api/geo.article/preview?token=' . $token;
    }

    /**
     * 清洗正文：去掉脚本、事件属性，统一图片相对路径。
     *
     * @param string $content 富文本 HTML
     */
    private static function normalizeContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        // 海讯社预览页会被对方抓取，不能带 <script>
        $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content) ?? $content;
        $content = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $content) ?? $content;
        // 正文落库存相对路径，展示时统一补域名；已是绝对地址的不动。
        return FileService::setFileUrl($content) ?: $content;
    }
}
