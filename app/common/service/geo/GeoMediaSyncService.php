<?php

namespace app\common\service\geo;

use app\common\exception\GeoTokenExpiredException;
use app\common\model\geo\GeoMedia;
use app\common\service\ConfigService;
use Exception;
use think\facade\Log;
use Throwable;

/**
 * 海讯社媒体资源同步。
 *
 * 账号 token/key 由 {@see AigcGeoManuscriptService::saveConfig()} 写入租户配置，
 * 这里只负责拉取 /api/media/resources/list{n}ON 并落本地镜像 la_geo_media。
 * 海讯列表接口不支持服务端搜索，前台筛选全部在本地表完成。
 */
class GeoMediaSyncService
{
    /** 单页条数，海讯建议不超过 3000。 */
    public const PAGE_SIZE = 1000;

    /** 单页抓取最大重试次数。 */
    public const MAX_ATTEMPTS = 3;

    /** 单次调用内最多换几次令牌，避免令牌持续失效时无限重试。 */
    public const MAX_TOKEN_RENEW = 2;

    /** @var array<int, string> 资源类型字典，与海讯 list{n}ON 的 n 对应。 */
    public const RESOURCE_TYPES = AigcGeoManuscriptService::RESOURCE_TYPES;

    /**
     * 同步指定资源类型。返回统计信息。
     *
     * @param int $tenantId 租户 ID
     * @param int $type 资源类型 1~15
     * @param bool $markMissing 本次未出现的资源是否标记 gone（数据必须完整同步过一次才开）
     * @param int $limit 最多处理多少条，0 表示不限，用于试跑或控制数据量
     * @param callable|null $onProgress 进度回调 fn(array $progress): void
     * @return array{type:int, total:int, created:int, updated:int, gone:int, pages:int, processed:int, limited:bool}
     */
    public static function syncType(
        int $tenantId,
        int $type,
        bool $markMissing = false,
        int $limit = 0,
        ?callable $onProgress = null
    ): array {
        if (!isset(self::RESOURCE_TYPES[$type])) {
            throw new Exception('资源类型无效');
        }
        $openToken = AigcGeoManuscriptService::accessToken($tenantId);
        $limit = max(0, $limit);
        $seen = [];
        $created = 0;
        $updated = 0;
        $processed = 0;
        $total = 0;
        $page = 1;
        $pages = 0;
        $pagesTotal = 1;
        $limited = false;

        do {
            // 令牌在循环里失效时自动换新并回写到 $openToken，后续页继续用新令牌。
            $payload = self::callWithToken(
                $tenantId,
                $openToken,
                static fn (string $token): array => AigcGeoManuscriptService::resources($token, $type, $page, self::PAGE_SIZE)
            );
            $total = (int)$payload['total'];
            // data.list 可能是对象（海讯偶尔按 sourceid 做 key），统一成列表。
            $list = array_values(is_array($payload['list']) ? $payload['list'] : []);
            $pages++;
            $pagesTotal = max(1, (int)ceil($total / self::PAGE_SIZE));
            if ($onProgress !== null) {
                $onProgress([
                    'phase' => 'page',
                    'type' => $type,
                    'page' => $page,
                    'pages' => $pagesTotal,
                    'total' => $total,
                    'processed' => $processed,
                    'created' => $created,
                    'updated' => $updated,
                ]);
            }
            foreach ($list as $row) {
                if ($limit > 0 && $processed >= $limit) {
                    $limited = true;
                    break 2;
                }
                if (!is_array($row)) {
                    continue;
                }
                $sourceid = trim((string)($row['sourceid'] ?? ''));
                if ($sourceid === '') {
                    continue;
                }
                $seen[$sourceid] = true;
                $processed++;
                if (self::saveRow($tenantId, $type, $sourceid, $row)) {
                    $created++;
                } else {
                    $updated++;
                }
            }
            $page++;
        } while ($list !== [] && $page <= $pagesTotal && $page <= 200);

        $gone = 0;
        // 限制条数时数据不完整，不能判断哪些资源已经下线。
        if ($markMissing && !$limited && $seen !== []) {
            $gone = self::markGone($tenantId, $type, array_keys($seen));
        }

        return [
            'type' => $type,
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'gone' => $gone,
            'pages' => $pages,
            'processed' => $processed,
            'limited' => $limited,
        ];
    }

