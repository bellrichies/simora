<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\ModelHealth\DeploymentAssignment;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\HealthObservation;
use App\Domain\ModelHealth\ModelRelease;
use DateTimeImmutable;

/** Transaction-only unit of work; a production adapter must enforce real FKs. */
interface ModelOperationsStore
{
    public function version(string $id): ?ModelRelease;

    public function saveVersion(ModelRelease $version): void;

    /** @return list<DeploymentAssignment> Include ended assignments for rollback lineage. */
    public function assignments(): array;

    public function saveAssignment(DeploymentAssignment $assignment): void;

    public function health(string $versionId, DeploymentScope $scope): ?HealthObservation;

    /** Append the observation immutably; update the latest projection only monotonically. */
    public function saveHealth(HealthObservation $observation, array $reasons): void;

    /** Current grant, retention, mapping and public-release rights; no cached assertion. */
    public function sourceAuthorized(string $versionId, DeploymentScope $scope, DateTimeImmutable $now): bool;

    public function approverAuthorized(int $userId, DateTimeImmutable $now): bool;

    public function approval(string $id): ?array;

    public function saveApproval(string $id, array $approval): void;

    public function command(string $key): ?array;

    /** Append audit + unique idempotent outbox event + result within the same transaction. */
    public function appendOperation(string $key, string $fingerprint, array $event, array $result): void;
}
