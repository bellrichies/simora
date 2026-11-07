<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Experience\ExperienceException;
use PDO;
use Throwable;

final class ExperienceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function now(): string
    {
        return (string) $this->db->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
    }

    public function owner(int $owner): ?array
    {
        return $this->one("SELECT id,email_verified_at,created_at FROM users WHERE id=? AND "
            . "status='active' FOR UPDATE", [$owner]);
    }

    public function activeAgent(int $id): bool
    {
        return $this->one("SELECT id FROM users WHERE id=? AND status='active' AND "
            . "email_verified_at IS NOT NULL", [$id]) !== null;
    }

    public function audit(int $actor, string $operation, string $type, string $id, string $request): void
    {
        $this->execute('INSERT INTO audit_logs '
            . '(actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) '
            . 'VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))', [$actor, $operation, $type, $id, $request]);
    }

    /** Caller holds the actor row lock: counts are shared and atomic across sessions/processes. */
    public function rate(int $actor, string $operation, int $seconds, int $limit): void
    {
        $row = $this->one('SELECT COUNT(*) AS total FROM audit_logs WHERE actor_user_id=? AND operation=? '
            . 'AND occurred_at>UTC_TIMESTAMP(6)-INTERVAL ? SECOND', [$actor, $operation, $seconds]);
        if ((int) $row['total'] >= $limit) {
            throw new ExperienceException('rate_limited', 429, $seconds);
        }
    }

    public function reviewByOwner(int $owner): ?array
    {
        return $this->one('SELECT * FROM reviews WHERE author_user_id=? FOR UPDATE', [$owner]);
    }

    public function review(string $public): ?array
    {
        return $this->one('SELECT * FROM reviews WHERE public_id=? FOR UPDATE', [$public]);
    }

    public function reviews(bool $queue, int $before, int $limit): array
    {
        $query = $this->db->prepare('SELECT * FROM reviews WHERE id<? '
            . ($queue ? "AND status IN ('pending','published','hidden','rejected') " : "AND status='published' ")
            . 'ORDER BY id DESC LIMIT ?');
        $query->bindValue(1, $before, PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function createReview(
        int $owner,
        int $rating,
        string $title,
        string $body,
        string $policy,
        string $key,
        string $hash
    ): array {
        $this->execute(
            'INSERT INTO reviews (public_id,author_user_id,rating,title,body,policy_reference,'
            . 'request_key,fingerprint,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            ['rev_' . bin2hex(random_bytes(16)), $owner, $rating, $title, $body, $policy, $key, $hash]
        );
        return $this->reviewByOwner($owner);
    }

    public function moderation(int $actor, string $key): ?array
    {
        return $this->one('SELECT * FROM review_moderation WHERE actor_user_id=? AND request_key=?', [$actor, $key]);
    }

    public function decideReview(
        array $review,
        int $actor,
        string $state,
        string $reason,
        string $policy,
        string $key,
        string $hash
    ): array {
        $this->execute('UPDATE reviews SET status=?,version=version+1,published_at='
            . "CASE WHEN ?='published' THEN UTC_TIMESTAMP(6) ELSE NULL END WHERE id=?", [$state, $state,
                $review['id']]);
        $this->execute(
            'INSERT INTO review_moderation (review_id,actor_user_id,from_status,to_status,reason_code,'
            . 'policy_reference,request_key,fingerprint,resulting_version,created_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$review['id'], $actor, $review['status'], $state, $reason, $policy, $key, $hash,
                (int) $review['version'] + 1]
        );
        return $this->review($review['public_id']);
    }

    public function emitReview(array $review): void
    {
        $this->execute(
            'INSERT INTO transactional_outbox (event_key,review_id,event_type,payload,created_at) '
            . "VALUES (?,?,'review.visibility.changed',?,UTC_TIMESTAMP(6))",
            ['review:' . $review['id'] . ':' . $review['version'], $review['id'],
            json_encode(
                ['review_id' => $review['public_id'], 'version' => (int) $review['version']],
                JSON_THROW_ON_ERROR
            )]
        );
    }

    public function ticket(string $public): ?array
    {
        return $this->one('SELECT * FROM support_tickets WHERE public_id=? FOR UPDATE', [$public]);
    }

    public function ticketRequest(int $owner, string $key): ?array
    {
        return $this->one('SELECT * FROM support_tickets WHERE owner_user_id=? AND request_key=?', [$owner, $key]);
    }

    public function tickets(int $actor, bool $triage, bool $restricted, int $before, int $limit): array
    {
        $sql = 'SELECT * FROM support_tickets WHERE id<? AND ';
        $values = [$before];
        if ($triage) {
            $sql .= $restricted ? '1=1 ' : "category<>'privacy_security' ";
        } else {
            $sql .= 'owner_user_id=? ';
            $values[] = $actor;
        }
        $sql .= 'ORDER BY id DESC LIMIT ' . $limit;
        return $this->all($sql, $values);
    }

    public function createTicket(
        int $owner,
        string $subject,
        string $category,
        string $policy,
        string $key,
        string $hash
    ): array {
        $id = 'tic_' . bin2hex(random_bytes(16));
        $this->execute(
            'INSERT INTO support_tickets (public_id,owner_user_id,subject,category,policy_reference,'
            . 'request_key,fingerprint,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            [$id, $owner, $subject, $category, $policy, $key, $hash]
        );
        return $this->ticket($id);
    }

    public function messageRequest(int $actor, string $key): ?array
    {
        return $this->one('SELECT * FROM support_messages WHERE actor_user_id=? AND request_key=?', [$actor, $key]);
    }

    public function message(
        array $ticket,
        int $actor,
        string $body,
        string $visibility,
        string $action,
        string $key,
        string $hash,
        int $version
    ): array {
        $id = 'msg_' . bin2hex(random_bytes(16));
        $this->execute(
            'INSERT INTO support_messages (public_id,ticket_id,actor_user_id,body,visibility,action,'
            . 'request_key,fingerprint,resulting_version,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$id, $ticket['id'], $actor, $body, $visibility, $action, $key, $hash, $version]
        );
        $row = $this->one('SELECT * FROM support_messages WHERE public_id=?', [$id]);
        $this->execute('INSERT INTO transactional_outbox (event_key,support_message_id,event_type,payload,created_at) '
            . 'VALUES (?,?,?,?,UTC_TIMESTAMP(6))', ['support:message:' . $row['id'], $row['id'],
                'support.message.created',
                json_encode(['ticket_id' => $ticket['public_id'], 'message_id' => $id,
                    'visibility' => $visibility], JSON_THROW_ON_ERROR)]);
        return $row;
    }

    public function messages(int $ticket, bool $staff, int $before, int $limit): array
    {
        return $this->all('SELECT m.*,t.owner_user_id FROM support_messages m JOIN '
            . 'support_tickets t ON t.id=m.ticket_id '
            . 'WHERE ticket_id=? AND m.id<? ' . ($staff ? '' : "AND m.visibility='customer' ")
            . 'ORDER BY m.id DESC LIMIT ' . $limit, [$ticket, $before]);
    }

    public function updateTicket(int $id, string $status): void
    {
        $this->execute('UPDATE support_tickets SET '
            . 'status=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$status, $id]);
    }

    public function assignTicket(int $id, int $agent): void
    {
        $this->execute('UPDATE support_tickets SET assigned_user_id=?,version=version+1 WHERE id=?', [$agent, $id]);
    }

    public function articles(int $before, int $limit): array
    {
        return $this->all("SELECT * FROM faq_articles WHERE status='published' AND id<? "
            . "ORDER BY id DESC LIMIT " . $limit, [$before]);
    }

    public function article(string $slug, int $version): ?array
    {
        return $this->one('SELECT * FROM faq_articles WHERE slug=? AND version=? FOR UPDATE', [$slug, $version]);
    }

    public function articleRequest(int $actor, string $key): ?array
    {
        return $this->one('SELECT * FROM faq_articles WHERE author_user_id=? AND request_key=?', [$actor, $key]);
    }

    public function draftArticle(
        int $actor,
        string $slug,
        int $version,
        string $title,
        string $body,
        string $policy,
        string $key,
        string $hash
    ): array {
        $this->execute(
            'INSERT INTO faq_articles (slug,version,title,body,author_user_id,policy_reference,request_key,'
            . 'fingerprint,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$slug, $version, $title, $body, $actor, $policy, $key, $hash]
        );
        return $this->article($slug, $version);
    }

    public function publishArticle(string $slug, int $version, int $actor, bool $withdraw): array
    {
        $this->all('SELECT id FROM faq_articles WHERE slug=? ORDER BY id FOR UPDATE', [$slug]);
        if (!$withdraw) {
            $this->execute("UPDATE faq_articles SET status='withdrawn' WHERE slug=? AND status='published'", [$slug]);
        }
        $this->execute('UPDATE faq_articles SET status=?,publisher_user_id=?,published_at=UTC_TIMESTAMP(6) '
            . 'WHERE slug=? AND version=?', [$withdraw ? 'withdrawn' : 'published', $actor, $slug, $version]);
        return $this->article($slug, $version);
    }

    public function referralCode(int $owner): ?array
    {
        return $this->one('SELECT * FROM referral_codes WHERE user_id=? FOR UPDATE', [$owner]);
    }

    public function createReferralCode(int $owner, string $policy): array
    {
        $this->execute(
            'INSERT INTO referral_codes (user_id,code,policy_reference,created_at) VALUES (?,?,?,UTC_TIMESTAMP(6))',
            [$owner, bin2hex(random_bytes(16)), $policy]
        );
        return $this->referralCode($owner);
    }

    public function referralCount(int $owner): int
    {
        $row = $this->one('SELECT COUNT(*) AS total FROM referral_attributions WHERE referrer_user_id=?', [$owner]);
        return (int) $row['total'];
    }

    private function execute(string $sql, array $values): void
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
    }

    private function one(string $sql, array $values): ?array
    {
        $rows = $this->all($sql, $values);
        return $rows[0] ?? null;
    }

    private function all(string $sql, array $values): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
