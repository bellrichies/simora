<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Payments\ProviderEvidence;
use App\Domain\Payments\VerifiedWebhook;

interface PaymentProvider
{
    /** Verify raw bytes, signature, merchant/environment and replay rules per the separately approved provider contract. */
    public function verifyWebhook(string $rawBody, array $headers): VerifiedWebhook;

    /** Authenticated read-only authoritative lookup. Never trust the webhook status or browser callback alone. */
    public function lookup(string $merchantReference): ProviderEvidence;

    /** Worker only. Single server-generated reference and approved hosted checkout terms. No off-session charges. */
    public function initialize(string $reference, string $email, int $amountMinor, string $callbackUrl): array;
}
