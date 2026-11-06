<?php

declare(strict_types=1);

namespace App\Domain\LiveSource;

use App\Domain\MarketCatalog;
use App\Domain\MarketSettlement;
use InvalidArgumentException;

/** Derives outcomes for one result revision; persistence must append, never overwrite. */
final class OutcomeEngine
{
    public function outcomes(string $status, ?int $home, ?int $away): array
    {
        if (!in_array($status, ['accepted', 'void', 'disputed'], true)) {
            throw new InvalidArgumentException('Unsupported result status.');
        }
        if ($status === 'accepted' && ($home === null || $away === null || $home < 0 || $away < 0)) {
            throw new InvalidArgumentException('Accepted results require non-negative scores.');
        }
        if ($status !== 'accepted' && ($home !== null || $away !== null)) {
            throw new InvalidArgumentException('Void or disputed results must not imply a score.');
        }

        $outcomes = [];
        foreach (array_keys(MarketCatalog::all()) as $market) {
            $outcomes[$market] = $status === 'accepted'
                ? (MarketSettlement::won($market, $home, $away) ? 'won' : 'lost')
                : $status;
        }

        return $outcomes;
    }
}
