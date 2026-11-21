<?php

namespace app\common\service\geo;

use app\common\service\pricing\BusinessPriceService;
use InvalidArgumentException;

final class GeoDiagnosisPricing
{
    public static function quote(array $catalog, array $platforms, int $samplesPerPlatform, bool $screenshot, bool $conversationLink, $markup): array
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
            'reserved_points' => BusinessPriceService::pointsForMarkup($total / 100, $markup),
        ];
    }

    private static function cents($value, string $platform): int
    {
        $cents = filter_var($value, FILTER_VALIDATE_INT);
        if ($cents === false || $cents < 0) {
            throw new InvalidArgumentException("平台 {$platform} 的报价无效，请刷新后重试");
        }
        return $cents;
    }
}
