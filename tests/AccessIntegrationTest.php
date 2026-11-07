<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Infrastructure\MigrationRunner;
use App\Repositories\AccessRepository;
use App\Services\AccessService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SimoraTests\Support\AccessTestDatabase;

/** Actual MySQL tests, isolated by bin/test-access.ps1. Never grants access to application users. */
final class AccessIntegrationTest extends TestCase
{
    private PDO $db;
    private AccessService $service;
    private int $owner;
    private string $token;
    private string $csrf;
    private SessionCredentials $credentials;

    public static function setUpBeforeClass(): void
    {
        $db = AccessTestDatabase::connect(true);
        $runner = new MigrationRunner($db, dirname(__DIR__) . '/database/migrations');
        foreach (
            ['010_catalog', '020_identity', '030_source_provenance', '080_access_notifications',
            '081_approved_access_policy', '090_payments_wallet_gated'] as $target
        ) {
            $runner->migrate($target, 'isolated_access_test', 'unit-test');
        }
        $rerun = $runner->migrate('081_approved_access_policy', 'isolated_access_test', 'unit-test');
        self::assertStringContainsString('already applied', end($rerun));
        $db->exec("INSERT INTO roles (code) VALUES ('unit-access')");
        $db->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r "
            . "CROSS JOIN permissions p WHERE r.code='unit-access'");
    }

    protected function setUp(): void
    {
        $this->db = AccessTestDatabase::connect();
        $this->service = new AccessService(new AccessRepository($this->db, 'sandbox'));
        $this->db->prepare('INSERT INTO users (normalized_email,password_hash,display_name,created_at) '
            . "VALUES (?,'unit-not-a-password','Unit only',UTC_TIMESTAMP(6))")
            ->execute([bin2hex(random_bytes(8)) . '@example.invalid']);
        $this->owner = (int) $this->db->lastInsertId();
        $this->token = bin2hex(random_bytes(32));
        $this->csrf = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO sessions (user_id,token_hash,csrf_token_hash,created_at,expires_at) '
            . 'VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')
            ->execute([$this->owner, hash('sha256', $this->token, true), hash('sha256', $this->csrf, true)]);
        $this->db->prepare("INSERT INTO user_roles (user_id,role_id,assigned_at) SELECT ?,id,UTC_TIMESTAMP(6) "
            . "FROM roles WHERE code='unit-access'")->execute([$this->owner]);
        $this->credentials = new SessionCredentials(true, $this->token, $this->csrf);
    }

    private function grant(string $feature = 'unit.feature', int $limit = 2): int
    {
        $this->db->prepare('INSERT INTO entitlements (user_id,feature_code,scope_key,quota_limit,period_seconds,'
            . 'period_anchor_at,starts_at,ends_at,approved_by,approval_reference,approved_at) '
            . "VALUES (?,?,'global',?,86400,UTC_DATE(),UTC_TIMESTAMP(6)-INTERVAL 1 DAY,"
            . "UTC_TIMESTAMP(6)+INTERVAL 1 DAY,?,'unit-only-approval',UTC_TIMESTAMP(6)-INTERVAL 2 DAY)")
            ->execute([$this->owner, $feature, $limit, $this->owner]);
        return (int) $this->db->lastInsertId();
    }

    private function consume(string $key, ?callable $action = null, int $cost = 1): array
    {
        return $this->service->consume(
            $this->credentials,
            'unit.feature',
            'global',
            $key,
            $cost,
            str_repeat('a', 64),
            $action ?? static fn (PDO $db): array => ['unit' => true]
        );
    }

    /** Unit-only trusted-ledger fixture; this does not verify or activate a real provider payment. */
    private function subscribe(string $code, bool $confirmed = true): int
    {
        $policy = \App\Domain\Access\PlanPolicy::all()[$code];
        $this->db->exec('UPDATE users SET email_verified_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE id=' . $this->owner);
        $version = $this->db->prepare('SELECT v.id FROM plan_versions v JOIN plans p ON p.id=v.plan_id '
            . 'WHERE p.code=? AND v.approval_reference=?');
        $version->execute([$code, \App\Domain\Access\PlanPolicy::APPROVAL]);
        $versionId = (int) $version->fetchColumn();
        $this->db->prepare('INSERT INTO subscriptions (user_id,plan_version_id,status,starts_at,ends_at) '
            . "VALUES (?,?,'active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL ? SECOND)")
            ->execute([$this->owner, $versionId, $policy['validity_seconds']]);
        $id = (int) $this->db->lastInsertId();
        if ($confirmed) {
            $this->confirm($id);
        }
        return $id;
    }

    private function confirm(int $id): void
    {
        $query = $this->db->prepare('SELECT s.*,r.amount_minor FROM subscriptions s JOIN plan_prices '
            . 'r ON r.plan_version_id=s.plan_version_id WHERE s.id=?');
        $query->execute([$id]);
        $s = $query->fetch();
        $this->db->prepare('INSERT INTO invoices '
            . '(public_id,user_id,plan_version_id,subscription_id,operation,subtotal_minor,tax_minor,total_minor,'
            . 'currency,validity_seconds,tax_reference,invoice_reference,approval_reference,customer_email_sha256,'
            . 'created_at,expires_at) '
            . "VALUES (?,?,?,?,'purchase',?,0,?,'NGN',86400,'unit','unit','unit',?,"
            . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')
            ->execute(['inv_' . bin2hex(random_bytes(16)), $s['user_id'], $s['plan_version_id'], $id,
                $s['amount_minor'], $s['amount_minor'], str_repeat('x', 32)]);
        $invoice = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO payments '
            . '(public_id,user_id,invoice_id,provider,account_code,environment,merchant_reference,request_key,'
            . 'fingerprint,amount_minor,currency,status,created_at) '
            . "VALUES (?,?,?,'unit','unit','sandbox',?,?,?,?,'NGN','succeeded',UTC_TIMESTAMP(6))")
            ->execute(['pay_' . bin2hex(random_bytes(16)), $s['user_id'], $invoice, bin2hex(random_bytes(16)),
                bin2hex(random_bytes(16)), str_repeat('a', 64), $s['amount_minor']]);
        $payment = (int) $this->db->lastInsertId();
        $this->db->prepare("UPDATE invoices SET status='paid',paid_payment_id=? WHERE id=?")
            ->execute([$payment, $invoice]);
        $this->db->prepare('INSERT INTO subscription_events '
            . '(subscription_id,actor_user_id,payment_id,event_type,evidence_reference,occurred_at) '
            . "VALUES (?,?,?,'payment.confirmed','unit-only-not-provider-evidence',UTC_TIMESTAMP(6))")
            ->execute([$id, $this->owner, $payment]);
    }

    private function planOperation(string $feature, string $key, ?callable $action = null): array
    {
        return $this->service->performPlan(
            $this->credentials,
            $feature,
            ['spe'],
            ['over_15'],
            [],
            $key,
            str_repeat('a', 64),
            $action ?? static fn ($db, $decision): array => ['feature' => $decision['feature']]
        );
    }

    private function denied(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Expected access denial.');
        } catch (AccessException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public function testOnlyApprovedCatalogVersionsAndImmutablePrices(): void
    {
        $features = [['feature_code' => 'unit.catalog', 'scope_key' => 'global', 'quota_limit' => 2,
            'period_seconds' => 86400]];
        $version = $this->service->approvePlan($this->credentials, 'trial', $features, 'unit-only-policy');
        $plans = $this->service->plans();
        self::assertCount(4, $plans);
        $trial = array_values(array_filter($plans, static fn ($row) => $row['code'] === 'trial'))[0];
        self::assertSame('200000', $trial['amount_minor']);
        self::assertFalse($trial['purchase_available']);
        self::assertEquals($features, $trial['features']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn());
        $new = $this->service->approvePlan($this->credentials, 'trial', $features, 'unit-new-version');
        self::assertGreaterThan($version, $new);
        self::assertSame('200000', (string) $this->db->query('SELECT amount_minor FROM plan_prices '
            . 'WHERE plan_version_id=' . $version)->fetchColumn());
    }

    public function testQuotaIdempotencyLimitAndBusinessRollback(): void
    {
        $this->grant();
        $first = $this->consume('same');
        $repeat = $this->consume('same', static function (): void {
            self::fail('Duplicate action ran.');
        });
        self::assertFalse($first['replayed']);
        self::assertTrue($repeat['replayed']);
        self::assertSame($first['result'], $repeat['result']);
        $this->denied(fn () => $this->consume('same', null, 2), 'idempotency_conflict');
        try {
            $this->consume('failed', static function (PDO $db): void {
                $db->exec("INSERT INTO plans (code,name) VALUES ('unit-rollback','Unit rollback')");
                throw new RuntimeException('Unit action failed.');
            });
        } catch (RuntimeException $error) {
            self::assertSame('Unit action failed.', $error->getMessage());
        }
        $count = $this->db->query("SELECT COUNT(*) FROM plans WHERE code='unit-rollback'")->fetchColumn();
        self::assertSame(0, (int) $count);
        self::assertSame(1, (int) $this->db->query('SELECT SUM(used_count) FROM usage_counters WHERE user_id='
            . $this->owner)->fetchColumn());
        $this->consume('last');
        $this->denied(fn () => $this->consume('exhausted'), 'quota_exhausted');
    }

    public function testNoDefaultGrantAndCurrentDatabaseSessionVerification(): void
    {
        $this->denied(fn () => $this->consume('none'), 'entitlement_denied');
        $this->grant();
        foreach (
            [new SessionCredentials(false, $this->token, $this->csrf),
            new SessionCredentials(true, str_repeat('a', 64), $this->csrf),
            new SessionCredentials(true, $this->token, str_repeat('a', 64))] as $index => $credentials
        ) {
            $this->denied(fn () => $this->service->consume(
                $credentials,
                'unit.feature',
                'global',
                'bad',
                1,
                str_repeat('a', 64),
                static fn () => []
            ), ['https_required', 'authentication_required', 'csrf_failed'][$index]);
        }
        $this->db->exec('UPDATE sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE user_id=' . $this->owner);
        $this->denied(fn () => $this->consume('revoked'), 'authentication_required');
    }

    public function testNotificationOwnershipDedupeAndReadIdempotency(): void
    {
        $id = $this->service->notify(
            $this->credentials,
            $this->owner,
            'unit-notification',
            'unit.event',
            ['title' => 'Unit only', 'body' => '<script>plain text</script>']
        );
        self::assertSame($id, $this->service->notify(
            $this->credentials,
            $this->owner,
            'unit-notification',
            'unit.event',
            ['title' => 'Unit only', 'body' => '<script>plain text</script>']
        ));
        self::assertCount(1, $this->service->notifications($this->credentials));
        self::assertSame([], $this->service->notifications($this->credentials, $id));
        $first = $this->service->markRead($this->credentials, $id, 'unit-read');
        self::assertSame($first, $this->service->markRead($this->credentials, $id, 'unit-read-again'));
        $this->denied(fn () => $this->service->markRead($this->credentials, $id + 99999, 'unit-missing'), 'not_found');
        $this->db->exec("INSERT INTO users (normalized_email,password_hash,display_name,created_at) "
            . "VALUES ('other-" . $this->owner . "@example.invalid','unit-not-a-password','Unit',UTC_TIMESTAMP(6))");
        $other = (int) $this->db->lastInsertId();
        $this->db->exec('UPDATE notifications SET user_id=' . $other . ' WHERE id=' . $id);
        $this->denied(fn () => $this->service->markRead($this->credentials, $id, 'unit-other-owner'), 'not_found');
    }

    public function testRevocationAndOverlappingGrantsFailClosed(): void
    {
        $id = $this->grant();
        $this->grant(); // Deliberately bypass administrative overlap rejection to exercise corrupted state.
        $this->denied(fn () => $this->consume('overlap'), 'entitlement_denied');
        self::assertTrue($this->service->revoke($this->credentials, $this->owner, $id));
        self::assertFalse($this->service->revoke($this->credentials, $this->owner, $id));
        self::assertFalse($this->consume('remaining')['replayed']);
    }

    public static function races(): array
    {
        return [['race-a', 'race-b', [200, 429]], ['same-race', 'same-race', [200, 200]]];
    }

    #[DataProvider('races')]
    public function testConcurrentLastQuotaSlotUsesRealIndependentConnections(
        string $first,
        string $second,
        array $expected
    ): void {
        $this->grant('unit.race', 1);
        putenv('SIMORA_ACCESS_TEST_TOKEN=' . $this->token);
        putenv('SIMORA_ACCESS_TEST_CSRF=' . $this->csrf);
        putenv('SIMORA_ACCESS_TEST_OWNER=' . $this->owner);
        $workers = [];
        foreach ([$first, $second] as $key) {
            $process = proc_open(
                [PHP_BINARY, 'tests/access-quota-worker.php', $key],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__)
            );
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $statuses = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $statuses[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        self::assertSame($expected, $statuses);
        self::assertSame(1, (int) $this->db->query('SELECT used_count FROM usage_counters WHERE user_id='
            . $this->owner)->fetchColumn());
        $effectQuery = "SELECT COUNT(*) FROM audit_logs WHERE operation='unit.race.effect' AND actor_user_id=";
        self::assertSame(1, (int) $this->db->query($effectQuery . $this->owner)->fetchColumn());
        putenv('SIMORA_ACCESS_TEST_TOKEN');
        putenv('SIMORA_ACCESS_TEST_CSRF');
        putenv('SIMORA_ACCESS_TEST_OWNER');
    }

    public function testResetWindowsAndExpiryUseDatabaseTime(): void
    {
        $id = $this->grant();
        $this->consume('today');
        $this->db->exec('UPDATE usage_counters SET period_start=period_start-INTERVAL 1 DAY,used_count=2 '
            . 'WHERE user_id=' . $this->owner);
        $this->consume('new-period');
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM usage_counters WHERE user_id='
            . $this->owner)->fetchColumn());
        $this->db->exec('UPDATE entitlements SET ends_at=UTC_TIMESTAMP(6) WHERE id=' . $id);
        $this->denied(fn () => $this->consume('expired'), 'entitlement_denied');
    }

    public function testPendingOrExpiredSubscriptionCannotSupplyAnEntitlement(): void
    {
        $version = $this->service->approvePlan($this->credentials, 'pro', [['feature_code' => 'unit.feature',
            'scope_key' => 'global', 'quota_limit' => 2, 'period_seconds' => 86400]], 'unit-only-policy');
        $this->db->exec('INSERT INTO subscriptions (user_id,plan_version_id,starts_at,ends_at) VALUES ('
            . $this->owner . ',' . $version . ',UTC_TIMESTAMP(6)-INTERVAL 1 DAY,UTC_TIMESTAMP(6)+INTERVAL 1 DAY)');
        $subscription = (int) $this->db->lastInsertId();
        $grant = $this->grant();
        $this->db->exec('UPDATE entitlements SET subscription_id=' . $subscription . ' WHERE id=' . $grant);
        $this->denied(fn () => $this->consume('pending'), 'entitlement_denied');
        $this->db->exec("UPDATE subscriptions SET status='active' WHERE id=" . $subscription);
        self::assertFalse($this->consume('active')['replayed']);
        $this->db->exec('UPDATE subscriptions SET ends_at=UTC_TIMESTAMP(6) WHERE id=' . $subscription);
        $this->denied(fn () => $this->consume('expired'), 'entitlement_denied');
    }

    public function testReadsNeedNoCsrfButWritesAndRbacCannotBeBypassed(): void
    {
        self::assertSame([], $this->service->notifications(new SessionCredentials(true, $this->token)));
        $readOnly = new SessionCredentials(true, $this->token);
        $this->denied(fn () => $this->service->markRead($readOnly, 1, 'unit'), 'csrf_failed');
        $this->db->exec('DELETE FROM user_roles WHERE user_id=' . $this->owner);
        $this->denied(fn () => $this->service->notifications($this->credentials), 'forbidden');
    }

    public function testAdministrativeGrantIsAuditedAndCannotOverlapOrBackdate(): void
    {
        $clock = $this->db->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
        $now = new \DateTimeImmutable($clock, new \DateTimeZone('UTC'));
        $grant = ['subscription_id' => null, 'feature_code' => 'unit.admin-grant', 'scope_key' => 'global',
            'quota_limit' => 2, 'period_seconds' => 86400,
            'period_anchor_at' => $now->setTime(0, 0)->format('Y-m-d\TH:i:s\Z'),
            'starts_at' => $now->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $now->modify('+1 day')->format('Y-m-d\TH:i:s\Z')];
        $id = $this->service->grant($this->credentials, $this->owner, $grant, 'unit-approved-policy');
        self::assertGreaterThan(0, $id);
        $this->denied(
            fn () => $this->service->grant($this->credentials, $this->owner, $grant, 'unit-approved-policy'),
            'overlapping_entitlement'
        );
        $auditQuery = "SELECT COUNT(*) FROM audit_logs WHERE operation='entitlement.granted' AND actor_user_id=";
        self::assertSame(1, (int) $this->db->query($auditQuery . $this->owner)->fetchColumn());
        $grant['starts_at'] = $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->grant($this->credentials, $this->owner, $grant, 'unit-backdated');
    }

    public function testAccessControllerUsesVerifiedOwnerAndStructuredErrors(): void
    {
        $controller = new \App\Http\AccessController($this->service);
        $readOnly = new SessionCredentials(true, $this->token);
        $response = $controller->handle('GET', '/api/v1/me/notifications', [], $readOnly);
        self::assertSame(200, $response->status);
        self::assertSame([], $response->body['data']);
        self::assertSame('server_verified', $response->body['meta']['data_mode']);
        $invalid = $controller->handle('GET', '/api/v1/me/notifications', ['user_id' => '1'], $this->credentials);
        self::assertSame(422, $invalid->status);
        $wrong = $controller->handle('POST', '/api/v1/me/notifications/1/read', [], $this->credentials);
        self::assertSame(405, $wrong->status);
        self::assertSame('PATCH', $wrong->headers['Allow']);
        $forged = $controller->handle(
            'GET',
            '/api/v1/subscription',
            ['access' => 'pro', 'user_id' => '1'],
            new SessionCredentials(true, null)
        );
        self::assertSame(401, $forged->status);
        self::assertSame('authentication_required', $forged->body['error']['code']);
    }

    public function testCompositeForeignKeysRejectCrossOwnerQuotaRows(): void
    {
        $grant = $this->grant();
        $this->db->exec("INSERT INTO users (normalized_email,password_hash,display_name,created_at) "
            . "VALUES ('fk-other-" . $this->owner . "@example.invalid','unit','Unit',UTC_TIMESTAMP(6))");
        $other = (int) $this->db->lastInsertId();
        try {
            $this->db->prepare('INSERT INTO usage_counters '
                . '(user_id,entitlement_id,feature_code,scope_key,period_start) '
                . "VALUES (?,?,'unit.feature','global',UTC_DATE())")->execute([$other, $grant]);
            self::fail('Cross-owner grant counter accepted.');
        } catch (PDOException $error) {
            self::assertSame(1452, $error->errorInfo[1]);
        }
        $this->consume('fk-unit');
        $counter = (int) $this->db->query('SELECT id FROM usage_counters WHERE user_id=' . $this->owner)->fetchColumn();
        try {
            $this->db->prepare('INSERT INTO usage_events (user_id,feature_code,request_id,counter_id,fingerprint,'
                . "delta,status,result_json,occurred_at) "
                . "VALUES (?,'unit.feature','unit-fk',?,?,1,'consumed','{}',UTC_TIMESTAMP(6))")
                ->execute([$other, $counter, str_repeat('a', 64)]);
            self::fail('Cross-owner usage event accepted.');
        } catch (PDOException $error) {
            self::assertSame(1452, $error->errorInfo[1]);
        }
    }

    public function testApprovedPolicySnapshotsAndThirtyDayMetadataWereMigrated(): void
    {
        foreach (\App\Domain\Access\PlanPolicy::all() as $code => $expected) {
            $query = $this->db->prepare('SELECT v.features,r.interval_code,v.approved_by,v.approval_kind '
                . 'FROM plan_versions v JOIN plans p ON p.id=v.plan_id JOIN plan_prices r ON r.plan_version_id=v.id '
                . 'WHERE p.code=? AND v.approval_reference=?');
            $query->execute([$code, \App\Domain\Access\PlanPolicy::APPROVAL]);
            $row = $query->fetch();
            self::assertSame($expected, \App\Domain\Access\PlanPolicy::verify(json_decode($row['features'], true)));
            self::assertNull($row['approved_by']);
            self::assertSame('documented_product', $row['approval_kind']);
            if (in_array($code, ['pro', 'max'], true)) {
                self::assertSame('thirty_days', $row['interval_code']);
            }
        }
    }

    public function testPlanReadsRequireConfirmationAndRespectTrialAndExpiry(): void
    {
        $subscription = $this->subscribe('trial', false);
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'prediction.analyse'),
            'paid_subscription_required'
        );
        $this->confirm($subscription);
        $allowed = $this->service->requirePlanRequest($this->credentials, 'prediction.analyse', ['spe'], ['over_15']);
        self::assertSame('trial', $allowed['plan_code']);
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'prediction.analyse', ['mlpe']),
            'model_or_market_denied'
        );
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'comparison.basic'),
            'feature_denied'
        );
        $this->db->exec('UPDATE users SET email_verified_at=NULL WHERE id=' . $this->owner);
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'prediction.analyse'),
            'trial_ineligible'
        );
        $this->db->exec('UPDATE subscriptions SET ends_at=UTC_TIMESTAMP(6) WHERE id=' . $subscription);
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'prediction.analyse'),
            'paid_subscription_required'
        );
    }

    public function testTrialCannotBeRepeatedByAnotherSubscription(): void
    {
        $old = $this->subscribe('trial');
        $this->db->exec("UPDATE subscriptions SET status='expired' WHERE id=" . $old);
        $this->subscribe('trial');
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'prediction.analyse'),
            'trial_ineligible'
        );
    }

    public function testUnlimitedAnalysisAndDailyExportQuotasAreDifferentPolicies(): void
    {
        $this->subscribe('standard');
        try {
            $this->planOperation('prediction.analyse', 'failed-plan', static function (): void {
                throw new RuntimeException('unit-failed-prediction');
            });
        } catch (RuntimeException $error) {
            self::assertSame('unit-failed-prediction', $error->getMessage());
        }
        for ($i = 0; $i < 6; $i++) {
            self::assertFalse($this->planOperation('prediction.analyse', 'analysis-' . $i)['replayed']);
        }
        self::assertTrue($this->planOperation('prediction.analyse', 'analysis-0')['replayed']);
        $grant = $this->db->query("SELECT * FROM entitlements WHERE user_id=" . $this->owner
            . " AND feature_code='prediction.analyse'")->fetch();
        self::assertNull($grant['quota_limit']);
        self::assertSame(1209600, (int) $grant['period_seconds']);
        $this->planOperation('csv.export', 'export-one');
        $this->planOperation('csv.export', 'export-two');
        $this->denied(fn () => $this->planOperation('csv.export', 'export-three'), 'quota_exhausted');
        $this->denied(fn () => $this->planOperation('comparison.export', 'report-one'), 'feature_denied');
        $dbNow = $this->db->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
        $clock = new \DateTimeImmutable($dbNow, new \DateTimeZone('UTC'));
        $period = $this->db->query("SELECT period_start FROM usage_counters WHERE user_id=" . $this->owner
            . " AND feature_code='csv.export'")->fetchColumn();
        self::assertSame(\App\Domain\Access\PlanPolicy::dailyAnchor($clock)->format('Y-m-d H:i:s.u'), $period);
    }

    public function testPaidUpgradeCarriesTodaysExportsAndPreservesGrantEvidence(): void
    {
        $old = $this->subscribe('standard');
        $this->planOperation('csv.export', 'old-one');
        $this->planOperation('csv.export', 'old-two');
        $this->db->exec("UPDATE subscriptions SET status='revoked' WHERE id=" . $old);
        $this->subscribe('pro');
        self::assertFalse($this->planOperation('comparison.export', 'new-three')['replayed']);
        $decision = $this->service->requirePlanRequest($this->credentials, 'comparison.full', ['beve'], ['under_15']);
        self::assertNull($decision['from']);
        $query = $this->db->query('SELECT used_count FROM usage_counters WHERE user_id=' . $this->owner);
        self::assertSame(3, (int) $query->fetchColumn());
        $grantCount = 'SELECT COUNT(DISTINCT entitlement_id) FROM usage_events WHERE user_id=';
        self::assertSame(2, (int) $this->db->query($grantCount . $this->owner)->fetchColumn());
    }

    public function testAnalyticsApiAppliesPlanBeforeItsUnavailablePersistenceGuard(): void
    {
        $this->subscribe('standard');
        $controller = new \App\Http\AnalyticsApiController(
            new \App\Services\AnalyticsApiService(require dirname(__DIR__) . '/config/platforms.php'),
            new \App\Infrastructure\SystemClock(),
            fn () => $this->service
        );
        $denied = $controller->handle('GET', '/api/v1/models/compare', ['models' => 'spe,beve'], $this->credentials);
        self::assertSame(403, $denied->status);
        $limited = $controller->handle(
            'GET',
            '/api/v1/models/compare',
            ['metrics' => 'model_agreement'],
            $this->credentials
        );
        self::assertSame(403, $limited->status);
        $allowed = $controller->handle('GET', '/api/v1/models/compare', ['models' => 'spe,mlpe'], $this->credentials);
        self::assertSame(503, $allowed->status); // No fabricated report when canonical model persistence is missing.
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM usage_events WHERE user_id='
            . $this->owner)->fetchColumn());
    }

    public function testRevokedDerivedFeatureCannotBeReadOrConsumedAgain(): void
    {
        $this->subscribe('pro');
        $this->planOperation('csv.export', 'before-revoke');
        $grant = (int) $this->db->query("SELECT id FROM entitlements WHERE user_id=" . $this->owner
            . " AND feature_code='csv.export'")->fetchColumn();
        self::assertTrue($this->service->revoke($this->credentials, $this->owner, $grant));
        $this->denied(
            fn () => $this->service->requirePlanRequest($this->credentials, 'comparison.export'),
            'entitlement_revoked'
        );
        $this->denied(fn () => $this->planOperation('csv.export', 'after-revoke'), 'entitlement_revoked');
    }
}
