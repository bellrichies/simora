<?php

declare(strict_types=1);

namespace App\Contracts;

interface BillingRepository
{
    /** Returns the billing read model. Amounts are integer minor units. */
    public function getData(): array;
}
