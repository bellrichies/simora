<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\PaymentProvider;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\ProviderEvidence;
use App\Domain\Payments\VerifiedWebhook;

final class UnavailablePaymentProvider implements PaymentProvider
{
    public function verifyWebhook(string $rawBody, array $headers): VerifiedWebhook
    {
        throw new PaymentException('payment_provider_unapproved', 503);
    }

    public function lookup(string $merchantReference): ProviderEvidence
    {
        throw new PaymentException('payment_provider_unapproved', 503);
    }

    public function initialize(string $reference, string $email, int $amountMinor, string $callbackUrl): array
    {
        throw new PaymentException('payment_provider_unapproved', 503);
    }
}
