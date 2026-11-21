<?php

namespace app\tenantapi\logic\setting;

use app\common\logic\BaseLogic;
use app\common\service\ConfigService;

class BusinessPriceLogic extends BaseLogic
{
    public static function getConfig(): array
    {
        return ConfigService::get('business_price', 'settings', [
            'smart_edit_points' => 0,
            'media_markup_percent' => 0,
            'geo_markup_percent' => 0,
        ]);
    }

    public static function setConfig(array $params): void
    {
        ConfigService::set('business_price', 'settings', [
            'smart_edit_points' => (int)$params['smart_edit_points'],
            'media_markup_percent' => (float)$params['media_markup_percent'],
            'geo_markup_percent' => (float)$params['geo_markup_percent'],
        ]);
    }
}
