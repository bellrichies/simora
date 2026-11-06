<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Contracts\ModelOperationsRepository;
use App\Contracts\ModelOperationsStore;
use App\Domain\ModelHealth\DeploymentAssignment;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\HealthObservation;
use App\Domain\ModelHealth\ModelRelease;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** Test double only: no claim of MySQL locking, durable storage or real source rights. */
final class ModelOperationsTestRepository implements ModelOperationsRepository, ModelOperationsStore
{
    public array $versions = [];
    public array $deployments = [];
    public array $observations = [];
    public array $healthEvents = [];
    public array $approvals = [];
    public array $commands = [];
    public array $events = [];
    public array $deniedScopes = [];
    public array $approvers = [2 => true];
    public bool $sourceAllowed = true;
    public bool $failAppend = false;
    private bool $locked = false;

    public function transaction(callable $work): mixed
    {
        if ($this->locked) {
            throw new RuntimeException('Test registry lock already held.');
        }
        $snapshot = [$this->versions, $this->deployments, $this->observations, $this->healthEvents,
            $this->approvals, $this->commands, $this->events];
        $this->locked = true;
        try {
            return $work($this);
        } catch (Throwable $exception) {
            [$this->versions, $this->deployments, $this->observations, $this->healthEvents,
                $this->approvals, $this->commands, $this->events] = $snapshot;
            throw $exception;
        } finally {
            $this->locked = false;
        }
    }

    public function version(string $id): ?ModelRelease
    {
        return $this->versions[$id] ?? null;
    }

    public function saveVersion(ModelRelease $version): void
    {
        $this->versions[$version->id] = $version;
    }

    public function assignments(): array
    {
        return array_values($this->deployments);
    }

    public function saveAssignment(DeploymentAssignment $assignment): void
    {
        $this->deployments[$assignment->id] = $assignment;
    }

    public function health(string $versionId, DeploymentScope $scope): ?HealthObservation
    {
        return $this->observations[$versionId . ':' . $scope->key()] ?? null;
    }

    public function saveHealth(HealthObservation $observation, array $reasons): void
    {
        $this->healthEvents[] = [$observation, $reasons];
        $key = $observation->versionId . ':' . $observation->scope->key();
        if (!isset($this->observations[$key]) || $this->observations[$key]->observedAt < $observation->observedAt) {
            $this->observations[$key] = $observation;
        }
    }

    public function sourceAuthorized(string $versionId, DeploymentScope $scope, DateTimeImmutable $now): bool
    {
        return $this->sourceAllowed && !in_array($scope->key(), $this->deniedScopes, true);
    }

    public function approverAuthorized(int $userId, DateTimeImmutable $now): bool
    {
        return $this->approvers[$userId] ?? false;
    }

    public function approval(string $id): ?array
    {
        return $this->approvals[$id] ?? null;
    }

    public function saveApproval(string $id, array $approval): void
    {
        $prior = $this->approvals[$id] ?? null;
        if (
            $prior !== null && ($prior['claims'] !== $approval['claims']
            || $prior['signature'] !== $approval['signature'] || ($prior['revoked'] && !$approval['revoked']))
        ) {
            throw new RuntimeException('Immutable approval or revocation violated.');
        }
        $this->approvals[$id] = $approval;
    }

    public function command(string $key): ?array
    {
        return $this->commands[$key] ?? null;
    }

    public function appendOperation(string $key, string $fingerprint, array $event, array $result): void
    {
        if ($this->failAppend || isset($this->commands[$key])) {
            throw new RuntimeException('Injected audit/outbox failure or duplicate command.');
        }
        $this->events[] = $event;
        $this->commands[$key] = ['fingerprint' => $fingerprint, 'result' => $result];
    }
}
