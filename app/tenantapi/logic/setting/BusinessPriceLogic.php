<?php

namespace app\tenantapi\logic\setting;

use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\model\TenantConfig;
use app\common\service\ConfigService;
use think\facade\Db;

class BusinessPriceLogic extends BaseLogic
{
    private const CONFIG_TYPE = 'business_price';
    private const CONFIG_NAME = 'settings';

    public static function getConfig(): array
    {
        return ConfigService::get(self::CONFIG_TYPE, self::CONFIG_NAME, [
            'smart_edit_points' => 0,
            'media_markup_percent' => 0,
            'geo_markup_percent' => 0,
        ]);
    }

    public static function setConfig(array $params): void
    {
        $prices = [
            'smart_edit_points' => (int)$params['smart_edit_points'],
            'media_markup_percent' => (float)$params['media_markup_percent'],
            'geo_markup_percent' => (float)$params['geo_markup_percent'],
        ];

        Db::transaction(static function () use ($prices) {
            // 配置表没有唯一键，同一租户首次保存时串行创建配置。
            $tenant = Tenant::where('id', request()->tenantId)->lock(true)->findOrFail();
            $key = ['type' => self::CONFIG_TYPE, 'name' => self::CONFIG_NAME];

            // 分表中已有的价格按租户模型作用域更新，避免与共享表的主键混用。
            if ((int)$tenant->tactics === 1 && TenantConfig::where($key)->value('id') !== null) {
                TenantConfig::where($key)->update([
                    'value' => json_encode($prices, JSON_THROW_ON_ERROR),
                    'update_time' => time(),
                ]);
                return;
            }

            ConfigService::set(self::CONFIG_TYPE, self::CONFIG_NAME, $prices);
        });
    }
}
