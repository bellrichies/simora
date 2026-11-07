<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use DateTimeImmutable;
use InvalidArgumentException;

/** Trusted release configuration only. No production instance is bound until separate financial/provider signoff. */
final class PaymentApproval
{
    public function __construct(
        public readonly string $provider,
        public readonly string $account,
        public readonly string $environment,
        public readonly string $approvalReference,
        public readonly string $taxReference,
        public readonly string $invoiceReference,
        private readonly DateTimeImmutable $expiresAt,
        public readonly int $maximumEvidenceAgeSeconds
    ) {
        foreach ([$provider, $account] as $key) {
            if (preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid approved provider/account identity.');
            }
        }
        if (
            !in_array($environment, ['sandbox', 'live'], true)
            || $maximumEvidenceAgeSeconds < 1 || $maximumEvidenceAgeSeconds > 3600
        ) {
            throw new InvalidArgumentException('Invalid separately approved evidence policy.');
        }
    }

    public function assertReady(DateTimeImmutable $now): void
    {
        foreach ([$this->approvalReference, $this->taxReference, $this->invoiceReference] as $reference) {
            if (trim($reference) === '' || strlen($reference) > 255) {
                throw new PaymentException('financial_approval_required', 503);
            }
        }
        if ($now >= $this->expiresAt) {
            throw new PaymentException('financial_approval_expired', 503);
        }
    }

    public function matches(string $provider, string $account, string $environment): bool
    {
        return $provider === $this->provider && $account === $this->account && $environment === $this->environment;
    }
}
