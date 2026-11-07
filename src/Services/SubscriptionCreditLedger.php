<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;
use App\Domain\Payments\PaymentException;
use App\Repositories\AccessRepository;
use PDO;

/** Subscription-credit subledger only. No cash withdrawals, bank transfers, peer transfers or arbitrary funding. */
final class SubscriptionCreditLedger
{
    public function __construct(private readonly PDO $db, private readonly AccessRepository $access)
    {
    }

    /** Called in the verified payment transaction, with the same PDO connection. */
    public function settle(int $paymentId): array
    {
        $this->assertTransaction();
        $query = $this->db->prepare('SELECT p.id AS payment_id,p.user_id,p.invoice_id,p.amount_minor,p.currency '
            . 'FROM payments p JOIN invoices i ON i.id=p.invoice_id AND i.user_id=p.user_id '
            . "WHERE p.id=? AND p.status='succeeded' AND i.status='paid' AND i.paid_payment_id=p.id "
            . 'AND p.amount_minor=i.total_minor AND p.currency=i.currency FOR UPDATE');
        $query->execute([$paymentId]);
        $payment = $query->fetch(PDO::FETCH_ASSOC);
        if ($payment === false || $payment['currency'] !== 'NGN') {
            throw new PaymentException('ledger_payment_not_verified');
        }
        $fund = $this->post($payment, 'verified_subscription_funding', 'credit:fund:' . $paymentId, true);
        $apply = $this->post($payment, 'subscription_application', 'credit:apply:' . $payment['invoice_id'], false);
        return ['funding_journal_id' => $fund, 'application_journal_id' => $apply];
    }

