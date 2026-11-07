<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\Access\SessionCredentials;
use App\Http\ApiResponse;
use App\Http\ExperienceController;
use App\Infrastructure\ExperienceServiceFactory;
use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use SimoraTests\Support\AccessTestDatabase;

/** Financial fixtures and customer content never enter the application database. */
final class ExperienceIntegrationTest extends TestCase
{
    private PDO $db;
    private ExperienceController $api;
    private array $config;
    private array $owner;
    private array $other;
    private array $staff;

    public static function setUpBeforeClass(): void
    {
        $db = AccessTestDatabase::connect(true);
        $runner = new MigrationRunner($db, dirname(__DIR__) . '/database/migrations');
        $runner->migrate('110_chat_optional', 'isolated_experience_test', 'unit-test');
        $result = $runner->migrate('110_chat_optional', 'isolated_experience_test', 'unit-test');
        self::assertStringContainsString('already applied', end($result));
        $db->exec("INSERT INTO roles (code) VALUES ('unit-member'),('unit-staff')");
        $member = ['referrals.read', 'referrals.create', 'reviews.read', 'reviews.submit', 'support.read',
            'support.create', 'support.reply', 'chat.read', 'chat.join', 'chat.send'];
        $insert = $db->prepare("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r "
            . "CROSS JOIN permissions p WHERE r.code='unit-member' AND p.code=?");
        foreach ($member as $permission) {
            $insert->execute([$permission]);
        }
        $db->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r "
            . "CROSS JOIN permissions p WHERE r.code='unit-staff'");
    }

    protected function setUp(): void
    {
        $this->db = AccessTestDatabase::connect();
        $this->config = ['features' => array_fill_keys(['referrals', 'reviews', 'support', 'chat'], true),
            'approval_reference' => 'unit-only:experience', 'privacy_reference' => 'unit-only:privacy',
            'moderation_reference' => 'unit-only:moderation', 'retention_reference' => 'unit-only:retention',
            'expires_at' => '2099-01-01T00:00:00Z', 'write_limit_minute' => 100,
            'read_limit_minute' => 1000, 'tickets_limit_day' => 5, 'moderation_owner' => 'unit-moderator',
            'privacy_owner' => 'unit-privacy', 'abuse_process_reference' => 'unit-abuse',
            'rights_process_reference' => 'unit-rights'];
        $this->api = ExperienceServiceFactory::create($this->db, $this->config);
        $this->owner = $this->actor('unit-member');
        $this->other = $this->actor('unit-member');
        $this->staff = $this->actor('unit-staff');
    }

    private function actor(string $role): array
    {
        $this->db->prepare('INSERT INTO users '
            . '(normalized_email,password_hash,display_name,email_verified_at,created_at) '
            . "VALUES (?,'unit-only','Secret Fixture Name',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            ->execute([bin2hex(random_bytes(8)) . '@example.invalid']);
        $id = (int) $this->db->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO sessions (user_id,token_hash,csrf_token_hash,created_at,expires_at) '
            . 'VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')
            ->execute([$id, hash('sha256', $token, true), hash('sha256', $csrf, true)]);
        $this->db->prepare('INSERT INTO user_roles (user_id,role_id,assigned_at) SELECT ?,id,UTC_TIMESTAMP(6) '
            . 'FROM roles WHERE code=?')->execute([$id, $role]);
        return ['id' => $id, 'credentials' => new SessionCredentials(true, $token, $csrf)];
    }

    private function call(
        string $method,
        string $path,
        array $body = [],
        ?array $actor = null,
        array $query = []
    ): ApiResponse {
        return $this->api->handle(
            $method,
            $path,
            $query,
            $method === 'POST' ? json_encode((object) $body, JSON_THROW_ON_ERROR) : '',
            ($actor ?? $this->owner)['credentials']
        );
    }

    private function submitReview(?array $actor = null, string $key = 'review-unit'): ApiResponse
    {
        return $this->call('POST', '/api/v1/reviews', ['rating' => 1, 'title' => 'An honest review',
            'body' => 'I would like clearer explanations in the application.', 'request_key' => $key], $actor);
    }

    private function ticket(string $category = 'general', string $key = 'ticket-unit'): string
    {
        $response = $this->call('POST', '/api/v1/support/tickets', ['subject' => 'Help with my account',
            'category' => $category, 'body' => 'This is private customer content.', 'request_key' => $key]);
        self::assertSame(200, $response->status, json_encode($response->body));
        return $response->body['data']['id'];
    }

    private function chatReady(): void
    {
        self::assertSame(200, $this->call('POST', '/api/v1/admin/chat/room', ['open' => true], $this->staff)->status);
        foreach ([$this->owner, $this->other, $this->staff] as $actor) {
            self::assertSame(200, $this->call(
                'POST',
                '/api/v1/chat/membership',
                ['consent_reference' => 'unit-only:experience'],
                $actor
            )->status);
        }
    }

    public function testReviewPendingIndependentModerationWithdrawalAndPrivateIdentity(): void
    {
        $created = $this->submitReview();
        self::assertSame(200, $created->status);
        self::assertSame($created->body['data'], $this->submitReview()->body['data']);
        self::assertSame([], $this->call('GET', '/api/v1/reviews')->body['data']['items']);
        self::assertSame('pending', $this->call('GET', '/api/v1/me/review')->body['data']['status']);
        self::assertSame(409, $this->submitReview(null, 'another-key')->status);
        $id = $created->body['data']['id'];
        $decision = ['action' => 'approve', 'reason' => 'approved', 'version' => 1, 'request_key' => 'approve-unit'];
        self::assertSame(403, $this->call('POST', '/api/v1/admin/reviews/' . $id . '/decisions', $decision)->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/reviews/' . $id . '/decisions',
            $decision,
            $this->staff
        )->status);
        $rows = $this->call('GET', '/api/v1/reviews')->body['data']['items'];
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['rating']);
        self::assertSame('Community member', $rows[0]['author']);
        self::assertStringNotContainsString('Secret Fixture Name', json_encode($rows));
        self::assertStringNotContainsString('example.invalid', json_encode($rows));
        self::assertArrayNotHasKey('author_user_id', $rows[0]);
        self::assertSame(404, $this->call(
            'POST',
            '/api/v1/me/review/' . $id . '/withdraw',
            ['version' => 2, 'request_key' => 'withdraw'],
            $this->other
        )->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/me/review/' . $id . '/withdraw',
            ['version' => 2, 'request_key' => 'withdraw']
        )->status);
        self::assertSame([], $this->call('GET', '/api/v1/reviews')->body['data']['items']);
        self::assertSame(409, $this->submitReview(null, 'resubmit')->status);
    }

    public function testModeratorCannotPublishOwnReviewAndVersionsAreChecked(): void
    {
        $id = $this->submitReview($this->staff)->body['data']['id'];
        $route = '/api/v1/admin/reviews/' . $id . '/decisions';
        $decision = ['action' => 'approve', 'reason' => 'approved', 'version' => 1, 'request_key' => 'self'];
        self::assertSame(403, $this->call('POST', $route, $decision, $this->staff)->status);
        $moderator = $this->actor('unit-staff');
        $decision['version'] = 2;
        self::assertSame(409, $this->call('POST', $route, $decision, $moderator)->status);
        $decision['version'] = 1;
        self::assertSame(200, $this->call('POST', $route, $decision, $moderator)->status);
        self::assertSame(200, $this->call('POST', $route, $decision, $moderator)->status);
        $decision['reason'] = 'spam';
        self::assertSame(409, $this->call('POST', $route, $decision, $moderator)->status);
    }

    public function testSupportOwnerAssignmentInternalNoteAndResolutionPrivacy(): void
    {
        $id = $this->ticket();
        self::assertSame(404, $this->call('GET', '/api/v1/support/tickets/' . $id, [], $this->other)->status);
        self::assertSame(404, $this->call('GET', '/api/v1/admin/support/tickets/' . $id, [], $this->staff)->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/tickets/' . $id . '/assignment',
            ['agent_id' => $this->staff['id'], 'version' => 1],
            $this->staff
        )->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/tickets/' . $id . '/messages',
            ['body' => 'Internal fraud review note.', 'action' => 'note', 'version' => 2, 'request_key' => 'note'],
            $this->staff
        )->status);
        $customer = $this->call('GET', '/api/v1/support/tickets/' . $id)->body['data'];
        self::assertCount(1, $customer['messages']);
        self::assertStringNotContainsString('Internal fraud', json_encode($customer));
        $staff = $this->call('GET', '/api/v1/admin/support/tickets/' . $id, [], $this->staff)->body['data'];
        self::assertCount(2, $staff['messages']);
        self::assertSame('Customer', $staff['messages'][1]['author']);
        $reply = ['body' => 'We resolved the reported issue.', 'action' => 'resolve', 'version' => 3,
            'request_key' => 'resolve'];
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/tickets/' . $id . '/messages',
            $reply,
            $this->staff
        )->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/tickets/' . $id . '/messages',
            $reply,
            $this->staff
        )->status);
        self::assertSame(409, $this->call(
            'POST',
            '/api/v1/support/tickets/' . $id . '/messages',
            ['body' => 'Try another reply', 'action' => 'reply', 'version' => 4, 'request_key' => 'closed']
        )->status);
        $notifications = $this->db->query('SELECT payload FROM notifications WHERE user_id='
            . $this->owner['id'])->fetchAll();
        self::assertCount(1, $notifications);
        self::assertStringNotContainsString('Internal fraud', json_encode($notifications));
        self::assertStringNotContainsString('We resolved', json_encode($notifications));
    }

    public function testSecurityTicketsRequireRestrictedStaffPermission(): void
    {
        $id = $this->ticket('privacy_security');
        $this->db->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id "
            . "JOIN roles r ON r.id=rp.role_id WHERE p.code='support.restricted' AND r.code='unit-staff'");
        try {
            self::assertSame(404, $this->call(
                'POST',
                '/api/v1/admin/support/tickets/' . $id . '/assignment',
                ['agent_id' => $this->staff['id'], 'version' => 1],
                $this->staff
            )->status);
            self::assertSame([], array_values(array_filter($this->call(
                'GET',
                '/api/v1/admin/support/tickets',
                [],
                $this->staff
            )->body['data']['items'], fn ($row) => $row['id'] === $id)));
        } finally {
            $this->db->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r "
                . "CROSS JOIN permissions p WHERE r.code='unit-staff' AND p.code='support.restricted'");
        }
    }

    public function testRateLimitRollbackAndIdenticalRetryDoesNotConsume(): void
    {
        $this->config['write_limit_minute'] = 1;
        $this->api = ExperienceServiceFactory::create($this->db, $this->config);
        $id = $this->ticket();
        self::assertSame($id, $this->ticket());
        $denied = $this->call('POST', '/api/v1/support/tickets', ['subject' => 'Second ticket',
            'category' => 'general', 'body' => 'This should roll back completely.', 'request_key' => 'another']);
        self::assertSame(429, $denied->status);
        self::assertSame('60', $denied->headers['Retry-After']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM support_tickets WHERE owner_user_id='
            . $this->owner['id'])->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM support_messages m JOIN support_tickets t '
            . 'ON t.id=m.ticket_id WHERE t.owner_user_id=' . $this->owner['id'])->fetchColumn());
    }

    public function testArticlesNeedIndependentPublicationAndRemainVersioned(): void
    {
        $slug = 'unit-help-' . $this->owner['id'];
        $draft = ['slug' => $slug, 'version' => 1, 'title' => 'Using the application',
            'body' => 'These are approved help instructions for this isolated test.', 'request_key' => 'article'];
        self::assertSame(200, $this->call('POST', '/api/v1/admin/support/articles', $draft, $this->staff)->status);
        self::assertSame(403, $this->call(
            'POST',
            '/api/v1/admin/support/articles/' . $slug . '/1/publication',
            ['withdraw' => false],
            $this->staff
        )->status);
        $publisher = $this->actor('unit-staff');
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/articles/' . $slug . '/1/publication',
            ['withdraw' => false],
            $publisher
        )->status);
        self::assertCount(1, array_filter($this->call(
            'GET',
            '/api/v1/support/articles'
        )->body['data']['items'], fn ($r) => $r['slug'] === $slug));
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/support/articles/' . $slug . '/1/publication',
            ['withdraw' => true],
            $publisher
        )->status);
        self::assertSame([], array_values(array_filter($this->call(
            'GET',
            '/api/v1/support/articles'
        )->body['data']['items'], fn ($r) => $r['slug'] === $slug)));
    }

    public function testChatMembershipPremoderationReportsMuteAndHide(): void
    {
        $this->chatReady();
        $posted = $this->call('POST', '/api/v1/chat/messages', ['body' => 'Please explain the statistics.',
            'request_key' => 'chat']);
        self::assertSame(200, $posted->status);
        self::assertSame([], array_values(array_filter(
            $this->call('GET', '/api/v1/chat/messages')->body['data']['items'],
            fn ($r) => $r['id'] === $posted->body['data']['id']
        )));
        $id = $posted->body['data']['id'];
        $queue = $this->call('GET', '/api/v1/admin/chat/messages', [], $this->staff)->body['data']['items'];
        $queued = array_values(array_filter($queue, fn ($row) => $row['id'] === $id));
        self::assertSame((string) $this->owner['id'], $queued[0]['author_user_id']);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/chat/messages/' . $id . '/decisions',
            ['publish' => true, 'reason' => 'approved', 'version' => 1],
            $this->staff
        )->status);
        $visible = $this->call('GET', '/api/v1/chat/messages')->body['data']['items'];
        $message = array_values(array_filter($visible, fn ($row) => $row['id'] === $id));
        self::assertArrayNotHasKey('author_user_id', $message[0]);
        $report = $this->call(
            'POST',
            '/api/v1/chat/messages/' . $id . '/reports',
            ['reason' => 'privacy'],
            $this->other
        );
        self::assertSame(200, $report->status);
        self::assertSame($report->body['data'], $this->call('POST', '/api/v1/chat/messages/' . $id
            . '/reports', ['reason' => 'privacy'], $this->other)->body['data']);
        $reports = $this->call('GET', '/api/v1/admin/chat/reports', [], $this->staff)->body['data']['items'];
        self::assertNotEmpty($reports);
        self::assertArrayNotHasKey('actor_user_id', $reports[0]);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/chat/members/' . $this->owner['id'] . '/restriction',
            ['ban' => false, 'mute_minutes' => 10],
            $this->staff
        )->status);
        self::assertSame(403, $this->call('POST', '/api/v1/chat/messages', ['body' => 'Muted message',
            'request_key' => 'muted'])->status);
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/chat/messages/' . $id . '/decisions',
            ['publish' => false, 'reason' => 'privacy', 'version' => 2],
            $this->staff
        )->status);
        self::assertSame([], array_values(array_filter($this->call(
            'GET',
            '/api/v1/chat/messages'
        )->body['data']['items'], fn ($r) => $r['id'] === $id)));
    }

    public function testChatBanCannotBeResetByJoiningAgainAndConsentIsChecked(): void
    {
        $this->chatReady();
        self::assertSame(200, $this->call(
            'POST',
            '/api/v1/admin/chat/members/' . $this->owner['id'] . '/restriction',
            ['ban' => true, 'mute_minutes' => 0],
            $this->staff
        )->status);
        self::assertSame(403, $this->call(
            'POST',
            '/api/v1/chat/membership',
            ['consent_reference' => 'unit-only:experience']
        )->status);
        self::assertSame(403, $this->call('GET', '/api/v1/chat/messages')->status);
        self::assertSame(403, $this->call(
            'POST',
            '/api/v1/chat/membership',
            ['consent_reference' => 'wrong'],
            $this->other
        )->status);
    }

    public function testReferralsRevealOnlyOwnCodeAndNeverCreateFinancialRewards(): void
    {
        self::assertNull($this->call('GET', '/api/v1/referrals')->body['data']['code']);
        $code = $this->call('POST', '/api/v1/referrals/code')->body['data'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $code['code']);
        self::assertSame($code, $this->call('POST', '/api/v1/referrals/code')->body['data']);
        self::assertNull($this->call('GET', '/api/v1/referrals', [], $this->other)->body['data']['code']);
        self::assertFalse($code['rewards_enabled']);
        self::assertFalse($code['attribution_enabled']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM referral_rewards')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM wallet_journals')->fetchColumn());
    }

    public function testSharedRateLimitIsAtomicAcrossTwoSessionsAndProcesses(): void
    {
        $this->config['write_limit_minute'] = 1;
        $secondToken = bin2hex(random_bytes(32));
        $secondCsrf = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO sessions (user_id,token_hash,csrf_token_hash,created_at,expires_at) '
            . 'VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)')
            ->execute([$this->owner['id'], hash('sha256', $secondToken, true), hash('sha256', $secondCsrf, true)]);
        $sessions = [[$this->owner['credentials']->token, $this->owner['credentials']->csrfToken],
            [$secondToken, $secondCsrf]];
        $workers = [];
        foreach ($sessions as $index => [$token, $csrf]) {
            $pipes = [];
            $worker = proc_open(
                [PHP_BINARY, __DIR__ . '/experience-race-worker.php'],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                dirname(__DIR__)
            );
            self::assertIsResource($worker);
            fwrite($pipes[0], json_encode(['token' => $token, 'csrf' => $csrf, 'config' => $this->config,
                'body' => ['subject' => 'Concurrent ticket', 'category' => 'general',
                    'body' => 'Concurrent private request.',
                    'request_key' => 'race:' . $index]], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $workers[] = [$worker, $pipes];
        }
        $statuses = [];
        foreach ($workers as [$worker, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($worker), $error);
            $statuses[] = json_decode($output, true, 8, JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        self::assertSame([200, 429], $statuses);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM support_tickets WHERE owner_user_id='
            . $this->owner['id'])->fetchColumn());
    }

    public function testGatesAuthenticationCsrfUnknownFieldsAndUnverifiedAccounts(): void
    {
        $disabled = $this->config;
        $disabled['features']['reviews'] = false;
        $this->api = ExperienceServiceFactory::create($this->db, $disabled);
        self::assertSame(503, $this->submitReview()->status);
        $disabled = $this->config;
        $disabled['expires_at'] = '2000-01-01T00:00:00Z';
        $this->api = ExperienceServiceFactory::create($this->db, $disabled);
        self::assertSame(503, $this->call('GET', '/api/v1/support/tickets')->status);
        $disabled = $this->config;
        $disabled['moderation_owner'] = '';
        $this->api = ExperienceServiceFactory::create($this->db, $disabled);
        self::assertSame(503, $this->call('GET', '/api/v1/chat/messages')->status);
        $this->api = ExperienceServiceFactory::create($this->db, $this->config);
        $bad = ['credentials' => new SessionCredentials(true, $this->owner['credentials']->token, null)];
        self::assertSame(403, $this->submitReview($bad)->status);
        $anonymous = ['credentials' => new SessionCredentials(true, null, null)];
        self::assertSame(401, $this->call('GET', '/api/v1/reviews', [], $anonymous)->status);
        self::assertSame(422, $this->call('POST', '/api/v1/reviews', ['rating' => 5,
            'author_user_id' => $this->staff['id']])->status);
        self::assertSame(405, $this->call('DELETE', '/api/v1/reviews')->status);
        $this->db->exec('UPDATE users SET email_verified_at=NULL WHERE id=' . $this->owner['id']);
        self::assertSame(403, $this->submitReview()->status);
    }
}
