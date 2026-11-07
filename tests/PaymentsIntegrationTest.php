<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\Access\SessionCredentials;
use App\Domain\Payments\PaymentApproval;
use App\Domain\Payments\PaymentException;
use App\Http\PaymentController;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\PaystackProvider;
use App\Infrastructure\SandboxInvoiceQuoteAuthority;
use App\Infrastructure\SystemClock;
use App\Repositories\AccessRepository;
use App\Repositories\PaymentRepository;
use App\Services\PaymentLifecycleService;
use App\Services\SubscriptionCreditLedger;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SimoraTests\Support\AccessTestDatabase;
use SimoraTests\Support\PaystackTestTransport;

final class PaymentsIntegrationTest extends TestCase
{
    private PDO $db;
    private PaymentLifecycleService $service;
    private PaymentRepository $repository;
    private SubscriptionCreditLedger $ledger;
    private PaystackTestTransport $http;
    private SessionCredentials $credentials;
    private int $owner;
    private string $email;

    public static function setUpBeforeClass(): void
    {
        $db = AccessTestDatabase::connect(true);
        $runner = new MigrationRunner($db, dirname(__DIR__) . '/database/migrations');
        $runner->migrate('090_payments_wallet_gated', 'isolated_payment_test', 'unit-test');
        $rerun = $runner->migrate('090_payments_wallet_gated', 'isolated_payment_test', 'unit-test');
        self::assertStringContainsString('already applied', end($rerun));
        $db->exec("INSERT INTO roles (code) VALUES ('unit-payments')");
        $db->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT "
            . "r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='unit-payments'");
    }

    protected function setUp(): void
    {
        $this->db = AccessTestDatabase::connect();
        $this->email = bin2hex(random_bytes(8)) . '@example.invalid';
        $this->db->prepare('INSERT INTO users '
            . '(normalized_email,password_hash,display_name,created_at,email_verified_at) '
            . "VALUES (?,'unit-only','Unit',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$this->email]);
        $this->owner = (int) $this->db->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO sessions '
            . '(user_id,token_hash,csrf_token_hash,created_at,expires_at) '
            . 'VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')
            ->execute([$this->owner, hash('sha256', $token, true), hash('sha256', $csrf, true)]);
        $this->db->prepare("INSERT INTO user_roles (user_id,role_id,assigned_at) SELECT "
            . "?,id,UTC_TIMESTAMP(6) FROM roles WHERE code='unit-payments'")->execute([$this->owner]);
        $this->credentials = new SessionCredentials(true, $token, $csrf);
        $access = new AccessRepository($this->db, 'sandbox');
        $this->repository = new PaymentRepository($this->db, $access);
        $this->ledger = new SubscriptionCreditLedger($this->db, $access);
        $this->http = new PaystackTestTransport();
        $clock = new SystemClock();
        $approval = new PaymentApproval(
            'paystack',
            'unit',
            'sandbox',
            'unit-only',
            'sandbox-only:no-real-tax',
            'sandbox-only:unissued-document',
            new DateTimeImmutable('+1 day'),
            300
        );
        $this->service = new PaymentLifecycleService(
            $this->repository,
            new PaystackProvider($this->http, $clock, 'unit', ['whk_unit' => 'whsec_unit_only']),
            new SandboxInvoiceQuoteAuthority(),
            $this->ledger,
            $approval,
            $clock,
            'https://example.invalid/plans'
        );
    }

    private function intent(
        string $plan = 'standard',
        string $key = 'unit-request',
        string $operation = 'purchase'
    ): array {
        $public = $this->service->prepare($this->credentials, $key, $plan, $operation);
        return $this->repository->findReference($public['reference']);
    }

    private function receipt(array $payment, string $id = '18446744073709551615'): int
    {
        $body = json_encode(['event' => 'charge.success', 'data' => ['id' => $id, 'domain' => 'test',
            'reference' => $payment['merchant_reference']]], JSON_THROW_ON_ERROR);
        $time = (string) time();
        $signature = 't=' . $time . ',v1=whk_unit:' . hash_hmac('sha512', $time . '.' . $body, 'whsec_unit_only');
        return (int) $this->service->receive($body, ['Paystack-Signature' => $signature])['receipt_id'];
    }

    private function evidence(array $p, string $id = '18446744073709551615'): void
    {
        $this->http->response = ['id' => $id, 'domain' => 'test', 'reference' => $p['merchant_reference'],
            'amount' => (int) $p['amount_minor'], 'currency' => 'NGN', 'status' => 'success',
            'paid_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1), 'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1),
            'customer' => ['email' => $this->email]];
        // DB microseconds precede a provider's next whole second, without slowing the test or using future evidence.
        $this->db->prepare('UPDATE invoices SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 SECOND WHERE id=?')
            ->execute([$p['invoice_id']]);
        $this->http->response['paid_at'] = $this->http->response['created_at'] = gmdate('Y-m-d\TH:i:s\Z');
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id='
            . $this->owner)->fetchColumn();
    }

    public function testVerifiedActivationIdempotencyBalanceAndSandboxIsolation(): void
    {
        $p = $this->intent();
        self::assertSame($p['public_id'], $this->service->prepare(
            $this->credentials,
            'unit-request',
            'standard',
            'purchase'
        )['payment_id']);
        self::assertSame(0, $this->rows('subscriptions'));
        $receipt = $this->receipt($p);
        self::assertSame($receipt, $this->receipt($p));
        self::assertSame([], $this->http->calls);
        $this->evidence($p);
        self::assertSame('subscription_confirmed', $this->service->process($receipt)['result_code']);
        self::assertSame('processed', $this->service->process($receipt)['status']);
        self::assertSame(1, $this->rows('subscriptions'));
        self::assertSame(2, $this->rows('wallet_journals'));
        self::assertSame('0', $this->service->wallet($this->credentials)['balance_minor']);
        $query = $this->db->query('SELECT j.id,SUM(p.debit_minor) AS d,SUM(p.credit_minor) AS c '
            . 'FROM wallet_journals j JOIN wallet_postings p ON '
            . 'p.journal_id=j.id WHERE j.user_id=' . $this->owner . ' GROUP BY j.id');
        foreach ($query as $journal) {
            self::assertSame((string) $p['amount_minor'], (string) $journal['d']);
            self::assertSame($journal['d'], $journal['c']);
        }
        self::assertCount(1, (new AccessRepository($this->db, 'sandbox'))->activePlanContexts($this->owner));
        self::assertSame([], (new AccessRepository($this->db))->activePlanContexts($this->owner));
        self::assertSame('3000000', $this->service->invoices($this->credentials)[0]['total_minor']);
        self::assertFalse($this->service->invoices($this->credentials)[0]['legal_document_issued']);
        $entries = $this->service->wallet($this->credentials, true);
        self::assertCount(2, $entries);
        self::assertSame('3000000', $entries[0]['debit_minor']);
        self::assertSame('3000000', $entries[1]['credit_minor']);
        self::assertSame(1, $this->rows('notifications'));
    }

    public function testAmountMismatchAndCallbackCannotActivate(): void
    {
        $p = $this->intent();
        $id = $this->receipt($p, '101');
        $this->evidence($p, '101');
        $this->http->response['amount']++;
        self::assertSame('evidence_mismatch', $this->service->process($id)['result_code']);
        self::assertSame(0, $this->rows('subscriptions'));
        self::assertSame(0, $this->rows('wallet_journals'));
    }

    public static function mismatchingEvidence(): array
    {
        return [['currency', 'USD'], ['email', 'other@example.invalid'], ['id', '222'],
            ['paid_at', '2000-01-01T00:00:00Z'], ['paid_at', '2099-01-01T00:00:00Z']];
    }

    #[DataProvider('mismatchingEvidence')]
    public function testEvidenceScopeAndPointInTimeMismatch(string $field, string $value): void
    {
        $p = $this->intent();
        $transaction = (string) (10000 + $this->owner);
        $id = $this->receipt($p, $transaction);
        $this->evidence($p, $transaction);
        if ($field === 'email') {
            $this->http->response['customer']['email'] = $value;
        } else {
            $this->http->response[$field] = $value;
        }
        self::assertSame('quarantined', $this->service->process($id)['status']);
        self::assertSame(0, $this->rows('subscriptions'));
        self::assertSame(0, $this->rows('wallet_journals'));
    }

    public function testRenewalQueuesExactFixedTermWithoutOverlap(): void
    {
        $p = $this->intent();
        $this->evidence($p, '301');
        $this->service->reconcile((int) $p['id']);
        $p2 = $this->intent('standard', 'renewal-request', 'renewal');
        $this->evidence($p2, '302');
        self::assertSame('subscription_confirmed', $this->service->reconcile((int) $p2['id'])['result_code']);
        $terms = $this->db->query('SELECT starts_at,ends_at,TIMESTAMPDIFF(SECOND,starts_at,ends_at) '
            . 'AS duration FROM subscriptions WHERE user_id=' . $this->owner . ' ORDER BY starts_at')->fetchAll();
        self::assertCount(2, $terms);
        self::assertSame($terms[0]['ends_at'], $terms[1]['starts_at']);
        self::assertSame(1209600, (int) $terms[1]['duration']);
        self::assertCount(1, (new AccessRepository($this->db, 'sandbox'))->activePlanContexts($this->owner));
        self::assertSame(4, $this->rows('wallet_journals'));
    }

    public function testSecondTrialIsRejectedAndPaidEvidenceCannotBeInvented(): void
    {
        $p = $this->intent('trial');
        $this->evidence($p, '401');
        $this->service->reconcile((int) $p['id']);
        $this->db->exec('UPDATE subscriptions SET ends_at=UTC_TIMESTAMP(6)-INTERVAL 1 '
            . 'SECOND, starts_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE user_id=' . $this->owner);
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('trial_ineligible');
        $this->intent('trial', 'second-trial');
    }

    public function testMissingCsrfAndForeignOwnerReadsAreDenied(): void
    {
        $this->intent();
        $bad = new SessionCredentials(true, $this->credentials->token, null);
        $controller = new PaymentController($this->service);
        self::assertSame(403, $controller->handle(
            'POST',
            '/api/v1/billing/checkouts',
            '{"plan":"max","operation":"purchase","request_key":"csrf"}',
            [],
            $bad
        )->status);
        $this->setUp();
        self::assertSame([], $this->service->invoices($this->credentials));
        self::assertSame([], $this->service->wallet($this->credentials, true));
        self::assertNull($this->service->wallet($this->credentials)['balance_minor']);
    }

    public function testPendingLookupRetriedThenConfirmed(): void
    {
        $p = $this->intent();
        $id = $this->receipt($p, '102');
        $this->evidence($p, '102');
        $this->http->response['status'] = 'pending';
        self::assertSame('verification_pending', $this->service->process($id)['result']);
        self::assertSame(0, $this->rows('subscriptions'));
        $this->http->response['status'] = 'success';
        self::assertSame('subscription_confirmed', $this->service->process($id)['result_code']);
    }

    public function testMissedWebhookReconciliationAndReversalReview(): void
    {
        $p = $this->intent();
        $this->evidence($p, '103');
        self::assertSame('subscription_confirmed', $this->service->reconcile((int) $p['id'])['result_code']);
        $this->http->response['status'] = 'reversed';
        self::assertSame('reversal_review', $this->service->reconcile((int) $p['id'])['result_code']);
        self::assertSame([], (new AccessRepository($this->db, 'sandbox'))->activePlanContexts($this->owner));
        self::assertSame(2, $this->rows('wallet_journals'));
    }

    public function testLedgerFailureRollsBackWholeActivation(): void
    {
        $p = $this->intent();
        $id = $this->receipt($p, '104');
        $this->evidence($p, '104');
        $this->db->prepare("INSERT INTO wallet_accounts "
            . "(user_id,owner_key,currency,account_type) VALUES "
            . "(?,?,'NGN','subscription_credit')")->execute([$this->owner, 'user:' . $this->owner]);
        $account = (int) $this->db->lastInsertId();
        $this->db->exec('INSERT INTO wallet_balances (account_id,current_minor) VALUES (' . $account . ',1)');
        try {
            $this->service->process($id);
            self::fail('Corrupt cache accepted.');
        } catch (PaymentException $error) {
            self::assertSame('credit_cache_reconciliation_required', $error->errorCode);
        }
        self::assertSame(0, $this->rows('subscriptions'));
        self::assertSame(0, $this->rows('wallet_journals'));
        self::assertSame('pending', $this->repository->findPayment((int) $p['id'])['status']);
        self::assertSame('received', $this->repository->receipt($id)['status']);
        $this->db->exec('UPDATE wallet_balances SET current_minor=0 WHERE account_id=' . $account);
        self::assertSame('subscription_confirmed', $this->service->process($id)['result_code']);
    }

    public function testCheckoutInitializeOneClaimAndNoProviderPlan(): void
    {
        $p = $this->intent();
        $this->http->response = ['reference' => $p['merchant_reference'],
            'authorization_url' => 'https://checkout.paystack.com/unit'];
        self::assertSame('initialized', $this->service->initialize((int) $p['id'])['status']);
        self::assertSame('reconciliation_required', $this->service->initialize((int) $p['id'])['status']);
        self::assertCount(1, $this->http->calls);
        self::assertArrayNotHasKey('plan', $this->http->calls[0][2]);
        self::assertSame('3000000', $this->http->calls[0][2]['amount']);
        self::assertSame(0, $this->rows('subscriptions'));
    }

    public function testOwnerReadsAndControllerRejectsClientAmountsAndPayoutRoutes(): void
    {
        $this->intent();
        $controller = new PaymentController($this->service);
        $response = $controller->handle(
            'POST',
            '/api/v1/billing/checkouts',
            '{"plan":"max","operation":"purchase","request_key":"x","amount":1}',
            [],
            $this->credentials
        );
        self::assertSame(422, $response->status);
        self::assertSame(401, $controller->handle(
            'GET',
            '/api/v1/billing/invoices',
            '',
            [],
            new SessionCredentials(true, null, null)
        )->status);
        self::assertFalse(PaymentController::matches('/api/v1/wallet/withdraw'));
        self::assertFalse(PaymentController::matches('/api/v1/billing/refunds'));
        self::assertSame(405, $controller->handle('POST', '/api/v1/wallet', '', [], $this->credentials)->status);
    }

    public function testConcurrentDeliveryCommitsOneSubscriptionAndTwoJournals(): void
    {
        $p = $this->intent();
        $receipt = $this->receipt($p, '106');
        $this->evidence($p, '106');
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/payment-race-worker.php', (string) $receipt],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                dirname(__DIR__)
            );
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode($this->http->response, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('processed', $output);
        }
        self::assertSame(1, $this->rows('subscriptions'));
        self::assertSame(2, $this->rows('wallet_journals'));
    }

    public function testApprovedAppendOnlyInternalReversal(): void
    {
        $p = $this->intent();
        $this->evidence($p, '105');
        $this->service->reconcile((int) $p['id']);
        $id = (int) $this->db->query('SELECT id FROM wallet_journals WHERE user_id=' . $this->owner
            . " AND purpose='subscription_application'")->fetchColumn();
        $reversed = $this->repository->transaction(fn () => $this->ledger->reverse(
            $this->credentials,
            $id,
            'unit-reverse',
            'unit-only-approval'
        ));
        self::assertSame(
            $reversed,
            $this->repository->transaction(fn () => $this->ledger->reverse(
                $this->credentials,
                $id,
                'unit-reverse',
                'unit-only-approval'
            ))
        );
        self::assertSame(3, $this->rows('wallet_journals'));
        self::assertSame('3000000', $this->service->wallet($this->credentials)['balance_minor']);
        self::assertFalse($this->service->wallet($this->credentials)['payouts_enabled']);
    }
}
