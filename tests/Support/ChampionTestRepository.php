<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Contracts\ChampionRepository;
use App\Contracts\ChampionStore;
use App\Domain\ModelComparison\ChampionRecord;
use App\Domain\ModelHealth\DeploymentScope;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** Isolated metadata assertions, never an operational champion registry. */
final class ChampionTestRepository implements ChampionRepository, ChampionStore
{
    public array $reports = [];
    public array $records = [];
    public array $audit = [];
    public array $eligible = ['alpha-v1' => true, 'beta-v1' => true];
    public bool $reportApproval = true;
    public bool $approverPermission = true;
    public bool $failAudit = false;
    public int $visibilityDelaySeconds = 2;
    private bool $locked = false;

    public function transaction(callable $work): mixed
    {
        if ($this->locked) {
            throw new RuntimeException('Test champion scope lock already held.');
        }
        $this->locked = true;
        $saved = [$this->records, $this->audit];
        try {
            return $work($this);
        } catch (Throwable $exception) {
            [$this->records, $this->audit] = $saved;
            throw $exception;
        } finally {
            $this->locked = false;
        }
    }

    public function reportEnvelope(string $sha256): ?string
    {
        return $this->reports[$sha256] ?? null;
    }

    public function reportApproved(string $sha256, DateTimeImmutable $at): bool
    {
        return $this->reportApproval && isset($this->reports[$sha256]);
    }

    public function approverAuthorized(int $userId, DateTimeImmutable $at): bool
    {
        return $userId === 2 && $this->approverPermission;
    }

    public function decision(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    public function append(ChampionRecord $record, string $fingerprint, array $audit): void
    {
        foreach ($this->records as $row) {
            $prior = $row['record'];
            if (
                $prior->key === $record->key || ($prior->scope->key() === $record->scope->key()
                && $prior->policyVersion === $record->policyVersion && $prior->decidedAt == $record->decidedAt)
            ) {
                throw new RuntimeException('Duplicate champion key or scope/decision clock.');
            }
        }
        $visible = new ChampionRecord(
            $record->key,
            $record->scope,
            $record->policyVersion,
            $record->reportSha256,
            $record->modelVersion,
            $record->reason,
            $record->decidedAt,
            $record->effectiveAt,
            $record->expiresAt,
            $record->approvedBy,
            $record->signature,
            $record->decidedAt->modify('+' . $this->visibilityDelaySeconds . ' seconds')
        );
        $this->records[$record->key] = ['fingerprint' => $fingerprint, 'record' => $visible];
        if ($this->failAudit) {
            throw new RuntimeException('Injected champion audit/outbox failure.');
        }
        $this->audit[] = $audit;
    }

    public function decisions(DeploymentScope $scope, string $policyVersion): array
    {
        return array_map(static fn ($row) => $row['record'], array_values($this->records));
    }

    public function modelEligible(
        string $modelVersion,
        DeploymentScope $scope,
        DateTimeImmutable $selectionAsOf,
        DateTimeImmutable $now
    ): bool {
        return $this->eligible[$modelVersion] ?? false;
    }
}
