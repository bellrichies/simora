<?php

declare(strict_types=1);

namespace App\Contracts;

interface SimulationRepository
{
    /** One PDO snapshot transaction; callback receives SimulationStore. Roll back writes/audit together on failure. */
    public function transaction(callable $work): mixed;
}
