<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use DateTimeImmutable;
use InvalidArgumentException;

/** Issued by a trusted local financial quote authority, never deserialized from a browser's price/status. */
final class ApprovedInvoiceQuote
{
    public function __construct(
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly string $taxReference,
        public readonly string $invoiceReference,
        public readonly DateTimeImmutable $expiresAt
    ) {
        if (
            $subtotalMinor < 1 || $taxMinor < 0 || $totalMinor > 1000000000
            || $subtotalMinor > 1000000000 || $taxMinor > 1000000000
            || $totalMinor !== $subtotalMinor + $taxMinor || $currency !== 'NGN'
        ) {
            throw new InvalidArgumentException('Invalid exact approved invoice amounts.');
        }
    }
}
