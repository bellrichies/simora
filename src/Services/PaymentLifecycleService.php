<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clock;
use App\Contracts\InvoiceQuoteAuthority;
use App\Contracts\PaymentProvider;
use App\Domain\Access\PlanPolicy;
use App\Domain\Access\SessionCredentials;
use App\Domain\Payments\PaymentApproval;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\VerifiedWebhook;
use App\Repositories\PaymentRepository;
use DateTimeImmutable;
use DateTimeZone;

final class PaymentLifecycleService
{
    public function __construct(
        private readonly PaymentRepository $repository,
        private readonly PaymentProvider $provider,
        private readonly InvoiceQuoteAuthority $quotes,
        private readonly SubscriptionCreditLedger $credits,
        private readonly PaymentApproval $approval,
        private readonly Clock $clock,
        private readonly string $callbackUrl
    ) {
    }

    public function prepare(
        SessionCredentials $credentials,
        string $request,
        string $planCode,
        string $operation
    ): array {
        $now = $this->clock->now();
        $this->approval->assertReady($now);
        if (!in_array($operation, ['purchase', 'renewal'], true)) {
            throw new PaymentException('change_proration_approval_required', 503);
        }
        if (preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $request) !== 1 || !isset(PlanPolicy::all()[$planCode])) {
            throw new PaymentException('invalid_checkout_request', 422);
        }
        $fingerprint = hash('sha256', json_encode([$planCode, $operation, $this->approval->provider,
            $this->approval->account, $this->approval->environment], JSON_THROW_ON_ERROR));
        return $this->repository->transaction(function () use (
            $credentials,
            $request,
            $planCode,
            $operation,
            $now,
            $fingerprint
        ) {
            $owner = $this->repository->authorize($credentials, 'billing.checkout', true);
            $user = $this->repository->owner($owner);
            if ($user === null) {
                throw new PaymentException('account_unavailable', 403);
            }
            $prior = $this->repository->prior($owner, $request);
            if ($prior !== null) {
                if (!hash_equals($prior['fingerprint'], $fingerprint)) {
                    throw new PaymentException('checkout_idempotency_conflict');
                }
                return $this->present($prior);
            }
            $plan = $this->repository->plan($planCode);
            if ($plan === null) {
                throw new PaymentException('plan_unavailable', 403);
            }
            $plan['policy'] = PlanPolicy::verify(json_decode($plan['features'], true, 32, JSON_THROW_ON_ERROR));
            if ((int) $plan['amount_minor'] !== $plan['policy']['amount_minor'] || $plan['currency'] !== 'NGN') {
                throw new PaymentException('approved_price_mismatch');
            }
            if (
                $planCode === 'trial' && ($user['email_verified_at'] === null
                || $this->repository->paidTrial($owner))
            ) {
                throw new PaymentException('trial_ineligible', 403);
            }
            $terms = $this->repository->terms($owner);
            if (
                ($operation === 'purchase' && $terms !== [])
                || ($operation === 'renewal' && ($planCode === 'trial' || ($terms !== []
                    && $terms[0]['code'] !== $planCode)))
            ) {
                throw new PaymentException('subscription_change_requires_review');
            }
            if ($this->repository->pending($owner)) {
                throw new PaymentException('existing_pending_checkout');
            }
            $quote = $this->quotes->issue($owner, $plan, $operation, $now);
            if (
                $quote->subtotalMinor !== (int) $plan['amount_minor'] || $plan['currency'] !== $quote->currency
                || $quote->taxReference !== $this->approval->taxReference
                || $quote->invoiceReference !== $this->approval->invoiceReference
                || $quote->expiresAt <= $now || $quote->expiresAt > $now->modify('+1 day')
            ) {
                throw new PaymentException('approved_quote_mismatch');
            }
            return $this->present($this->repository->create(
                $owner,
                $user['normalized_email'],
                $plan,
                $operation,
                $request,
                $fingerprint,
                $quote,
                $this->approval
            ));
        });
    }

    /** Signature verification and durable capture only; no provider network calls or activation on the HTTP request. */
    public function receive(string $body, array $headers): array
    {
        $this->approval->assertReady($this->clock->now());
        $notice = $this->provider->verifyWebhook($body, $headers);
        if (!$this->approval->matches($notice->provider, $notice->account, $notice->environment)) {
            throw new PaymentException('provider_scope_mismatch');
        }
        return $this->repository->serialized($notice->provider . ':' . $notice->account . ':'
            . $notice->environment . ':' . $notice->eventId, function () use ($notice, $body) {
                return $this->repository->transaction(function () use ($notice, $body) {
                    $existing = $this->repository->existingWebhook($notice);
                    if ($existing !== null) {
                        if (!hash_equals($existing['evidence_sha256'], $notice->digest())) {
                            $this->repository->audit(
                                null,
                                'payment.webhook.duplicate_conflict',
                                (string) $existing['id'],
                                'webhook:' . $existing['id']
                            );
                            return ['receipt_id' => (string) $existing['id'], 'status' => 'conflict_review'];
                        }
                        return ['receipt_id' => (string) $existing['id'], 'status' => $existing['status']];
                    }
                    $row = $this->repository->capture($notice, hash('sha256', $body));
                    return ['receipt_id' => (string) $row['id'], 'status' => $row['status']];
                });
            });
    }

    /** At-least-once worker handler. Independent current provider verification happens outside the DB transaction. */
    public function process(int $receiptId): array
    {
        $this->approval->assertReady($this->clock->now());
        $receipt = $this->repository->receipt($receiptId);
        if ($receipt === null) {
            throw new PaymentException('webhook_not_found', 404);
        }
        if ($receipt['status'] !== 'received') {
            return ['status' => $receipt['status'], 'result' => $receipt['result_code']];
        }
        if (!$this->approval->matches($receipt['provider'], $receipt['account_code'], $receipt['environment'])) {
            throw new PaymentException('provider_scope_mismatch');
        }
        $payment = $this->repository->findReference($receipt['merchant_reference']);
        if ($payment === null) {
            return $this->repository->transaction(fn () => $this->repository->finishReceipt(
                $receiptId,
                'quarantined',
                'unknown_reference',
                null,
                null
            ));
        }
        $fact = $this->provider->lookup($receipt['merchant_reference']);
        return $this->repository->transaction(function () use ($receiptId, $payment, $fact) {
            $owner = $this->repository->owner((int) $payment['user_id']);
            $p = $this->repository->payment((int) $payment['id']);
            $invoice = $this->repository->invoice((int) $p['invoice_id']);
            $event = $this->repository->receipt($receiptId, true);
            if ($event['status'] !== 'received') {
                return $event;
            }
            $now = $this->clock->now();
            $this->approval->assertReady($now);
            $utc = new DateTimeZone('UTC');
            $mismatch = !$this->approval->matches($fact->provider, $fact->account, $fact->environment)
                || $p['provider'] !== $fact->provider || $p['account_code'] !== $fact->account
                || $p['environment'] !== $fact->environment || $fact->merchantReference !== $p['merchant_reference']
                || $fact->providerReference !== $event['transaction_id']
                || $fact->amountMinor !== (int) $p['amount_minor']
                || $fact->amountMinor !== (int) $invoice['total_minor'] || $fact->currency !== $p['currency']
                || !hash_equals($invoice['customer_email_sha256'], hash(
                    'sha256',
                    strtolower(trim($fact->customerEmail)),
                    true
                ))
                || ($p['provider_reference'] !== null && $p['provider_reference'] !== $fact->providerReference)
                || $fact->verifiedAt > $now || $fact->verifiedAt < $now->modify('-'
                    . $this->approval->maximumEvidenceAgeSeconds . ' seconds')
                || $fact->occurredAt > $now || $fact->occurredAt < new DateTimeImmutable($invoice['created_at'], $utc)
                || $fact->occurredAt > new DateTimeImmutable($invoice['expires_at'], $utc);
            if ($owner === null || $mismatch || $p['status'] === 'review') {
                // Never replace a legitimate successful transaction with mismatching or stale evidence.
                if ($p['status'] !== 'succeeded') {
                    $this->repository->paymentStatus((int) $p['id'], 'review');
                }
                return $this->repository->finishReceipt(
                    $receiptId,
                    'quarantined',
                    'evidence_mismatch',
                    (int) $p['id'],
                    $fact->digest()
                );
            }
            if (in_array($fact->status, ['refunded', 'disputed'], true)) {
                $this->repository->recordReversalObservation(
                    (int) $p['id'],
                    $receiptId,
                    $fact->providerReference,
                    $fact->amountMinor
                );
                $this->repository->paymentStatus((int) $p['id'], 'review', $fact->providerReference);
                return $this->repository->finishReceipt(
                    $receiptId,
                    'quarantined',
                    'reversal_review',
                    (int) $p['id'],
                    $fact->digest()
                );
            }
            if ($p['status'] === 'succeeded') {
                return $this->repository->finishReceipt(
                    $receiptId,
                    'processed',
                    'already_fulfilled',
                    (int) $p['id'],
                    $fact->digest()
                );
            }
            if ($fact->status !== 'succeeded') {
                // A pending/failed lookup cannot fulfill a success notice. Keep it retryable for reconciliation.
                return ['status' => 'received', 'result' => 'verification_pending'];
            }
            if ($invoice['paid_payment_id'] !== null || $invoice['status'] !== 'unpaid') {
                $this->repository->paymentStatus((int) $p['id'], 'review', $fact->providerReference);
                return $this->repository->finishReceipt(
                    $receiptId,
                    'quarantined',
                    'duplicate_charge_review',
                    (int) $p['id'],
                    $fact->digest()
                );
            }
            $terms = $this->repository->terms((int) $p['user_id']);
            $plan = json_decode(
                $this->repositoryPlanFeatures((int) $invoice['plan_version_id']),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            $policy = PlanPolicy::verify($plan);
            $trialInvalid = $policy['code'] === 'trial' && ($owner['email_verified_at'] === null
                || $this->repository->paidTrial((int) $p['user_id']));
            if (
                $trialInvalid || ($invoice['operation'] === 'purchase' && $terms !== [])
                || ($invoice['operation'] === 'renewal' && ($policy['code'] === 'trial'
                    || ($terms !== [] && $terms[0]['code'] !== $policy['code'])))
            ) {
                $this->repository->paymentStatus((int) $p['id'], 'review', $fact->providerReference);
                return $this->repository->finishReceipt(
                    $receiptId,
                    'quarantined',
                    'subscription_conflict',
                    (int) $p['id'],
                    $fact->digest()
                );
            }
            $starts = $invoice['operation'] === 'renewal' && $terms !== []
                ? new DateTimeImmutable($terms[0]['ends_at'], $utc)
                : new DateTimeImmutable($this->repository->now(), $utc);
            $ends = $starts->modify('+' . $policy['validity_seconds'] . ' seconds');
            $this->repository->activate(
                $p,
                $invoice,
                $starts->format('Y-m-d H:i:s.u'),
                $ends->format('Y-m-d H:i:s.u'),
                $fact->providerReference
            );
            $this->credits->settle((int) $p['id']);
            return $this->repository->finishReceipt(
                $receiptId,
                'processed',
                'subscription_confirmed',
                (int) $p['id'],
                $fact->digest()
            );
        });
    }

    private function repositoryPlanFeatures(int $version): string
    {
        return $this->repository->versionFeatures($version);
    }

    /** Trusted worker reconciliation for missed webhooks, initialize timeouts and later reversals. */
    public function reconcile(int $paymentId): array
    {
        $this->approval->assertReady($this->clock->now());
        $p = $this->repository->findPayment($paymentId);
        if ($p === null || !$this->approval->matches($p['provider'], $p['account_code'], $p['environment'])) {
            throw new PaymentException('payment_not_found', 404);
        }
        $fact = $this->provider->lookup($p['merchant_reference']);
        if (!in_array($fact->status, ['succeeded', 'refunded', 'disputed'], true)) {
            return ['status' => 'verification_pending'];
        }
        $notice = new VerifiedWebhook(
            $fact->provider,
            $fact->account,
            $fact->environment,
            'reconcile:' . $fact->providerReference . ':' . $fact->status,
            'transaction.reconciled',
            $p['merchant_reference'],
            $fact->providerReference,
            'server_lookup'
        );
        $receipt = $this->repository->serialized(
            $notice->eventId,
            fn () => $this->repository->transaction(function () use ($notice) {
                return $this->repository->existingWebhook($notice) ?? $this->repository->capture(
                    $notice,
                    $notice->digest()
                );
            })
        );
        return $this->process((int) $receipt['id']);
    }

    /** External initialize is claimed once; timeouts require reconciliation, never another charge/reference. */
    public function initialize(int $paymentId): array
    {
        $this->approval->assertReady($this->clock->now());
        $initial = $this->repository->findPayment($paymentId);
        if ($initial === null) {
            throw new PaymentException('payment_not_found', 404);
        }
        $context = $this->repository->transaction(function () use ($paymentId, $initial) {
            $user = $this->repository->owner((int) $initial['user_id']);
            $p = $this->repository->payment($paymentId);
            $invoice = $this->repository->invoice((int) $p['invoice_id']);
            if (
                $user === null || !$this->approval->matches($p['provider'], $p['account_code'], $p['environment'])
                || new DateTimeImmutable($invoice['expires_at'], new DateTimeZone('UTC')) <= $this->clock->now()
                || !hash_equals($invoice['customer_email_sha256'], hash('sha256', $user['normalized_email'], true))
            ) {
                throw new PaymentException('checkout_no_longer_eligible');
            }
            return ['payment' => $p, 'email' => $user['normalized_email'],
                'claimed' => $this->repository->claimCheckout($paymentId)];
        });
        if (!$context['claimed']) {
            return ['status' => 'reconciliation_required'];
        }
        $p = $context['payment'];
        $result = $this->provider->initialize(
            $p['merchant_reference'],
            $context['email'],
            (int) $p['amount_minor'],
            $this->callbackUrl
        );
        return $this->repository->transaction(function () use ($paymentId, $result) {
            $this->repository->initialized($paymentId, $result['authorization_url']);
            return ['status' => 'initialized'];
        });
    }

    public function invoices(SessionCredentials $credentials): array
    {
        return $this->repository->transaction(function () use ($credentials) {
            $owner = $this->repository->authorize($credentials, 'billing.read', false);
            $rows = $this->repository->ownedInvoices($owner);
            foreach ($rows as &$row) {
                foreach (['subtotal_minor', 'tax_minor', 'total_minor'] as $key) {
                    $row[$key] = (string) $row[$key];
                }
                $row['created_at'] = (new DateTimeImmutable(
                    $row['created_at'],
                    new DateTimeZone('UTC')
                ))->format('Y-m-d\TH:i:s.u\Z');
                $row['legal_document_issued'] = false;
            }
            return $rows;
        });
    }

    public function wallet(SessionCredentials $credentials, bool $transactions = false): array
    {
        return $this->repository->transaction(function () use ($credentials, $transactions) {
            $owner = $this->repository->authorize($credentials, 'billing.read', false);
            return $transactions ? $this->credits->ownedTransactions($owner) : $this->credits->ownedSummary($owner);
        });
    }

    private function present(array $p): array
    {
        return ['payment_id' => $p['public_id'], 'reference' => $p['merchant_reference'], 'status' => $p['status'],
            'authorization_url' => $p['authorization_url'], 'environment' => $p['environment']];
    }
}