    /**
     * 逐个查询资源状态（stateON），更新上架/删除标记。
     *
     * @param int $tenantId 租户 ID
     * @param array<int, string> $sourceIds 海讯资源ID列表
     * @return array{checked:int, down:int, removed:int}
     */
    public static function refreshStates(int $tenantId, array $sourceIds): array
    {
        $sourceIds = array_values(array_filter(array_unique(array_map('trim', $sourceIds))));
        if ($sourceIds === []) {
            return ['checked' => 0, 'down' => 0, 'removed' => 0];
        }
        $openToken = AigcGeoManuscriptService::accessToken($tenantId);
        $checked = 0;
        $down = 0;
        $removed = 0;
        foreach ($sourceIds as $sourceId) {
            try {
                // 逐个校正可能跑很久，同样交给带令牌自愈的调用器。
                $state = self::callWithToken(
                    $tenantId,
                    $openToken,
                    static fn (string $token): array => AigcGeoManuscriptService::resourceState($token, $sourceId)
                );
            } catch (Exception $e) {
                Log::warning('geo media state failed: ' . $sourceId . ' ' . $e->getMessage());
                continue;
            }
            $checked++;
            $down += $state['flag'] === 1 ? 0 : 1;
            $removed += $state['deleted'] === 1 ? 0 : 1;
            GeoMedia::where(['sourceid' => $sourceId])->update([
                'flag' => $state['flag'],
                'deleted' => $state['deleted'],
                'sync_status' => $state['available'] ? 'synced' : ($state['deleted'] === 1 ? 'stale' : 'gone'),
                'update_time' => time(),
            ]);
        }
        return ['checked' => $checked, 'down' => $down, 'removed' => $removed];
    }

    /**
     * 同步多类资源。
     *
     * @param int $tenantId 租户 ID
     * @param array<int> $types 指定类型；空数组表示全部
     * @param bool $markMissing 本次未出现的资源是否标记 gone
     * @param int $limit 每类最多处理多少条，0 表示不限
     * @param callable|null $onProgress 进度回调
     * @return array<int, array<string, mixed>>
     */
    public static function syncAll(
        int $tenantId,
        array $types = [],
        bool $markMissing = true,
        int $limit = 0,
        ?callable $onProgress = null
    ): array {
        $types = $types === [] ? array_keys(self::RESOURCE_TYPES) : $types;
        $result = [];
        foreach ($types as $type) {
            try {
                $result[$type] = self::syncType($tenantId, (int)$type, $markMissing, $limit, $onProgress);
            } catch (Throwable $e) {
                $result[$type] = [
                    'type' => (int)$type,
                    'error' => $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(),
                ];
            }
        }
        return $result;
    }

    /**
     * 租户默认加成倍率，配置在 geo_manuscript.markup，默认 1.0。
     * 资源行若单独设置了 price_ratio（大于 0）则优先用行上的值。
     */
    public static function markup(?int $tenantId = null): float
    {
        $tenantId = $tenantId ?: (int)request()->tenantId;
        $value = self::tenantConfig($tenantId, 'markup');
        $markup = is_numeric($value) ? (float)$value : 1.0;
        return $markup > 0 ? $markup : 1.0;
    }

    /**
     * 读租户级配置。ConfigService 依赖 request()->tenantId，
     * 命令行/轮询任务里没有请求上下文时直接按 tenant_id 查 la_tenant_config。
     *
     * @param int $tenantId 租户 ID
     * @param string $name 配置名
     * @param mixed $default 默认值
     * @param string $type 配置类型，默认 geo_manuscript
     * @return mixed
     */
    public static function tenantConfig(int $tenantId, string $name, mixed $default = null, string $type = 'geo_manuscript'): mixed
    {
        if ($tenantId > 0 && (int)(request()->tenantId ?? 0) !== $tenantId) {
            $value = \think\facade\Db::name('tenant_config')
                ->where(['tenant_id' => $tenantId, 'type' => $type, 'name' => $name])
                ->value('value');
            if ($value === null) {
                return $default;
            }
            $json = json_decode((string)$value, true);
            return json_last_error() === JSON_ERROR_NONE ? $json : $value;
        }
        return ConfigService::get($type, $name, $default);
    }

    /**
     * 按成本价与倍率计算建议售价。
     *
     * @param float $costPrice 海讯成本价
     * @param float $markup 倍率
     */
    public static function suggestSalePrice(float $costPrice, float $markup): float
    {
        return round($costPrice * $markup, 2);
    }

