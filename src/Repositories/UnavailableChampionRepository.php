<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ChampionRepository;
use RuntimeException;

final class UnavailableChampionRepository implements ChampionRepository
{
    public function transaction(callable $work): mixed
    {
        throw new RuntimeException('Canonical comparison/champion registry and publication lineage are unavailable.');
    }
}
