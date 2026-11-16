<?php

// In-memory persistence doubles: exercise real article, order and tenant URL services
// without a database, queue worker, paid model request or media submission.
namespace GeoArticleReadyTest {
    class Row extends \ArrayObject
    {
        public function isEmpty(): bool { return $this->count() === 0; }
        public function toArray(): array { return $this->getArrayCopy(); }
        public function getData(string $key): mixed { return $this[$key]; }
        public function save(array $data): void
        {
            foreach ($data as $key => $value) $this[$key] = $value;
        }
    }

    class Query
    {
        public function __construct(private Row $row) {}
        public function findOrEmpty(): Row { return $this->row; }
    }
}

namespace app\common\model\geo {
    use GeoArticleReadyTest\Query;
    use GeoArticleReadyTest\Row;

    class GeoArticle
    {
        public const STATUS_DRAFT = 'draft';
        public const STATUS_READY = 'ready';
        public const STATUS_TEXT = ['draft' => '草稿', 'ready' => '待投稿'];
        public static array $rows = [];

        public static function create(array $data): Row
        {
            $id = count(self::$rows) + 1;
            return self::$rows[$id] = new Row($data + ['id' => $id, 'update_time' => time()]);
        }

        public static function where(string|array $field, mixed $value = null): Query
        {
            $conditions = is_array($field) ? $field : [$field => $value];
            foreach (self::$rows as $row) {
                foreach ($conditions as $key => $expected) {
                    if ($row[$key] !== $expected) continue 2;
                }
                return new Query($row);
            }
            return new Query(new Row());
        }
    }

    class GeoMedia
    {
        public static function where(string $field, mixed $value): Query
        {
            return new Query(new Row());
        }
    }
}

namespace app\common\model\tenant {
    use GeoArticleReadyTest\Query;
    use GeoArticleReadyTest\Row;

    class Tenant
    {
        public static array $rows = [];
        public static function where(string $field, int $value): Query
        {
            return new Query(new Row(self::$rows[$value] ?? []));
        }
    }
}

namespace think\facade {
    class Config
    {
        public static function get(string $key): string { return 'platform.example'; }
    }
}

namespace app\common\service\tenant {
    class TenantDomainAliasService
    {
        public static function getPrimaryAlias(array $tenant): string
        {
            return $tenant['domain_alias'] ?? '';
        }
    }
}

namespace app\common\service {
    class FileService
    {
        public static function setFileUrl(string $content): string { return $content; }
    }
}

namespace {
    use app\common\model\geo\GeoArticle;
    use app\common\model\tenant\Tenant;
    use app\common\service\geo\GeoArticleService;
    use app\common\service\geo\GeoOrderService;

