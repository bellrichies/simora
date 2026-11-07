<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Payments\ApprovedInvoiceQuote;
use DateTimeImmutable;

interface InvoiceQuoteAuthority
{
    /** Pure local approved tax/legal quote. No client prices or network calls inside the database transaction. */
    public function issue(int $ownerId, array $plan, string $operation, DateTimeImmutable $now): ApprovedInvoiceQuote;
}
