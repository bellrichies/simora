<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\InvoiceQuoteAuthority;
use App\Domain\Payments\ApprovedInvoiceQuote;
use App\Domain\Payments\PaymentException;
use DateTimeImmutable;

final class UnavailableInvoiceQuoteAuthority implements InvoiceQuoteAuthority
{
    public function issue(int $ownerId, array $plan, string $operation, DateTimeImmutable $now): ApprovedInvoiceQuote
    {
        throw new PaymentException('financial_quote_unapproved', 503);
    }
}
