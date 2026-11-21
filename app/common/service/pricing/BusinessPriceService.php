<?php

namespace app\common\service\pricing;

use app\common\service\TenantConfigTable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class BusinessPriceService
{
    public const POINTS_PER_YUAN = 100;
    private const MAX_POINTS = 99999999;
    private const MAX_MARKUP_PERCENT = 9900;

    public static function get(int $tenantId): array
    {
        $table = TenantConfigTable::forTenant($tenantId);
        $prices = Db::name($table)->where(['tenant_id' => $tenantId, 'type' => 'business_price'])
            ->column('value', 'name');
        // 媒体目录、价格类别和已有后台均使用 geo_manuscript.markup 倍率。
        $ratio = Db::name($table)->where([
            'tenant_id' => $tenantId, 'type' => 'geo_manuscript', 'name' => 'markup',
        ])->value('value');
        return [
            'smart_edit_points' => (int)($prices['smart_edit_points'] ?? 0),
            'geo_markup_percent' => (float)($prices['geo_markup_percent'] ?? 0),
            'media_markup_percent' => is_numeric($ratio) && $ratio > 0 ? round(($ratio - 1) * 100, 2) : 0,
        ];
    }

    public static function save(int $tenantId, array $input): void
    {
        $points = self::number($input['smart_edit_points'] ?? null, 0, self::MAX_POINTS, 0, '剪辑每条点数');
        $geo = self::number($input['geo_markup_percent'] ?? null, 0, self::MAX_MARKUP_PERCENT, 2, 'GEO 检测溢价率');
        $media = self::number($input['media_markup_percent'] ?? null, -99.9, self::MAX_MARKUP_PERCENT, 2, '媒体发稿溢价率');
        Db::transaction(static function () use ($tenantId, $points, $geo, $media) {
            $table = TenantConfigTable::forTenant($tenantId, true);
            if ($media < 0 && $media !== (float)self::get($tenantId)['media_markup_percent']) {
                throw new InvalidArgumentException('媒体发稿溢价率不能为负数');
            }
            foreach ([
                ['business_price', 'smart_edit_points', (int)$points],
                ['business_price', 'geo_markup_percent', $geo],
                ['geo_manuscript', 'markup', round(1 + $media / 100, 4)],
            ] as [$type, $name, $value]) {
                $key = ['tenant_id' => $tenantId, 'type' => $type, 'name' => $name];
                $id = Db::name($table)->where($key)->value('id');
                $data = ['value' => (string)$value, 'update_time' => time()];
                if ($id) {
                    Db::name($table)->where('id', $id)->update($data);
                } else {
                    Db::name($table)->insert($key + $data + ['create_time' => time()]);
                }
            }
        });
    }

    private static function number(mixed $value, float $min, float $max, int $precision, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || $value < $min || $value > $max
            || round((float)$value, $precision) !== (float)$value) {
            throw new InvalidArgumentException($label . '超出范围或精度无效');
        }
        return (float)$value;
    }

    public static function smartEditPoints(int $tenantId): int
    {
        return self::get($tenantId)['smart_edit_points'];
    }

    public static function yuanToPoints(mixed $yuan): int
    {
        if (!is_numeric($yuan) || !is_finite((float)$yuan) || $yuan < 0 || $yuan > 9999999999) {
            throw new InvalidArgumentException('上游金额无效');
        }
        return (int)round((float)$yuan * self::POINTS_PER_YUAN);
    }

    public static function pointsForMarkup(mixed $yuan, mixed $percent): int
    {
        $rate = self::number($percent, -99.99, self::MAX_MARKUP_PERCENT, 2, '溢价率');
        $cents = self::yuanToPoints($yuan);
        // 上游以分报价，溢价率支持两位小数。仅在总价换算点数时四舍五入。
        $basisPoints = 10000 + (int)round($rate * 100);
        $points = intdiv($cents * $basisPoints + 5000, 10000);
        if ($points > self::MAX_POINTS) {
            throw new RuntimeException('本次计费金额超过单笔上限');
        }
        return $points;
    }
}
