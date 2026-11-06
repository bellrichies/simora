<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use DateTimeImmutable;
use InvalidArgumentException;

/** Projection of a canonical version and its immutable validated release evidence. */
final class ModelRelease
{
    public const GATES = [
        'lineage', 'point_in_time', 'untouched_holdout', 'baseline', 'calibration',
        'uncertainty', 'eight_markets', 'source_rights', 'security',
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $engine,
        public readonly string $status,
        public readonly int $createdBy,
        public readonly string $artifactSha256,
        public readonly string $evidenceSha256,
        public readonly DateTimeImmutable $validatedAt,
        public readonly DeploymentScope $scope,
        public readonly array $gates,
        public readonly bool $releaseEligible,
        public readonly string $operatorLabel
    ) {
        if (
            $id === '' || !in_array($engine, ['spe', 'mlpe', 'beve'], true) || $createdBy < 1
            || !in_array($status, ['draft', 'trained', 'validated', 'shadow', 'approved', 'active',
                'suspended', 'retired'], true)
        ) {
            throw new InvalidArgumentException('Invalid model release identity or state.');
        }
        foreach ([$artifactSha256, $evidenceSha256] as $sha) {
            if (preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
                throw new InvalidArgumentException('Immutable release digests required.');
            }
        }
    }

    public function eligible(DateTimeImmutable $now): bool
    {
        if (
            !$this->releaseEligible || $this->validatedAt > $now || $this->operatorLabel !== $this->engine
            || !in_array($this->status, ['validated', 'shadow', 'approved', 'active'], true)
        ) {
            return false;
        }
        foreach (self::GATES as $gate) {
            if (($this->gates[$gate] ?? null) !== true) {
                return false;
            }
        }
        return true;
    }

    public function suspended(): self
    {
        return new self(
            $this->id,
            $this->engine,
            'suspended',
            $this->createdBy,
            $this->artifactSha256,
            $this->evidenceSha256,
            $this->validatedAt,
            $this->scope,
            $this->gates,
            $this->releaseEligible,
            $this->operatorLabel
        );
    }
}
