<?php

declare(strict_types=1);

namespace App\Domain;

final class MarketSettlement
{
    public static function won(string $market, int $home, int $away): bool
    {
        return match ($market) {
            'over_15' => $home + $away >= 2,
            'over_25' => $home + $away >= 3,
            'under_25' => $home + $away <= 2,
            'under_35' => $home + $away <= 3,
            'btts_yes' => $home > 0 && $away > 0,
            'btts_no' => $home === 0 || $away === 0,
            default => throw new \InvalidArgumentException('Unsupported market.'),
        };
    }
}