    function request(): object
    {
        // A worker has no originating HTTP request; never derive its tenant URL here.
        return new class { public function domain(): string { return 'http://localhost'; } };
    }
    function get_file_domain(string $content): string { return $content; }
    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }
    function expectError(callable $callback, string $message): void
    {
        try { $callback(); } catch (Exception $error) {
            check($error->getMessage() === $message, 'Unexpected error: ' . $error->getMessage());
            return;
        }
        throw new RuntimeException('Expected error: ' . $message);
    }

    require dirname(__DIR__, 2) . '/app/common/service/tenant/TenantUrlService.php';
    require dirname(__DIR__, 2) . '/app/common/service/geo/GeoArticleService.php';
    require dirname(__DIR__, 2) . '/app/common/service/geo/GeoOrderService.php';

    unset($_SERVER['HTTPS']);
    Tenant::$rows = [
        7 => ['id' => 7, 'sn' => 'tenant-a', 'access_mode' => 'subdomain'],
        8 => ['id' => 8, 'sn' => 'tenant-b', 'access_mode' => 'alias', 'domain_alias_enable' => 0, 'domain_alias' => 'brand.example'],
        9 => ['id' => 9, 'sn' => 'tenant-c', 'access_mode' => 'id'],
    ];
    $article = ['title' => 'AI 稿件', 'digest' => '摘要', 'content' => "第一段\n\n<script>alert(1)</script>"];
    $id = GeoArticleService::createGeneratedArticle(7, 23, '写稿要求', $article);
    $row = GeoArticle::$rows[$id];
    check($row['status'] === 'ready', 'Generated article must be ready without a manual save');
    check($row['source'] === 'ai' && $row['source_question'] === '写稿要求', 'AI origin must be preserved');
    check($row['tenant_id'] === 7 && $row['user_id'] === 23, 'Generated article ownership must be preserved');
    check(preg_match('/\A[a-f0-9]{40}\z/', $row['preview_token']) === 1, 'Preview must have an unguessable token');
    check($row['preview_url'] === 'http://tenant-a.platform.example/api/geo.article/preview?token=' . $row['preview_token'], 'Worker preview must use the tenant domain');
    $preview = GeoArticleService::preview($row['preview_token']);
    check($preview['title'] === $article['title'], 'Generated article must be immediately previewable');
    check(str_contains($preview['content'], '&lt;script&gt;') && !str_contains($preview['content'], '<script>'), 'Generated text must stay HTML escaped');
    // A missing-media error proves the real order service passed article readiness checks.
    expectError(fn() => GeoOrderService::create(23, ['article_id' => $id, 'media_id' => 0]), '媒体资源不存在');
    expectError(fn() => GeoOrderService::create(24, ['article_id' => $id, 'media_id' => 0]), '稿件不存在');

    $url = $row['preview_url'];
    $saved = GeoArticleService::save(23, ['id' => $id, 'title' => '修改标题', 'content' => '<p>修改正文</p>']);
    check($saved['preview_url'] === $url && $saved['status'] === 'ready', 'Editing must retain the ready state and existing link');
    check($saved['source'] === 'ai' && $saved['source_question'] === '写稿要求', 'Editing must retain AI attribution');

    $legacy = GeoArticle::create($row->toArray());
    $legacyId = array_key_last(GeoArticle::$rows);
    $legacy->save(['id' => $legacyId, 'status' => 'draft', 'preview_token' => str_repeat('b', 40), 'preview_url' => '']);
    expectError(fn() => GeoArticleService::preview($legacy['preview_token']), '预览地址已失效');
    $savedLegacy = GeoArticleService::save(23, ['id' => $legacyId, 'title' => '旧稿件', 'content' => '<p>旧正文</p>']);
    check($savedLegacy['status'] === 'ready' && $savedLegacy['preview_url'] !== '', 'Existing AI drafts must still activate on save');
    check($savedLegacy['preview_token'] === str_repeat('b', 40), 'Saving an existing draft must preserve its token');

    $aliasId = GeoArticleService::createGeneratedArticle(8, 23, '别名租户', $article);
    check(str_starts_with(GeoArticle::$rows[$aliasId]['preview_url'], 'http://brand.example/api/geo.article/preview?token='), 'Worker must respect the configured alias');
    $idModeId = GeoArticleService::createGeneratedArticle(9, 23, 'ID 租户', $article);
    $idModeUrl = GeoArticle::$rows[$idModeId]['preview_url'];
    check(str_starts_with($idModeUrl, 'http://platform.example/api/geo.article/preview?token=') && str_ends_with($idModeUrl, '&tenant_id=9'), 'ID access mode must keep tenant routing in the preview query');
    check(GeoArticle::$rows[$aliasId]['preview_token'] !== GeoArticle::$rows[$idModeId]['preview_token'], 'Generated articles must have distinct preview tokens');
    $count = count(GeoArticle::$rows);
    expectError(fn() => GeoArticleService::createGeneratedArticle(999, 23, '不存在的租户', $article), '租户不存在，无法生成稿件预览地址');
    check(count(GeoArticle::$rows) === $count, 'Missing tenant must not create a partially ready article');

    // Automatic task callbacks must share the tenant-aware preview builder from master.
    foreach ([$id, $aliasId, $idModeId] as $generatedId) {
        $generated = GeoArticle::$rows[$generatedId];
        $tenantId = $generated['tenant_id'];
        $readyUrl = $generated['preview_url'];
        GeoArticleService::prepareForAutomaticSubmission($tenantId, 23, $generatedId);
        check($generated['preview_url'] === $readyUrl, 'Automatic submission must preserve a ready article URL');

        $draft = GeoArticle::create($generated->toArray());
        $draftId = array_key_last(GeoArticle::$rows);
        $token = bin2hex(random_bytes(20));
        $draft->save(['id' => $draftId, 'status' => 'draft', 'preview_token' => $token, 'preview_url' => '']);
        expectError(fn() => GeoArticleService::prepareForAutomaticSubmission($tenantId, 24, $draftId), '生成的稿件不存在或正文为空');
        expectError(fn() => GeoArticleService::prepareForAutomaticSubmission(999, 23, $draftId), '生成的稿件不存在或正文为空');
        check($draft['preview_url'] === '', 'An unauthorized callback must not activate a draft');
        GeoArticleService::prepareForAutomaticSubmission($tenantId, 23, $draftId);
        check($draft['status'] === 'ready' && $draft['preview_token'] === $token, 'Automatic preparation must activate legacy drafts and preserve their tokens');
        check($draft['preview_url'] === str_replace($generated['preview_token'], $token, $readyUrl), 'Automatic preparation must use the same tenant routing as generated articles');
        check(GeoArticleService::preview($token)['title'] === $draft['title'], 'Automatically prepared drafts must be previewable');
    }

    echo "PASS: generated articles are ready, previewable, eligible for submission, editable and tenant-aware; legacy saves remain supported\n";
}
