<?php

declare(strict_types=1);

namespace App\Contracts;

interface PlatformDataRepository
{
    /** Returns leagues, results, optional schedule, quota and demo pick previews.
     * Leagues contain stable platform-scoped id/name pairs; results, upcoming weeks and picks
     * carry the matching league_id. Results are stored selections, newest first.
     * See docs/data-contract.md for stable IDs, types and metric definitions.
     * @return array{leagues: array, results: array, upcoming?: array, quota?: array, picks?: array, source?: array}
     */
    public function find(string $platform): array;
}
