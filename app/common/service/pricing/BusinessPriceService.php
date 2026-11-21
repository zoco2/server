<?php

namespace app\common\service\pricing;

use app\common\service\ConfigService;
use InvalidArgumentException;
use RuntimeException;

final class BusinessPriceService
{
    public const POINTS_PER_YUAN = 100;
    private const MAX_POINTS = 99999999;
    private const MAX_MARKUP_PERCENT = 9900;

    private static function settings(): array
    {
        return ConfigService::get('business_price', 'settings', []);
    }

    public static function smartEditPoints(): int
    {
        return (int)(self::settings()['smart_edit_points'] ?? 0);
    }

    public static function mediaMarkupRatio(): float
    {
        return 1 + (float)(self::settings()['media_markup_percent'] ?? 0) / 100;
    }

    public static function geoMarkupPercent(): float
    {
        return (float)(self::settings()['geo_markup_percent'] ?? 0);
    }

    public static function yuanToPoints(mixed $yuan): int
    {
        if (!is_numeric($yuan) || !is_finite((float)$yuan) || $yuan < 0 || $yuan > 9999999999) {
            throw new InvalidArgumentException('上游金额无效');
        }
        return (int)round((float)$yuan * self::POINTS_PER_YUAN);
    }

    public static function pointsForMarkup(mixed $yuan, float $percent): int
    {
        if (!is_finite($percent) || $percent < 0 || $percent > self::MAX_MARKUP_PERCENT) {
            throw new InvalidArgumentException('溢价率无效');
        }
        $cents = self::yuanToPoints($yuan);
        $basisPoints = 10000 + (int)round($percent * 100);
        $points = intdiv($cents * $basisPoints + 5000, 10000);
        if ($points > self::MAX_POINTS) {
            throw new RuntimeException('本次计费金额超过单笔上限');
        }
        return $points;
    }
}
