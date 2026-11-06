<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use DateTimeImmutable;
use InvalidArgumentException;

/** Trusted collector DTO, not an HTTP input or a source-rights assertion. */
final class HealthObservation
{
    public function __construct(
        public readonly string $versionId,
        public readonly DeploymentScope $scope,
        public readonly string $artifactSha256,
        public readonly string $featureSchemaSha256,
        public readonly DateTimeImmutable $windowStart,
        public readonly DateTimeImmutable $windowEnd,
        public readonly DateTimeImmutable $observedAt,
        public readonly DateTimeImmutable $availableAt,
        public readonly DateTimeImmutable $ingestedAt,
        public readonly int $sampleCount,
        public readonly array $metrics,
        public readonly array $featureCounts,
        public readonly bool $artifactVerified,
        public readonly bool $dependenciesHealthy,
        public readonly string $manifestSha256
    ) {
        if (
            $versionId === '' || $sampleCount < 0 || $windowStart >= $windowEnd
            || $windowEnd > $observedAt || $availableAt < $observedAt || $ingestedAt < $observedAt
        ) {
            throw new InvalidArgumentException('Invalid health observation chronology or count.');
        }
        foreach ([$artifactSha256, $featureSchemaSha256, $manifestSha256] as $sha) {
            if (preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
                throw new InvalidArgumentException('Health digests must be SHA-256.');
            }
        }
    }
}
