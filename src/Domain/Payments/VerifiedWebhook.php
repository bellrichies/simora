<?php

declare(strict_types=1);

namespace App\Domain\Payments;

final class VerifiedWebhook
{
    public function __construct(
        public readonly string $provider,
        public readonly string $account,
        public readonly string $environment,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $merchantReference,
        public readonly ?string $transactionId,
        public readonly string $verificationKind
    ) {
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([$this->provider, $this->account, $this->environment,
            $this->eventId, $this->eventType, $this->merchantReference, $this->transactionId], JSON_THROW_ON_ERROR));
    }
}
