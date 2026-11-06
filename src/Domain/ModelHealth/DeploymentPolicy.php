<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use InvalidArgumentException;

final class DeploymentPolicy
{
    public function __construct(
        public readonly HealthPolicy $health,
        public readonly int $maximumLimitedBasisPoints,
        public readonly int $minimumShadowSeconds,
        public readonly int $maximumApprovalSeconds
    ) {
        if (
            $maximumLimitedBasisPoints < 1 || $maximumLimitedBasisPoints >= 10000
            || $minimumShadowSeconds < 1 || $maximumApprovalSeconds < 1
        ) {
            throw new InvalidArgumentException('Explicit limited rollout and approval limits required.');
        }
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([
            $this->health->digest(), $this->maximumLimitedBasisPoints,
            $this->minimumShadowSeconds, $this->maximumApprovalSeconds,
        ], JSON_THROW_ON_ERROR));
    }
}