    /**
     * 带令牌自愈的调用。
     *
     * 全量同步要跑很久，令牌可能在循环中途失效；缓存里的令牌又是按租户共享的，
     * 后台保存配置或另一个进程刷新都会让手里的旧令牌当场作废。这里收到令牌失效
     * 就换新令牌立即重试，而不是拿同一个过期令牌空转到次数耗尽。
     *
     * @param int $tenantId 租户 ID
     * @param string $openToken 访问令牌，失效换新后回写
     * @param callable $call 实际请求，签名为 fn(string $openToken): array
     * @return array<string, mixed>
     */
    private static function callWithToken(int $tenantId, string &$openToken, callable $call): array
    {
        $lastError = null;
        $renewed = 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $call($openToken);
            } catch (GeoTokenExpiredException $e) {
                $lastError = $e;
                if ($renewed >= self::MAX_TOKEN_RENEW) {
                    break;
                }
                $renewed++;
                try {
                    $openToken = AigcGeoManuscriptService::renewAccessToken($tenantId);
                } catch (Exception $renewError) {
                    // 换令牌都失败了（账号被撤销、网络不通），重试没有意义。
                    throw new Exception('访问令牌已失效且无法重新获取：' . $renewError->getMessage());
                }
            } catch (Exception $e) {
                $lastError = $e;
                usleep(300000 * $attempt);
            }
        }
        throw new Exception('同步失败：' . ($lastError ? $lastError->getMessage() : '未知错误'));
    }

    /**
     * 写入一行资源。已存在时不覆盖手工售价，只在成本变化时按倍率刷新。
     *
     * @param int $tenantId 租户 ID
     * @param int $type 资源类型
     * @param string $sourceid 海讯资源ID
     * @param array<string, mixed> $row 海讯原始行
     * @return bool true=新建，false=更新
     */
    private static function saveRow(int $tenantId, int $type, string $sourceid, array $row): bool
    {
        $cost = round((float)($row['costprice'] ?? 0), 2);
        $data = [
            'tenant_id' => $tenantId,
            'sourceid' => $sourceid,
            'resource_type' => $type,
            'name' => self::text($row['name'] ?? '', 200),
            'classify' => self::text($row['classify'] ?? '', 120),
            'region' => self::text($row['region'] ?? '', 120),
            'portalmedia' => self::text($row['portalmedia'] ?? '', 120),
            'industrystr' => self::text($row['industrystr'] ?? '', 200),
            'searchcoll' => self::text($row['searchcoll'] ?? '', 200),
            'entrancelink' => self::text($row['entrancelink'] ?? '', 500),
            'caseurl' => self::text($row['caseurl'] ?? '', 500),
            'remark' => self::text($row['remark'] ?? '', 500),
            'costprice' => $cost,
            // 必须写真实列名：模型 $json 声明的是 price_type_json，
            // 写成 price_types 既不是列也不是 json 声明名，会被当普通数组塞进 SQL。
            'price_type_json' => self::priceTypes($row),
            'linktype' => (int)($row['linktype'] ?? 0),
            'publishtime' => (int)($row['publishtime'] ?? 0),
            'newsresource' => (int)($row['newsresource'] ?? 0),
            'weekendpublish' => (int)($row['weekendpublish'] ?? 0),
            'searchcollint' => (int)($row['searchcollint'] ?? 0),
            'pcweight' => (int)($row['pcweight'] ?? 0),
            'wapweight' => (int)($row['wapweight'] ?? 0),
            'entrlevel' => (int)($row['entrlevel'] ?? 0),
            'recommend' => (int)($row['recommend'] ?? 0),
            'flag' => (int)($row['flag'] ?? 1),
            'deleted' => (int)($row['deleted'] ?? 1),
            'sync_status' => 'synced',
            // detail_json 没声明成 json 字段，靠 setDetailJsonAttr 自行编码成字符串，
            // 否则会被 Builder 当数组、走 $val[0] 分支报 Undefined array key 0。
            'detail_json' => $row,
            'last_sync_time' => time(),
            'update_time' => time(),
        ];
        $exists = GeoMedia::where(['sourceid' => $sourceid])->findOrEmpty();
        if ($exists->isEmpty()) {
            $data['create_time'] = time();
            GeoMedia::create($data);
            return true;
        }
        unset($data['detail_json']);
        $exists->save($data);
        return false;
    }

    /**
     * 全量同步后，本次未出现的资源标记 gone（海讯已删除）。
     *
     * @param int $tenantId 租户 ID
     * @param int $type 资源类型
     * @param array<int, string> $seenIds 本次出现的资源ID
     */
    private static function markGone(int $tenantId, int $type, array $seenIds): int
    {
        $query = GeoMedia::where(['resource_type' => $type])
            ->where('sync_status', '<>', 'gone');
        if ($seenIds !== []) {
            $query->whereNotIn('sourceid', $seenIds);
        }
        return (int)$query->update([
            'flag' => 0,
            'deleted' => 0,
            'sync_status' => 'gone',
            'update_time' => time(),
        ]);
    }

    /**
     * 多价格类别。海讯文档未给出独立字段，若上游返回了价格数组则原样保存，
     * 否则用单一成本价生成默认类别。
     *
     * @param array<string, mixed> $row 海讯原始行
     * @return array<int, array{type:int, price:float}>
     */
    private static function priceTypes(array $row): array
    {
        $raw = $row['pricetype'] ?? $row['priceType'] ?? $row['prices'] ?? null;
        if (is_array($raw)) {
            $items = [];
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $type = (int)($item['type'] ?? $item['priceType'] ?? 0);
                    $price = (float)($item['price'] ?? $item['costprice'] ?? 0);
                    if ($type > 0 && $price > 0) {
                        $items[] = ['type' => $type, 'price' => round($price, 2)];
                    }
                }
            }
            if ($items !== []) {
                return $items;
            }
        }
        return [['type' => 1, 'price' => round((float)($row['costprice'] ?? 0), 2)]];
    }

    /**
     * @param mixed $value 原始值
     * @param int $max 最大长度
     */
    private static function text(mixed $value, int $max): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return mb_substr(trim(strip_tags((string)$value)), 0, $max);
    }
}