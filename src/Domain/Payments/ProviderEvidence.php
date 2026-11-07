<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use DateTimeImmutable;
use InvalidArgumentException;

/** Provider adapter output after its actual contract verification, not an API request DTO. */
final class ProviderEvidence
{
    public function __construct(
        public readonly string $provider,
        public readonly string $account,
        public readonly string $environment,
        public readonly string $eventId,
        public readonly string $merchantReference,
        public readonly string $providerReference,
        public readonly string $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly DateTimeImmutable $occurredAt,
        public readonly DateTimeImmutable $verifiedAt,
        public readonly string $customerEmail = ''
    ) {
        foreach ([$provider, $account, $eventId, $merchantReference, $providerReference] as $value) {
            if (preg_match('/^[a-zA-Z0-9_.:=-]{1,128}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid verified provider identity.');
            }
        }
        if (
            !in_array($status, ['pending', 'failed', 'succeeded', 'refunded', 'disputed'], true)
            || $amountMinor < 1 || $amountMinor > 1000000000 || !preg_match('/^[A-Z]{3}$/D', $currency)
            || !in_array($environment, ['sandbox', 'live'], true)
        ) {
            throw new InvalidArgumentException('Invalid verified payment evidence.');
        }
    }

    public function claims(): array
    {
        return ['provider' => $this->provider, 'account' => $this->account, 'environment' => $this->environment,
            'event_id' => $this->eventId, 'merchant_reference' => $this->merchantReference,
            'provider_reference' => $this->providerReference, 'status' => $this->status,
            'amount_minor' => $this->amountMinor, 'currency' => $this->currency,
            'customer_email_sha256' => hash('sha256', strtolower(trim($this->customerEmail))),
            'occurred_at' => $this->occurredAt->format('U.u')];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->claims(), JSON_THROW_ON_ERROR));
    }
}
