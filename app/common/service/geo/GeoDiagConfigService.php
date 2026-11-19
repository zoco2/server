<?php

namespace app\common\service\geo;

use app\common\service\TenantConfigTable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class GeoDiagConfigService
{
    public const DEFAULTS = [
        'api_key' => '', 'ssl_verify' => false,
    ];

    private static function query(int $tenantId, bool $lock = false)
    {
        return Db::name(TenantConfigTable::forTenant($tenantId, $lock))->where([
            'tenant_id' => $tenantId, 'type' => 'geo_diag', 'name' => 'provider',
        ]);
    }

    public static function get(int $tenantId): array
    {
        $raw = self::query($tenantId)->value('value');
        if ($raw === null) {
            return self::DEFAULTS;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['api_key'] ?? null)
            || !is_bool($data['ssl_verify'] ?? null)) {
            throw new RuntimeException('GEO 检测配置数据异常');
        }
        return array_intersect_key($data, self::DEFAULTS);
    }

    public static function save(int $tenantId, array $input, bool $onlyIfMissing = false): array
    {
        $key = is_string($input['api_key'] ?? null) ? trim($input['api_key']) : '';
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('请填写有效的 GEO 检测 API Key');
        }
        if (!in_array($input['ssl_verify'] ?? null, [true, false, 1, 0, '1', '0'], true)) {
            throw new InvalidArgumentException('请选择是否校验 HTTPS 证书');
        }
        $config = ['api_key' => $key, 'ssl_verify' => (bool)$input['ssl_verify']];
        return Db::transaction(function () use ($tenantId, $config, $onlyIfMissing) {
            $query = self::query($tenantId, true);
            $id = (clone $query)->value('id');
            if ($id && $onlyIfMissing) {
                throw new RuntimeException('该租户已有 GEO 检测配置，已跳过导入');
            }
            $row = ['value' => json_encode($config, JSON_THROW_ON_ERROR), 'update_time' => time()];
            if ($id) {
                $query->where('id', $id)->update($row);
            } else {
                $query->insert($row + [
                    'tenant_id' => $tenantId, 'type' => 'geo_diag', 'name' => 'provider', 'create_time' => time(),
                ]);
            }
            return $config;
        });
    }
}
