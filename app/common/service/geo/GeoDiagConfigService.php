<?php

namespace app\common\service\geo;

use app\common\service\ConfigService;
use InvalidArgumentException;
use RuntimeException;

final class GeoDiagConfigService
{
    public const DEFAULTS = ['api_key' => ''];

    public static function getPlatform(): array
    {
        $data = ConfigService::getPlatform('geo_diag', 'provider', self::DEFAULTS);
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
}
