<?php

namespace app\common\service\smart_edit;

use app\common\model\smart_edit\SmartEditFont;
use app\common\service\FileService;

/**
 * 标题字体库。
 *
 * 字体元数据在 la_smart_edit_font，文件已预置在 OSS fonts/。
 * IMS Font 字段不可靠，统一走 FontURL：用表里的 uri + storage_* 拼可访问地址。
 * 平台预置 tenant_id=0，查询时绕过租户全局 scope。
 */
class SmartEditFontService
{
    /**
     * 前端下拉用的字体列表。
     *
     * @return array<int, array{key:string,label:string,preview:string}>
     */
    public static function options(): array
    {
        $rows = self::baseQuery()
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'key' => (string)$row['code'],
                'label' => (string)$row['name'],
                'preview' => (string)($row['preview'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * 取得某个预置字体的可访问地址（IMS FontURL）。
     *
     * 文件已在 OSS，不再下载或上传；拼好地址即可。
     * 返回空串表示该字体不可用。
     *
     * @param array  $config  SmartEditImsConfigService::require() 的结果（保留入参以兼容调用方）
     * @param string $key     字体 code
     * @param int    $timeout 兼容旧签名，已无下载逻辑
     */
    public static function prepare(array $config, string $key, int $timeout = 0): string
    {
        unset($timeout);
        $row = self::findByCode($key);
        if ($row === null) {
            return '';
        }

        $uri = ltrim((string)$row['uri'], '/');
        if ($uri === '' || str_contains($uri, '..')) {
            return '';
        }

        $url = FileService::getFileUrlByStorage(
            $uri,
            (string)($row['storage_scope'] ?? ''),
            (string)($row['storage_engine'] ?? ''),
            (string)($row['storage_domain'] ?? '')
        );
        if ($url !== '') {
            return $url;
        }

        // 表里有域名时再兜底拼一次，避免 FileService 在无请求上下文下拼不出地址
        $domain = trim((string)($row['storage_domain'] ?? ''));
        if ($domain === '') {
            $domain = (string)($config['domain'] ?? '');
        }
        return $domain === '' ? '' : SmartEditImsConfigService::joinUrl($domain, $uri);
    }

    /**
     * 字体的回退系统字体名；库内无别名时返回空。
     */
    public static function aliasOf(string $key): string
    {
        unset($key);
        return '';
    }

    /**
     * 字体是否在启用清单中。
     */
    public static function has(string $key): bool
    {
        return self::findByCode($key) !== null;
    }

    /**
     * 全部启用字体的 code 列表（命令行校验用）。
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return self::baseQuery()->order(['sort' => 'desc', 'id' => 'asc'])->column('code');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findByCode(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        $row = self::baseQuery()->where('code', $key)->findOrEmpty();
        if ($row->isEmpty()) {
            return null;
        }
        return $row->toArray();
    }

    /**
     * 平台预置字体查询：固定 tenant_id=0，不走租户全局 scope。
     */
    private static function baseQuery()
    {
        return SmartEditFont::withoutGlobalScope(['tenantId'])
            ->where('tenant_id', 0)
            ->where('status', 1)
            ->where('delete_time', 0);
    }
}
