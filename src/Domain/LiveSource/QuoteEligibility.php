<?php

declare(strict_types=1);

namespace App\Domain\LiveSource;

use DateTimeImmutable;
use InvalidArgumentException;

/** Point-in-time eligibility; callers must independently verify source rights and mapping. */
final class QuoteEligibility
{
    public function isEligible(
        DateTimeImmutable $kickoffAt,
        DateTimeImmutable $decisionAt,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $ingestedAt,
        DateTimeImmutable $expiresAt,
        int $publicationBufferSeconds
    ): bool {
        if ($publicationBufferSeconds < 0 || $availableAt < $observedAt || $ingestedAt < $observedAt
            || $expiresAt <= $observedAt) {
            throw new InvalidArgumentException('Invalid quote chronology or publication buffer.');
        }

        $cutoff = $kickoffAt->modify('-' . $publicationBufferSeconds . ' seconds');

        return $decisionAt < $cutoff
            && $observedAt <= $decisionAt
            && $availableAt <= $decisionAt
            && $ingestedAt <= $decisionAt
            && $decisionAt < $expiresAt;
    }
}
