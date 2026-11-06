<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ModelOperationsRepository;
use RuntimeException;

/** No alternate JSON/demo registry when canonical model persistence is absent. */
final class UnavailableModelOperationsRepository implements ModelOperationsRepository
{
    public function transaction(callable $work): mixed
    {
        throw new RuntimeException('Canonical model platform and migration 055 are unavailable.');
    }
}
