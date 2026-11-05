<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\BillingRepository;

final class DemoBillingRepository implements BillingRepository
{
    public function __construct(private string $fixturePath) {}

    public function getData(): array
    {
        if (!is_readable($this->fixturePath)) {
            throw new \RuntimeException('Billing demo data is unavailable.');
        }
        return require $this->fixturePath;
    }
}
