<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\ModelComparison\ChampionRecord;
use App\Domain\ModelHealth\DeploymentScope;
use DateTimeImmutable;

interface ChampionStore
{
    public function reportEnvelope(string $sha256): ?string;

    /** Independent canonical approval, rights, release gates and supersession check. */
    public function reportApproved(string $sha256, DateTimeImmutable $at): bool;

    public function approverAuthorized(int $userId, DateTimeImmutable $at): bool;

    public function decision(string $key): ?array;

    /** Immutable record, unique key + scope/policy/decision clock; audit/outbox/result atomic. */
    public function append(ChampionRecord $record, string $fingerprint, array $audit): void;

    /** @return list<ChampionRecord> Attach verified actual commit visibility, not requested times. */
    public function decisions(DeploymentScope $scope, string $policyVersion): array;

    /** Must validate as-of lineage AND current health/rights/permissions, never choose a fallback. */
    public function modelEligible(
        string $modelVersion,
        DeploymentScope $scope,
        DateTimeImmutable $selectionAsOf,
        DateTimeImmutable $now
    ): bool;
}
