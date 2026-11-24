<?php

namespace app\common\service\geo;

use app\common\service\ConfigService;
use app\common\service\TenantConfigTable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class GeoDiagConfigService
{
    public const DEFAULTS = ['api_key' => ''];

    private static function query(int $tenantId, bool $lock = false)
    {
        return Db::name(TenantConfigTable::forTenant($tenantId, $lock))->where([
            'tenant_id' => $tenantId, 'type' => 'geo_diag', 'name' => 'provider',
        ]);
    }

    public static function get(int $tenantId): array
    {
        if ($tenantId === 0) {
            $data = ConfigService::getPlatform('geo_diag', 'provider');
        } else {
            $raw = self::query($tenantId)->value('value');
            $data = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($data === null) {
            return self::DEFAULTS;
        }
        if (!is_string($data['api_key'] ?? null)) {
            throw new RuntimeException('GEO 检测配置数据异常');
        }
        return ['api_key' => $data['api_key']];
    }

    public static function savePlatform(array $input): void
    {
        ConfigService::set('geo_diag', 'provider', self::validate($input));
    }

    private static function validate(array $input): array
    {
        $key = is_string($input['api_key'] ?? null) ? trim($input['api_key']) : '';
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('请填写有效的 GEO 检测 API Key');
        }
        return ['api_key' => $key];
    }

    public static function save(int $tenantId, array $input, bool $onlyIfMissing = false): array
    {
        $config = self::validate($input);
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
