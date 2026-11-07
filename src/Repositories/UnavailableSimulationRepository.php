<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SimulationRepository;
use RuntimeException;

final class UnavailableSimulationRepository implements SimulationRepository
{
    public function transaction(callable $work): mixed
    {
        throw new RuntimeException('Immutable published history and canonical simulation persistence are unavailable.');
    }
}
