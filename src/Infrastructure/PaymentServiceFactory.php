<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Payments\PaymentApproval;
use App\Domain\Payments\PaymentException;
use App\Repositories\AccessRepository;
use App\Repositories\PaymentRepository;
use App\Services\PaymentLifecycleService;
use App\Services\SubscriptionCreditLedger;
use DateTimeImmutable;
use PDO;

final class PaymentServiceFactory
{
    public static function create(PDO $db, array $config): PaymentLifecycleService
    {
        $clock = new SystemClock();
        $access = new AccessRepository($db);
        $provider = new UnavailablePaymentProvider();
        $quotes = new UnavailableInvoiceQuoteAuthority();
        $reference = '';
        $expires = new DateTimeImmutable('1970-01-01T00:00:00Z');
        if ($config['enabled']) {
            if ($config['mode'] !== 'test' || $config['live_enabled'] || $config['payouts_enabled']) {
                throw new PaymentException('live_payments_not_approved', 503);
            }
            $keys = json_decode($config['webhook_keys_json'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($keys) || ($config['signature_mode'] === 'timestamped' && $keys === [])) {
                throw new PaymentException('provider_configuration_invalid', 503);
            }
            $provider = new PaystackProvider(
                new CurlPaystackTransport($config['secret_key'], 'test'),
                $clock,
                $config['account'],
                $keys,
                $config['secret_key'],
                $config['signature_mode']
            );
            $quotes = new SandboxInvoiceQuoteAuthority();
            $reference = 'user-selected:paystack-subscription-credit:2026-11-06:sandbox-only';
            $expires = new DateTimeImmutable($config['sandbox_approval_expires_at']);
        }
        $approval = new PaymentApproval(
            'paystack',
            $config['account'],
            'sandbox',
            $reference,
            'sandbox-only:no-real-tax',
            'sandbox-only:unissued-document',
            $expires,
            300
        );
        return new PaymentLifecycleService(
            new PaymentRepository($db, $access),
            $provider,
            $quotes,
            new SubscriptionCreditLedger($db, $access),
            $approval,
            $clock,
            $config['callback_url']
        );
    }
}