    /** Full internal correction only, separately authorized and audited. Never calls a refund/transfer API. */
    public function reverse(SessionCredentials $credentials, int $journalId, string $request, string $approval): int
    {
        $this->assertTransaction();
        $actor = SessionAuthorization::owner($this->access, $credentials, 'billing.credit.adjust', true);
        if (
            $journalId < 1 || preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $request) !== 1
            || trim($approval) === '' || strlen($approval) > 255
        ) {
            throw new PaymentException('credit_adjustment_approval_required', 403);
        }
        $query = $this->db->prepare('SELECT j.*,p.amount_minor FROM wallet_journals j '
            . 'JOIN payments p ON p.id=j.payment_id WHERE j.id=? FOR UPDATE');
        $query->execute([$journalId]);
        $original = $query->fetch(PDO::FETCH_ASSOC);
        if ($original === false || $original['purpose'] === 'approved_reversal') {
            throw new PaymentException('credit_reversal_not_permitted', 403);
        }
        $id = $this->post(
            $original,
            'approved_reversal',
            'credit:reverse:' . $journalId,
            $original['purpose'] === 'subscription_application',
            $journalId
        );
        $query = $this->db->prepare('INSERT INTO wallet_adjustments '
            . '(journal_id,actor_user_id,request_reference,approval_reference,created_at) '
            . 'VALUES (?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id');
        $query->execute([$id, $actor, $request, $approval]);
        return $id;
    }

    public function ownedSummary(int $owner): array
    {
        $this->assertTransaction();
        $query = $this->db->prepare("SELECT * FROM wallet_accounts WHERE user_id=? AND "
            . "account_type='subscription_credit' FOR UPDATE");
        $query->execute([$owner]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        return ['scope' => 'subscription_only', 'payouts_enabled' => false,
            'account_id' => $account === false ? null : (string) $account['id'],
            'currency' => 'NGN',
            'balance_minor' => $account === false ? null : (string) $this->balance((int) $account['id'])];
    }

    public function ownedTransactions(int $owner): array
    {
        $this->assertTransaction();
        $query = $this->db->prepare('SELECT j.id,j.external_event_key,j.purpose,j.currency,j.payment_id,'
            . 'j.invoice_id,j.reversal_of_id,j.posted_at,CAST(p.debit_minor AS CHAR) AS debit_minor,'
            . 'CAST(p.credit_minor AS CHAR) AS credit_minor FROM wallet_journals j '
            . 'JOIN wallet_postings p ON p.journal_id=j.id JOIN wallet_accounts a ON a.id=p.account_id '
            . 'WHERE j.user_id=? AND a.user_id=? ORDER BY j.id DESC LIMIT 100');
        $query->execute([$owner, $owner]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['posted_at'] = (new \DateTimeImmutable($row['posted_at'], new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s.u\Z');
        }
        return $rows;
    }

    private function post(
        array $payment,
        string $purpose,
        string $key,
        bool $creditCustomer,
        ?int $reversal = null
    ): int {
        $owner = (int) $payment['user_id'];
        $amount = (int) $payment['amount_minor'];
        if ($amount < 1 || $amount > 1000000000 || $payment['currency'] !== 'NGN') {
            throw new PaymentException('credit_amount_invalid');
        }
        $hash = hash('sha256', json_encode([$owner, (int) $payment['payment_id'], (int) $payment['invoice_id'],
            $purpose, $amount, $creditCustomer, $reversal], JSON_THROW_ON_ERROR));
        $query = $this->db->prepare('SELECT id,fingerprint FROM wallet_journals WHERE external_event_key=? FOR UPDATE');
        $query->execute([$key]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            if (!hash_equals($existing['fingerprint'], $hash)) {
                throw new PaymentException('credit_idempotency_conflict');
            }
            return (int) $existing['id'];
        }
        $create = $this->db->prepare('INSERT INTO wallet_accounts (user_id,owner_key,currency,account_type) '
            . "VALUES (?,?,'NGN',?) ON DUPLICATE KEY UPDATE id=id");
        $create->execute([$owner, 'user:' . $owner, 'subscription_credit']);
        $create->execute([null, 'system:subscription', 'subscription_control']);
        $query = $this->db->prepare('SELECT * FROM wallet_accounts WHERE currency=\'NGN\' AND '
            . '((user_id=? AND account_type=\'subscription_credit\') OR '
                . 'owner_key=\'system:subscription\') ORDER BY id FOR UPDATE');
        $query->execute([$owner]);
        $accounts = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($accounts) !== 2) {
            throw new PaymentException('credit_account_scope_invalid');
        }
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account['id']] = $this->balance((int) $account['id']);
            if (
                $account['account_type'] === 'subscription_credit' && !$creditCustomer
                && $balances[$account['id']] < $amount
            ) {
                throw new PaymentException('insufficient_subscription_credit');
            }
        }
        $query = $this->db->prepare('INSERT INTO wallet_journals (user_id,external_event_key,fingerprint,purpose,'
            . 'currency,payment_id,invoice_id,reversal_of_id,posted_at) VALUES '
                . '(?,?,?,?,\'NGN\',?,?,?,UTC_TIMESTAMP(6))');
        $query->execute([$owner, $key, $hash, $purpose, $payment['payment_id'], $payment['invoice_id'], $reversal]);
        $id = (int) $this->db->lastInsertId();
        $debits = $credits = 0;
        foreach ($accounts as $account) {
            $credit = ($account['account_type'] === 'subscription_credit') === $creditCustomer;
            $debitAmount = $credit ? 0 : $amount;
            $creditAmount = $credit ? $amount : 0;
            $debits += $debitAmount;
            $credits += $creditAmount;
            $old = $balances[$account['id']];
            $delta = $creditAmount - $debitAmount;
            if (($delta > 0 && $old > PHP_INT_MAX - $delta) || ($delta < 0 && $old < PHP_INT_MIN - $delta)) {
                throw new PaymentException('credit_precision_exceeded');
            }
            $this->db->prepare('INSERT INTO wallet_postings '
                . '(journal_id,account_id,debit_minor,credit_minor) VALUES (?,?,?,?)')
                ->execute([$id, $account['id'], $debitAmount, $creditAmount]);
            $this->db->prepare('UPDATE wallet_balances SET current_minor=?,version=version+1 WHERE account_id=?')
                ->execute([$old + $delta, $account['id']]);
        }
        if ($debits !== $credits) {
            throw new PaymentException('unbalanced_subscription_journal');
        }
        $this->db->prepare('INSERT INTO audit_logs '
            . '(actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) '
            . 'VALUES (NULL,\'credit.journal.posted\',\'wallet_journal\',?,?,UTC_TIMESTAMP(6))')
            ->execute([(string) $id, $key]);
        return $id;
    }

    private function balance(int $account): int
    {
        $this->db->prepare('INSERT INTO wallet_balances (account_id) VALUES (?) ON DUPLICATE '
            . 'KEY UPDATE account_id=account_id')
            ->execute([$account]);
        $query = $this->db->prepare('SELECT current_minor FROM wallet_balances WHERE account_id=? FOR UPDATE');
        $query->execute([$account]);
        $cached = (string) $query->fetchColumn();
        $query = $this->db->prepare('SELECT COALESCE(SUM(CAST(credit_minor AS SIGNED)-CAST(debit_minor AS SIGNED)),0) '
            . 'FROM wallet_postings WHERE account_id=?');
        $query->execute([$account]);
        $actual = (string) $query->fetchColumn();
        if (!preg_match('/^-?[0-9]{1,19}$/D', $actual) || (string) (int) $actual !== $actual || $cached !== $actual) {
            throw new PaymentException('credit_cache_reconciliation_required', 503);
        }
        return (int) $actual;
    }

    private function assertTransaction(): void
    {
        if (!$this->db->inTransaction() || PHP_INT_SIZE !== 8) {
            throw new PaymentException('credit_transaction_required', 503);
        }
    }
}
