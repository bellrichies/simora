<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Simulation\SimulationScope;
use App\Domain\Simulation\SimulationLimits;
use DateTimeImmutable;

interface SimulationStore
{
    /** Recheck active identity, RBAC, model-specific entitlement and quotas under the transaction. */
    public function authorized(int $ownerId, SimulationScope $scope, DateTimeImmutable $now): bool;

    /** Resolve paid model/market policy, outcome cap and history floor from the current database snapshot. */
    public function limits(int $ownerId, SimulationScope $scope, DateTimeImmutable $now): SimulationLimits;

    /** Unique owner+key, immutable stored fingerprint/report; lock against concurrent inserts. */
    public function existing(int $ownerId, string $key): ?array;

    /**
     * Last N actual published selections, selected without outcome filtering, oldest kickoff first.
     * Pin latest accepted settlement revision visible at asOf and provenance/verified fee contracts.
     * Exclude synthetic partitions; check publication/source/model access, retained rights and row ownership.
     * Never export raw outcomes as if published picks or quietly omit unresolved/correlated/invalid-price rows.
     */
    public function history(SimulationScope $scope, DateTimeImmutable $asOf, int $lastN): array;

    /** Append canonical run+entries+audit atomically. Enforce owner/key uniqueness and no update of previous runs. */
    public function append(int $ownerId, string $key, string $fingerprint, array $report): void;

    /** Query by both owner and immutable run ID, never fetch then authorize. */
    public function owned(int $ownerId, string $runId): ?array;
}
