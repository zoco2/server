<?php

namespace app\common\service\geo;

use app\common\service\ConfigService;
use InvalidArgumentException;

final class GeoDiagnosisPricing
{
    private const MAX_POINTS = 99999999;

    public static function markupPercent(): float
    {
        $settings = ConfigService::getPlatform('business_price', 'settings')
            ?? ConfigService::get('business_price', 'settings', []);
        return (float)($settings['geo_markup_percent'] ?? 0);
    }

    public static function quote(array $catalog, array $platforms, int $samplesPerPlatform, bool $screenshot, bool $conversationLink, float $markup): array
    {
        $byId = array_column($catalog, null, 'id');
        $units = [];
        foreach ($platforms as $id) {
            $row = $byId[$id] ?? [];
            $pricing = $row['pricing'] ?? [];
            if (!$pricing || ($row['upstreamConfigured'] ?? true) === false || ($pricing['configured'] ?? true) === false) {
                throw new InvalidArgumentException("平台 {$id} 暂无可用报价");
            }
            $base = $pricing['baseCents'] ?? $pricing[($row['client'] ?? '') === 'mobile' ? 'mobileCents' : 'webCents'] ?? null;
            $unit = self::cents($base, $id);
            if ($screenshot) {
                $unit += self::cents($pricing['screenshotCents'] ?? null, $id);
            }
            if ($conversationLink && isset($pricing['conversationLinkCents'])) {
                $unit += self::cents($pricing['conversationLinkCents'], $id);
            }
            $units[$id] = $unit;
        }
        $total = array_sum($units) * $samplesPerPlatform;
        return [
            'status' => 'reserved',
            'unit_cents' => $units,
            'quoted_cents' => $total,
            'markup_percent' => $markup,
            'reserved_points' => self::points($total, $markup),
        ];
    }

    /** 1 元 = 100 点。上游直接以分计价，溢价后按整个批次四舍五入。 */
    public static function points(int $cents, float $markup): int
    {
        if ($cents < 0 || $cents > self::MAX_POINTS || !is_finite($markup) || $markup < 0 || $markup > 9900) {
            throw new InvalidArgumentException('GEO 检测报价或溢价率无效');
        }
        $points = intdiv($cents * (10000 + (int)round($markup * 100)) + 5000, 10000);
        if ($points > self::MAX_POINTS) {
            throw new InvalidArgumentException('GEO 检测价格超过单笔上限');
        }
        return $points;
    }

    private static function cents($value, string $platform): int
    {
        $cents = filter_var($value, FILTER_VALIDATE_INT);
        if ($cents === false || $cents < 0 || $cents > self::MAX_POINTS) {
            throw new InvalidArgumentException("平台 {$platform} 的报价无效，请刷新后重试");
        }
        return $cents;
    }
}
