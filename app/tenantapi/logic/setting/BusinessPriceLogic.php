<?php

namespace app\tenantapi\logic\setting;

use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\service\ConfigService;
use think\facade\Db;

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
        Db::transaction(static function () use ($params) {
            // 配置表没有唯一键，同一租户首次保存时串行创建配置。
            Tenant::where('id', request()->tenantId)->lock(true)->findOrFail();
            ConfigService::set('business_price', 'settings', [
                'smart_edit_points' => (int)$params['smart_edit_points'],
                'media_markup_percent' => (float)$params['media_markup_percent'],
                'geo_markup_percent' => (float)$params['geo_markup_percent'],
            ]);
        });
    }
}
