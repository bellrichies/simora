<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\Clock;
use App\Contracts\PaymentProvider;
use App\Contracts\PaystackTransport;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\ProviderEvidence;
use App\Domain\Payments\VerifiedWebhook;
use DateTimeImmutable;

final class PaystackProvider implements PaymentProvider
{
    public function __construct(
        private readonly PaystackTransport $http,
        private readonly Clock $clock,
        private readonly string $account,
        private readonly array $webhookKeys,
        private readonly ?string $legacySecret = null,
        private readonly string $signatureMode = 'timestamped'
    ) {
        if (
            !in_array($signatureMode, ['timestamped', 'legacy'], true)
            || preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $account) !== 1
        ) {
            throw new PaymentException('provider_configuration_invalid', 503);
        }
        foreach ($webhookKeys as $id => $key) {
            if (
                !is_string($id) || !str_starts_with($id, 'whk_') || !is_string($key) || !str_starts_with(
                    $key,
                    'whsec_'
                )
            ) {
                throw new PaymentException('provider_configuration_invalid', 503);
            }
        }
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedWebhook
    {
        if (strlen($rawBody) > 262144) {
            throw new PaymentException('webhook_too_large', 413);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        $modern = $headers['paystack-signature'] ?? null;
        $valid = false;
        $kind = 'timestamped';
        if ($modern !== null) {
            if (!is_string($modern) || strlen($modern) > 4096) {
                throw new PaymentException('invalid_webhook_signature', 401);
            }
            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $modern) as $part) {
                [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($name === 't') {
                    if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $value)) {
                        throw new PaymentException('invalid_webhook_signature', 401);
                    }
                    $timestamp = $value;
                } elseif ($name === 'v1') {
                    $signatures[] = explode(':', $value, 2);
                }
            }
            if ($timestamp !== null && abs($this->clock->now()->getTimestamp() - (int) $timestamp) <= 300) {
                foreach ($signatures as $pair) {
                    [$id, $signature] = array_pad($pair, 2, '');
                    $key = $this->webhookKeys[$id] ?? null;
                    if (
                        $key !== null && preg_match('/^[a-f0-9]{128}$/D', $signature)
                        && hash_equals(hash_hmac('sha512', $timestamp . '.' . $rawBody, $key), $signature)
                    ) {
                        $valid = true;
                    }
                }
            }
        } elseif ($this->signatureMode === 'legacy') {
            $kind = 'legacy';
            $signature = $headers['x-paystack-signature'] ?? null;
            $valid = is_string($this->legacySecret) && str_starts_with($this->legacySecret, 'sk_test_')
                && is_string($signature) && preg_match('/^[a-f0-9]{128}$/D', $signature)
                && hash_equals(hash_hmac('sha512', $rawBody, $this->legacySecret), $signature);
        }
        // An invalid modern header never falls back to the legacy header.
        if (!$valid) {
            throw new PaymentException('invalid_webhook_signature', 401);
        }
        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new PaymentException('invalid_webhook_json', 422);
        }
        if (!is_array($event)) {
            throw new PaymentException('invalid_webhook_json', 422);
        }
        $type = $event['event'] ?? null;
        if (!is_string($type) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $type) !== 1) {
            throw new PaymentException('invalid_webhook_event', 422);
        }
        if ($type !== 'charge.success') {
            return new VerifiedWebhook(
                'paystack',
                $this->account,
                'sandbox',
                'ignored:' . hash('sha256', $rawBody),
                $type,
                null,
                null,
                $kind
            );
        }
        $data = $event['data'] ?? [];
        if (!is_array($data)) {
            throw new PaymentException('invalid_webhook_event', 422);
        }
        $id = self::transactionId($data['id'] ?? null);
        $reference = self::reference($data['reference'] ?? null);
        if (($data['domain'] ?? null) !== 'test') {
            throw new PaymentException('provider_environment_mismatch', 409);
        }
        return new VerifiedWebhook(
            'paystack',
            $this->account,
            'sandbox',
            'charge.success:' . $id,
            $type,
            $reference,
            $id,
            $kind
        );
    }

    public function lookup(string $merchantReference): ProviderEvidence
    {
        $reference = self::reference($merchantReference);
        $data = $this->http->request('GET', '/transaction/verify/' . $reference);
        if (
            ($data['domain'] ?? null) !== 'test' || ($data['reference'] ?? null) !== $reference
            || !is_int($data['amount'] ?? null) || !is_string($data['currency'] ?? null)
            || !is_string($data['customer']['email'] ?? null)
            || !filter_var($data['customer']['email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new PaymentException('provider_evidence_mismatch', 409);
        }
        $status = match ($data['status'] ?? null) {
            'success' => 'succeeded', 'failed', 'abandoned' => 'failed', 'reversed' => 'refunded',
            'pending', 'ongoing', 'processing', 'queued' => 'pending',
            default => throw new PaymentException('provider_response_invalid', 503),
        };
        $id = self::transactionId($data['id'] ?? null);
        $time = $status === 'succeeded' ? ($data['paid_at'] ?? null) : ($data['created_at'] ?? null);
        if (!is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $time)) {
            throw new PaymentException('provider_timestamp_invalid', 503);
        }
        try {
            $occurred = new DateTimeImmutable($time);
        } catch (\Exception) {
            throw new PaymentException('provider_timestamp_invalid', 503);
        }
        if ($occurred->format('Y-m-d\TH:i:s') !== substr($time, 0, 19)) {
            throw new PaymentException('provider_timestamp_invalid', 503);
        }
        return new ProviderEvidence(
            'paystack',
            $this->account,
            'sandbox',
            'verify:' . $id,
            $reference,
            $id,
            $status,
            $data['amount'],
            $data['currency'] ?? '',
            $occurred,
            $this->clock->now(),
            $data['customer']['email']
        );
    }

    /** Worker-only hosted checkout. No plans, splits, subaccounts, recurring authorizations or transfer parameters. */
    public function initialize(string $reference, string $email, int $amountMinor, string $callbackUrl): array
    {
        self::reference($reference);
        $url = parse_url($callbackUrl);
        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) || $amountMinor < 1 || $amountMinor > 1000000000
            || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || !isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
        ) {
            throw new PaymentException('invalid_checkout_terms', 422);
        }
        $data = $this->http->request('POST', '/transaction/initialize', ['reference' => $reference,
            'email' => $email, 'amount' => (string) $amountMinor, 'currency' => 'NGN', 'callback_url' => $callbackUrl]);
        $checkout = is_string($data['authorization_url'] ?? null) ? parse_url($data['authorization_url']) : false;
        if (
            ($data['reference'] ?? null) !== $reference || !is_array($checkout)
            || ($checkout['scheme'] ?? null) !== 'https' || ($checkout['host'] ?? null) !== 'checkout.paystack.com'
            || isset($checkout['user']) || isset($checkout['pass']) || isset($checkout['fragment'])
            || isset($checkout['port'])
        ) {
            throw new PaymentException('provider_checkout_url_invalid', 503);
        }
        return ['reference' => $reference, 'authorization_url' => $data['authorization_url']];
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-zA-Z0-9.=-]{1,128}$/D', $value) !== 1) {
            throw new PaymentException('provider_reference_invalid', 422);
        }
        return $value;
    }

    private static function transactionId(mixed $value): string
    {
        $id = is_int($value) ? (string) $value : $value;
        if (
            !is_string($id) || preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            throw new PaymentException('provider_transaction_id_invalid', 422);
        }
        return $id;
    }
}
