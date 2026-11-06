<?php

declare(strict_types=1);

namespace App\Domain\ModelComparison;

use App\Domain\ModelHealth\DeploymentScope;
use DateTimeImmutable;

/** availableAt is verified durable-commit visibility metadata, never a client timestamp. */
final class ChampionRecord
{
    public function __construct(
        public readonly string $key,
        public readonly DeploymentScope $scope,
        public readonly string $policyVersion,
        public readonly string $reportSha256,
        public readonly ?string $modelVersion,
        public readonly string $reason,
        public readonly DateTimeImmutable $decidedAt,
        public readonly DateTimeImmutable $effectiveAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly int $approvedBy,
        public readonly array $signature,
        public readonly ?DateTimeImmutable $availableAt = null
    ) {
    }

    public function claims(): array
    {
        return ['schema' => 'simora.champion-decision.v1', 'decision_key' => $this->key,
            'scope_key' => $this->scope->key(), 'policy_version' => $this->policyVersion,
            'report_sha256' => $this->reportSha256, 'model_version' => $this->modelVersion,
            'reason' => $this->reason, 'decided_at' => $this->decidedAt->format('U.u'),
            'effective_at' => $this->effectiveAt->format('U.u'), 'expires_at' => $this->expiresAt->format('U.u'),
            'approved_by' => $this->approvedBy];
    }

    public function visible(DateTimeImmutable $asOf): bool
    {
        return $this->availableAt !== null && $this->availableAt >= $this->decidedAt
            && $this->availableAt <= $asOf && $this->decidedAt <= $asOf && $this->effectiveAt <= $asOf;
    }
}
