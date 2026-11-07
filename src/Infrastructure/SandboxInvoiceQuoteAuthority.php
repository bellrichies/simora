<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\InvoiceQuoteAuthority;
use App\Domain\Payments\ApprovedInvoiceQuote;
use DateTimeImmutable;

/** Test-mode simulation only: no real tax decision or legally issued invoice. Never bind for live payments. */
final class SandboxInvoiceQuoteAuthority implements InvoiceQuoteAuthority
{
    public function issue(int $ownerId, array $plan, string $operation, DateTimeImmutable $now): ApprovedInvoiceQuote
    {
        return new ApprovedInvoiceQuote(
            (int) $plan['amount_minor'],
            0,
            (int) $plan['amount_minor'],
            'NGN',
            'sandbox-only:no-real-tax',
            'sandbox-only:unissued-document',
            $now->modify('+15 minutes')
        );
    }
}
