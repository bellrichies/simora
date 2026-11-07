<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Access\SessionCredentials;
use App\Domain\Payments\ApprovedInvoiceQuote;
use App\Domain\Payments\PaymentApproval;
use App\Domain\Payments\PaymentException;
use App\Domain\Payments\VerifiedWebhook;
use App\Services\SessionAuthorization;
use PDO;
use Throwable;

final class PaymentRepository
{
    public function __construct(private readonly PDO $db, private readonly AccessRepository $access)
    {
    }

    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $value = $work();
            $this->db->commit();
            return $value;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function serialized(string $key, callable $work): mixed
    {
        $lock = 'simora_pay_' . substr(hash('sha256', $key), 0, 40);
        $query = $this->db->prepare('SELECT GET_LOCK(?,5)');
        $query->execute([$lock]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new PaymentException('payment_busy', 503);
        }
        try {
            return $work();
        } finally {
            $this->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function authorize(SessionCredentials $credentials, string $permission, bool $write): int
    {
        return SessionAuthorization::owner($this->access, $credentials, $permission, $write);
    }

    public function owner(int $id): ?array
    {
        return $this->one("SELECT id,normalized_email,email_verified_at FROM users WHERE "
            . "id=? AND status='active' FOR UPDATE", [$id]);
    }

    public function now(): string
    {
        return (string) $this->db->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
    }

    public function plan(string $code): ?array
    {
        return $this->one('SELECT v.id AS plan_version_id,v.features,p.code,r.amount_minor,r.currency '
            . 'FROM plans p JOIN plan_versions v ON v.plan_id=p.id JOIN plan_prices r ON r.plan_version_id=v.id '
            . 'WHERE p.code=? AND p.enabled=1 AND v.approved_at<=UTC_TIMESTAMP(6) AND v.effective_at<=UTC_TIMESTAMP(6) '
            . 'ORDER BY v.version DESC LIMIT 1 FOR UPDATE', [$code]);
    }

    public function prior(int $owner, string $key): ?array
    {
        return $this->one('SELECT * FROM payments WHERE user_id=? AND request_key=? FOR UPDATE', [$owner, $key]);
    }

    public function pending(int $owner): bool
    {
        return $this->one(
            'SELECT p.id FROM payments p JOIN invoices i ON i.id=p.invoice_id '
            . "WHERE p.user_id=? AND p.status='pending' AND i.status='unpaid' "
                . "AND i.expires_at>UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE",
            [$owner]
        ) !== null;
    }

    public function create(
        int $owner,
        string $email,
        array $plan,
        string $operation,
        string $key,
        string $fingerprint,
        ApprovedInvoiceQuote $quote,
        PaymentApproval $approval
    ): array {
        $invoiceId = 'inv_' . bin2hex(random_bytes(16));
        $paymentId = 'pay_' . bin2hex(random_bytes(16));
        $merchant = 'sim-' . bin2hex(random_bytes(16));
        $this->run(
            'INSERT INTO invoices (public_id,user_id,plan_version_id,operation,subtotal_minor,tax_minor,total_minor,'
            . 'currency,validity_seconds,tax_reference,invoice_reference,approval_reference,customer_email_sha256,'
            . 'created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?)',
            [$invoiceId, $owner, $plan['plan_version_id'], $operation, $quote->subtotalMinor, $quote->taxMinor,
                $quote->totalMinor, $quote->currency, $plan['policy']['validity_seconds'], $quote->taxReference,
                $quote->invoiceReference, $approval->approvalReference, hash('sha256', $email, true),
            $quote->expiresAt->format('Y-m-d H:i:s.u')]
        );
        $invoice = (int) $this->db->lastInsertId();
        $this->run(
            'INSERT INTO payments (public_id,user_id,invoice_id,provider,account_code,environment,'
            . 'merchant_reference,request_key,fingerprint,amount_minor,currency,created_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$paymentId, $owner, $invoice, $approval->provider, $approval->account, $approval->environment,
            $merchant,
            $key,
            $fingerprint,
            $quote->totalMinor,
            $quote->currency]
        );
        $id = (int) $this->db->lastInsertId();
        $this->outbox('payment:checkout:' . $id, 'payment.checkout.requested', $id, null);
        $this->audit($owner, 'payment.intent.created', (string) $id, $key);
        return $this->payment($id);
    }

    public function payment(int $id): ?array
    {
        return $this->one('SELECT * FROM payments WHERE id=? FOR UPDATE', [$id]);
    }

    public function findReference(string $reference): ?array
    {
        return $this->one('SELECT * FROM payments WHERE merchant_reference=?', [$reference]);
    }

    public function findPayment(int $id): ?array
    {
        return $this->one('SELECT * FROM payments WHERE id=?', [$id]);
    }

    public function invoice(int $id): array
    {
        return $this->one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]);
    }

    public function versionFeatures(int $id): string
    {
        $row = $this->one('SELECT features FROM plan_versions WHERE id=?', [$id]);
        if ($row === null) {
            throw new PaymentException('invoice_plan_unavailable');
        }
        return $row['features'];
    }

    public function receipt(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM payment_webhooks WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public function existingWebhook(VerifiedWebhook $notice): ?array
    {
        return $this->one('SELECT * FROM payment_webhooks WHERE provider=? AND account_code=? AND environment=? '
            . 'AND external_event_id=? FOR UPDATE', [$notice->provider, $notice->account,
                $notice->environment, $notice->eventId]);
    }

    public function capture(VerifiedWebhook $notice, string $bodyHash): array
    {
        $ignored = $notice->merchantReference === null;
        $this->run('INSERT INTO payment_webhooks (provider,account_code,environment,external_event_id,event_type,'
            . 'merchant_reference,transaction_id,payload_sha256,evidence_sha256,verification_kind,status,received_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$notice->provider, $notice->account,
                $notice->environment,
                $notice->eventId, $notice->eventType, $notice->merchantReference, $notice->transactionId, $bodyHash,
                $notice->digest(), $notice->verificationKind, $ignored ? 'ignored' : 'received']);
        $id = (int) $this->db->lastInsertId();
        if (!$ignored) {
            $this->outbox('payment:webhook:' . $id, 'payment.webhook.received', null, $id);
        }
        return $this->receipt($id);
    }

    public function finishReceipt(int $id, string $status, string $reason, ?int $payment, ?string $lookup): array
    {
        $this->run('UPDATE payment_webhooks SET status=?,result_code=?,payment_id=?,lookup_sha256=?,'
            . 'processed_at=UTC_TIMESTAMP(6) WHERE id=?', [$status, $reason, $payment, $lookup, $id]);
        $this->audit(null, 'payment.webhook.' . $reason, (string) ($payment ?? $id), 'webhook:' . $id);
        return $this->receipt($id);
    }

    public function paymentStatus(int $id, string $status, ?string $providerReference = null): void
    {
        $this->run(
            'UPDATE payments SET status=?,provider_reference=COALESCE(provider_reference,?) WHERE id=?',
            [$status, $providerReference, $id]
        );
    }

    public function terms(int $owner): array
    {
        return $this->all('SELECT s.*,p.code FROM subscriptions s JOIN plan_versions v ON v.id=s.plan_version_id '
            . 'JOIN plans p ON p.id=v.plan_id WHERE s.user_id=? AND s.status=\'active\' '
            . 'AND s.ends_at>UTC_TIMESTAMP(6) ORDER BY s.ends_at DESC FOR UPDATE', [$owner]);
    }

    public function paidTrial(int $owner): bool
    {
        return $this->one('SELECT s.id FROM subscriptions s JOIN plan_versions v ON v.id=s.plan_version_id '
            . 'JOIN plans p ON p.id=v.plan_id WHERE s.user_id=? AND p.code=\'trial\' '
            . 'AND EXISTS (SELECT 1 FROM subscription_events e WHERE e.subscription_id=s.id '
            . 'AND e.event_type=\'payment.confirmed\') LIMIT 1', [$owner]) !== null;
    }

    public function activate(
        array $payment,
        array $invoice,
        string $starts,
        string $ends,
        string $providerReference
    ): int {
        $this->run('INSERT INTO subscriptions (user_id,plan_version_id,status,starts_at,ends_at) '
            . "VALUES (?,?,'active',?,?)", [$payment['user_id'], $invoice['plan_version_id'], $starts, $ends]);
        $subscription = (int) $this->db->lastInsertId();
        $this->paymentStatus((int) $payment['id'], 'succeeded', $providerReference);
        $this->run(
            "UPDATE invoices SET status='paid',paid_payment_id=?,subscription_id=? WHERE id=? AND status='unpaid'",
            [$payment['id'], $subscription, $invoice['id']]
        );
        $this->run(
            'INSERT INTO subscription_events (subscription_id,actor_user_id,payment_id,event_type,'
            . 'evidence_reference,occurred_at) VALUES (?,NULL,?,\'payment.confirmed\',?,UTC_TIMESTAMP(6))',
            [$subscription, $payment['id'], 'payment:' . $payment['public_id']]
        );
        $this->outbox(
            'payment:activated:' . $payment['id'],
            'subscription.payment.confirmed',
            (int) $payment['id'],
            null
        );
        $this->access->publishNotification(
            (int) $payment['user_id'],
            null,
            'payment:confirmed:' . $payment['id'],
            'subscription.payment.confirmed',
            json_encode(
                ['payment_id' => $payment['public_id'],
                'starts_at' => $starts,
                'ends_at' => $ends,
                'environment' => $payment['environment']],
                JSON_THROW_ON_ERROR
            )
        );
        $this->audit(null, 'payment.subscription.confirmed', (string) $payment['id'], 'payment:' . $payment['id']);
        return $subscription;
    }

    public function claimCheckout(int $id): bool
    {
        $claimed = $this->run('UPDATE payments SET checkout_claimed_at=UTC_TIMESTAMP(6) '
            . "WHERE id=? AND checkout_claimed_at IS NULL AND status='pending'", [$id]) === 1;
        if ($claimed) {
            $this->audit(null, 'payment.checkout.claimed', (string) $id, 'payment:' . $id);
        }
        return $claimed;
    }

    public function initialized(int $id, string $url): void
    {
        $this->run('UPDATE payments SET initialized_at=UTC_TIMESTAMP(6),authorization_url=? WHERE id=?', [$url, $id]);
        $this->audit(null, 'payment.checkout.initialized', (string) $id, 'payment:' . $id);
    }

    public function outbox(string $key, string $type, ?int $payment, ?int $webhook): void
    {
        $this->run('INSERT INTO transactional_outbox '
            . '(event_key,batch_id,payment_id,payment_webhook_id,event_type,payload,created_at) '
            . 'VALUES (?,NULL,?,?,?,?,UTC_TIMESTAMP(6))', [$key, $payment, $webhook, $type,
                json_encode(['payment_id' => $payment, 'webhook_id' => $webhook], JSON_THROW_ON_ERROR)]);
    }

    public function audit(?int $actor, string $operation, string $id, string $correlation): void
    {
        $this->run('INSERT INTO audit_logs (actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) '
            . 'VALUES (?,?,\'payment\',?,?,UTC_TIMESTAMP(6))', [$actor, $operation, $id, $correlation]);
    }

    public function ownedInvoices(int $owner): array
    {
        return $this->all('SELECT public_id,invoice_number,subtotal_minor,tax_minor,total_minor,'
            . 'currency,status,created_at '
            . 'FROM invoices WHERE user_id=? ORDER BY id DESC LIMIT 100', [$owner]);
    }

    public function recordReversalObservation(int $payment, int $receipt, string $providerReference, int $amount): void
    {
        $this->run('INSERT INTO refunds '
            . '(payment_id,webhook_id,provider_reference,amount_minor,currency,status,created_at) '
            . "VALUES (?,?,?,?,'NGN','observed_review',UTC_TIMESTAMP(6))", [$payment, $receipt,
                $providerReference, $amount]);
    }

    private function run(string $sql, array $values): int
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query->rowCount();
    }

    private function one(string $sql, array $values): ?array
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function all(string $sql, array $values): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
