<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\PaystackTransport;
use App\Domain\Payments\PaymentException;

final class CurlPaystackTransport implements PaystackTransport
{
    public function __construct(private readonly string $secret, private readonly string $environment = 'test')
    {
        if ($environment !== 'test' || preg_match('/^sk_test_[A-Za-z0-9]+$/D', $secret) !== 1) {
            throw new PaymentException('paystack_test_credentials_required', 503);
        }
        if (!extension_loaded('curl')) {
            throw new PaymentException('paystack_transport_unavailable', 503);
        }
    }

    public function request(string $method, string $path, array $body = []): array
    {
        $initialize = $method === 'POST' && $path === '/transaction/initialize';
        $verify = $method === 'GET' && preg_match('#^/transaction/verify/[a-zA-Z0-9.=-]{1,128}$#D', $path);
        if (!$initialize && !$verify) {
            throw new PaymentException('provider_operation_disabled', 403);
        }
        if ($verify && $body !== []) {
            throw new PaymentException('invalid_provider_request', 422);
        }
        if (
            $initialize && array_diff(array_keys($body), ['amount', 'email', 'currency', 'reference',
            'callback_url'])
        ) {
            throw new PaymentException('provider_operation_disabled', 403);
        }
        $handle = curl_init('https://api.paystack.co' . $path);
        $bytes = '';
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->secret, 'Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > 131072) {
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            }]);
        if ($initialize) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false || $status !== 200) {
            throw new PaymentException('provider_unavailable', 503);
        }
        try {
            $result = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new PaymentException('provider_response_invalid', 503);
        }
        if (!is_array($result) || ($result['status'] ?? null) !== true || !is_array($result['data'] ?? null)) {
            throw new PaymentException('provider_response_invalid', 503);
        }
        return $result['data'];
    }
}
