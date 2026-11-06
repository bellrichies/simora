<?php

declare(strict_types=1);

namespace App\Domain\ModelComparison;

use DateTimeImmutable;

/** Only a verified server-side session and entitlement resolver may construct this context. */
final class ComparisonViewer
{
    public function __construct(
        public readonly string $subjectId,
        private readonly DateTimeImmutable $expiresAt,
        private readonly array $entitlements
    ) {
    }

    public function allowed(DateTimeImmutable $now): bool
    {
        return $this->subjectId !== '' && $this->expiresAt > $now
            && in_array('model_comparisons', $this->entitlements, true);
    }
}
