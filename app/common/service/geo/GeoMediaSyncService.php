<?php

namespace app\common\service\geo;

use app\common\model\geo\GeoMedia;
use app\common\service\ConfigService;
use Exception;
use think\facade\Log;

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

    /** @var array<int, string> 资源类型字典，与海讯 list{n}ON 的 n 对应。 */
    public const RESOURCE_TYPES = AigcGeoManuscriptService::RESOURCE_TYPES;

    /**
     * 同步指定资源类型。返回统计信息。
     *
     * @param int $tenantId 租户 ID
     * @param int $type 资源类型 1~15
     * @param array{full?:bool} $options full=true 时把本次未出现的资源标记为 gone
     * @return array{type:int, total:int, created:int, updated:int, gone:int, pages:int}
     */
    public static function syncType(int $tenantId, int $type, array $options = []): array
    {
        if (!isset(self::RESOURCE_TYPES[$type])) {
            throw new Exception('资源类型无效');
        }
        $openToken = AigcGeoManuscriptService::accessToken($tenantId);
        $seen = [];
        $created = 0;
        $updated = 0;
        $total = 0;
        $page = 1;
        $pages = 0;

        do {
            $payload = self::fetchPage($openToken, $type, $page);
            $total = (int)$payload['total'];
            $list = $payload['list'];
            $pages++;
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sourceid = trim((string)($row['sourceid'] ?? ''));
                if ($sourceid === '') {
                    continue;
                }
                $seen[$sourceid] = true;
                if (self::saveRow($tenantId, $type, $sourceid, $row)) {
                    $created++;
                } else {
                    $updated++;
                }
            }
            $page++;
        } while ($list !== [] && $page <= max(1, (int)ceil($total / self::PAGE_SIZE)) && $page <= 200);

        $gone = 0;
        if (!empty($options['full']) && $seen !== []) {
            $gone = self::markGone($tenantId, $type, array_keys($seen));
        }

        return [
            'type' => $type,
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'gone' => $gone,
            'pages' => $pages,
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
                $state = AigcGeoManuscriptService::resourceState($openToken, $sourceId);
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
     * 同步全部 15 类资源。
     *
     * @param int $tenantId 租户 ID
     * @param array<int> $types 指定类型；空数组表示全部
     * @return array<int, array<string, mixed>>
     */
    public static function syncAll(int $tenantId, array $types = []): array
    {
        $types = $types === [] ? array_keys(self::RESOURCE_TYPES) : $types;
        $result = [];
        foreach ($types as $type) {
            try {
                $result[$type] = self::syncType($tenantId, (int)$type, ['full' => true]);
            } catch (Exception $e) {
                $result[$type] = ['type' => (int)$type, 'error' => $e->getMessage()];
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
     * 抓取一页，失败重试。
     *
     * @param string $openToken 访问令牌
     * @param int $type 资源类型
     * @param int $page 页码
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    private static function fetchPage(string $openToken, int $type, int $page): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return AigcGeoManuscriptService::resources($openToken, $type, $page, self::PAGE_SIZE);
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
        $text = trim(strip_tags((string)$value));
        return mb_substr($text, 0, $max);
    }
}