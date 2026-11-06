<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final class PayloadEvidence
{
    public function hash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public function validateTimes(DateTimeImmutable $observedAt, DateTimeImmutable $availableAt, DateTimeImmutable $ingestedAt): void
    {
        if ($availableAt < $observedAt || $ingestedAt < $observedAt) {
            throw new InvalidArgumentException('Evidence cannot be available or ingested before observation.');
        }
    }
}
