<?php

declare(strict_types=1);

namespace App\Contracts;

interface ChampionRepository
{
    /** One PDO transaction + scope/policy exclusion lock; callback receives ChampionStore. */
    public function transaction(callable $work): mixed;
}
