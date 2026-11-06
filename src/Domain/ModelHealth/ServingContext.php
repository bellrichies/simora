<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use DateTimeImmutable;

/** Values must be resolved server-side from active session and entitlement state. */
final class ServingContext
{
    public function __construct(
        public readonly string $subjectId,
        private readonly DateTimeImmutable $expiresAt,
        private readonly array $entitlements
    ) {
    }

    public function eligible(DateTimeImmutable $now): bool
    {
        return $this->subjectId !== '' && $this->expiresAt > $now
            && in_array('limited_analytics', $this->entitlements, true);
    }
}
