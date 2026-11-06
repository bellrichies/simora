<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\SourceScopedId;

/** Deterministic identity translation only; never imports fixture predictions or infers source rights. */
final class LegacyCatalogAdapter
{
    public function leagueId(string $source, string $legacyId): string
    {
        return SourceScopedId::fromExternal('league', $source, 'league', $legacyId);
    }

    public function roundId(string $source, string $legacyLeagueId, string $legacyWeekId): string
    {
        return SourceScopedId::fromExternal('round', $source, $legacyLeagueId, $legacyWeekId);
    }

    public function matchId(string $source, string $legacyLeagueId, string $legacyMatchId): string
    {
        return SourceScopedId::fromExternal('match', $source, $legacyLeagueId, $legacyMatchId);
    }
}
