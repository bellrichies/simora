<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class AccessRepository
{
    public function __construct(private readonly PDO $db, private readonly string $paymentEnvironment = 'live')
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

    public function session(string $hash, bool $lock): ?array
    {
        return $this->one('SELECT s.id,s.user_id,s.csrf_token_hash FROM sessions s JOIN users u ON u.id=s.user_id '
            . "WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP(6) AND u.status='active'"
            . ($lock ? ' FOR UPDATE' : ''), [$hash]);
    }

    public function permitted(int $owner, string $permission): bool
    {
        return $this->one(
            'SELECT p.id FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id '
            . 'JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=? AND p.code=? LIMIT 1 FOR SHARE',
            [$owner, $permission]
        ) !== null;
    }

    public function lockOwner(int $owner): bool
    {
        return $this->one("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE", [$owner]) !== null;
    }

    public function now(): string
    {
        return (string) $this->one('SELECT UTC_TIMESTAMP(6) AS now', [])['now'];
    }

    public function grants(int $owner, string $feature, string $scope): array
    {
        return $this->all(
            'SELECT e.* FROM entitlements e LEFT JOIN subscriptions s ON s.id=e.subscription_id '
            . 'AND s.user_id=e.user_id LEFT JOIN plan_versions v ON v.id=s.plan_version_id '
            . 'WHERE e.user_id=? AND e.feature_code=? AND e.scope_key=? '
            . 'AND e.revoked_at IS NULL AND e.starts_at<=UTC_TIMESTAMP(6) AND e.ends_at>UTC_TIMESTAMP(6) '
            . 'AND e.approved_at<=UTC_TIMESTAMP(6) AND (e.subscription_id IS NULL OR '
            . "(s.status='active' AND s.starts_at<=UTC_TIMESTAMP(6) AND s.ends_at>UTC_TIMESTAMP(6) "
            . 'AND v.approved_at<=UTC_TIMESTAMP(6) AND v.effective_at<=UTC_TIMESTAMP(6))) FOR UPDATE',
            [$owner, $feature, $scope]
        );
    }

    public function usageEvent(int $owner, string $feature, string $key): ?array
    {
        return $this->one(
            'SELECT * FROM usage_events WHERE user_id=? AND feature_code=? AND request_id=?',
            [$owner, $feature, $key]
        );
    }

    public function counter(int $owner, array $grant, string $period): array
    {
        $this->execute(
            'INSERT INTO usage_counters (user_id,entitlement_id,feature_code,scope_key,period_start) '
            . 'VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
            [$owner, $grant['id'], $grant['feature_code'], $grant['scope_key'], $period]
        );
        return $this->one(
            'SELECT * FROM usage_counters WHERE user_id=? AND feature_code=? '
            . 'AND scope_key=? AND period_start=? FOR UPDATE',
            [$owner, $grant['feature_code'], $grant['scope_key'], $period]
        );
    }

    public function consume(int $counter, int $cost): void
    {
        $sql = 'UPDATE usage_counters SET used_count=used_count+?,version=version+1 WHERE id=?';
        $this->execute($sql, [$cost, $counter]);
    }

    public function recordUsage(
        int $owner,
        string $feature,
        string $key,
        int $counter,
        string $hash,
        int $cost,
        string $result,
        int $entitlement
    ): void {
        $this->execute(
            'INSERT INTO usage_events (user_id,feature_code,request_id,counter_id,fingerprint,delta,status,'
            . "result_json,entitlement_id,occurred_at) VALUES (?,?,?,?,?,?,'consumed',?,?,UTC_TIMESTAMP(6))",
            [$owner, $feature, $key, $counter, $hash, $cost, $result, $entitlement]
        );
    }

    public function activePlanContexts(int $owner): array
    {
        return $this->all('SELECT s.id AS subscription_id,s.plan_version_id,s.starts_at,s.ends_at,'
            . 'v.features,v.approved_at,p.code,u.email_verified_at FROM subscriptions s '
            . 'JOIN plan_versions v ON v.id=s.plan_version_id JOIN plans p ON p.id=v.plan_id '
            . 'JOIN users u ON u.id=s.user_id WHERE s.user_id=? AND s.status=\'active\' '
            . 'AND s.starts_at<=UTC_TIMESTAMP(6) AND s.ends_at>UTC_TIMESTAMP(6) '
            . 'AND v.effective_at<=s.starts_at AND v.approved_at<=s.starts_at AND EXISTS '
            . '(SELECT 1 FROM subscription_events e JOIN payments pay ON pay.id=e.payment_id '
            . 'JOIN invoices i ON i.id=pay.invoice_id AND i.paid_payment_id=pay.id '
            . 'WHERE e.subscription_id=s.id AND i.subscription_id=s.id AND i.user_id=s.user_id '
            . 'AND pay.user_id=s.user_id AND i.plan_version_id=s.plan_version_id '
            . 'AND pay.status=\'succeeded\' AND i.status=\'paid\' AND pay.environment=? '
            . 'AND e.event_type=\'payment.confirmed\' AND e.evidence_reference<>\'\' '
            . 'AND e.occurred_at<=UTC_TIMESTAMP(6)) FOR UPDATE', [$owner, $this->paymentEnvironment]);
    }

    public function paidTrialCount(int $owner): int
    {
        return (int) $this->one('SELECT COUNT(*) AS total FROM subscriptions s '
            . 'JOIN plan_versions v ON v.id=s.plan_version_id JOIN plans p ON p.id=v.plan_id '
            . 'WHERE s.user_id=? AND p.code=\'trial\' AND EXISTS (SELECT 1 FROM subscription_events e '
            . 'JOIN payments pay ON pay.id=e.payment_id JOIN invoices i ON i.paid_payment_id=pay.id '
            . 'AND i.id=pay.invoice_id WHERE e.subscription_id=s.id AND i.subscription_id=s.id '
            . 'AND pay.user_id=s.user_id AND i.user_id=s.user_id AND pay.environment=? '
            . 'AND pay.status=\'succeeded\' AND i.status=\'paid\' AND e.event_type=\'payment.confirmed\' '
            . 'AND e.evidence_reference<>\'\' AND e.occurred_at<=UTC_TIMESTAMP(6))', [$owner,
                $this->paymentEnvironment])['total'];
    }

    public function policyRevoked(int $owner, array $context, array $features): bool
    {
        $placeholders = implode(',', array_fill(0, count($features), '?'));
        return $this->one(
            'SELECT id FROM entitlements WHERE user_id=? AND subscription_id=? '
            . 'AND plan_version_id=? AND approval_kind=\'documented_product\' AND scope_key=\'global\' '
            . 'AND revoked_at IS NOT NULL AND feature_code IN (' . $placeholders . ') LIMIT 1 FOR UPDATE',
            [$owner, $context['subscription_id'], $context['plan_version_id'], ...$features]
        ) !== null;
    }

    public function policyGrant(
        int $owner,
        array $context,
        string $feature,
        ?int $limit,
        int $period,
        string $anchor
    ): array {
        $existing = $this->one(
            'SELECT * FROM entitlements WHERE user_id=? AND subscription_id=? '
            . 'AND plan_version_id=? AND feature_code=? AND scope_key=\'global\' FOR UPDATE',
            [$owner, $context['subscription_id'], $context['plan_version_id'], $feature]
        );
        if ($existing !== null) {
            if (
                $existing['revoked_at'] !== null || $existing['approval_kind'] !== 'documented_product'
                || ($existing['quota_limit'] === null ? null : (int) $existing['quota_limit']) !== $limit
                || (int) $existing['period_seconds'] !== $period
                || $existing['period_anchor_at'] !== $anchor
            ) {
                throw new \App\Domain\Access\AccessException('policy_grant_conflict', 403);
            }
            return $existing;
        }
        $reference = \App\Domain\Access\PlanPolicy::APPROVAL;
        $this->execute(
            'INSERT INTO entitlements (user_id,subscription_id,plan_version_id,feature_code,scope_key,'
            . 'quota_limit,period_seconds,period_anchor_at,starts_at,ends_at,approved_by,approval_kind,'
            . 'approval_reference,approved_at) VALUES (?,?,?,?,\'global\',?,?,?,?,?,NULL,\'documented_product\',?,?)',
            [$owner, $context['subscription_id'], $context['plan_version_id'], $feature, $limit, $period,
            $anchor,
            $context['starts_at'],
            $context['ends_at'],
            $reference,
            $context['approved_at']]
        );
        $id = (int) $this->db->lastInsertId();
        $this->audit($owner, 'entitlement.policy_materialized', 'entitlement', (string) $id, 'policy:' . $id);
        return $this->one('SELECT * FROM entitlements WHERE id=? AND user_id=?', [$id, $owner]);
    }

    public function business(callable $work): mixed
    {
        return $work($this->db);
    }

    public function audit(?int $actor, string $operation, string $type, string $id, string $correlation): void
    {
        $this->execute('INSERT INTO audit_logs '
            . '(actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) '
            . 'VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))', [$actor, $operation, $type, $id, $correlation]);
    }

    public function plans(): array
    {
        return $this->all('SELECT p.code,p.name,v.id AS version_id,v.version,v.features,v.effective_at,'
            . 'r.currency,CAST(r.amount_minor AS CHAR) AS amount_minor,r.interval_code '
            . 'FROM plans p JOIN plan_versions v ON v.plan_id=p.id JOIN plan_prices r ON r.plan_version_id=v.id '
            . 'WHERE p.enabled=1 AND v.effective_at<=UTC_TIMESTAMP(6) AND v.approved_at<=UTC_TIMESTAMP(6) '
            . 'AND NOT EXISTS (SELECT 1 FROM plan_versions newer WHERE newer.plan_id=p.id '
            . 'AND newer.effective_at<=UTC_TIMESTAMP(6) AND newer.version>v.version) ORDER BY p.id,r.id LIMIT 100', []);
    }

    public function subscriptions(int $owner): array
    {
        return $this->all('SELECT s.id,s.status,s.starts_at,s.ends_at,s.plan_version_id,p.code AS plan_code '
            . 'FROM subscriptions s JOIN plan_versions v ON v.id=s.plan_version_id JOIN plans p ON p.id=v.plan_id '
            . 'WHERE s.user_id=? ORDER BY s.id DESC LIMIT 100', [$owner]);
    }

    public function notifications(int $owner, int $before, int $limit): array
    {
        $query = $this->db->prepare('SELECT id,event_type,payload,created_at,read_at FROM notifications '
            . 'WHERE user_id=? AND id<? ORDER BY id DESC LIMIT ?');
        $query->bindValue(1, $owner, PDO::PARAM_INT);
        $query->bindValue(2, $before, PDO::PARAM_INT);
        $query->bindValue(3, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $owner, int $id): ?array
    {
        $row = $this->one('SELECT id,read_at FROM notifications WHERE user_id=? AND id=? FOR UPDATE', [$owner, $id]);
        if ($row !== null && $row['read_at'] === null) {
            $this->execute('UPDATE notifications SET read_at=UTC_TIMESTAMP(6) WHERE id=? AND user_id=?', [$id, $owner]);
            $row = $this->one('SELECT id,read_at FROM notifications WHERE id=? AND user_id=?', [$id, $owner]);
            $row['changed'] = true;
        }
        return $row;
    }

    public function publishNotification(int $owner, ?int $actor, string $key, string $type, string $payload): int
    {
        $existing = $this->one(
            'SELECT id,event_type,payload FROM notifications WHERE user_id=? AND event_key=?',
            [$owner, $key]
        );
        if ($existing !== null) {
            $samePayload = json_decode($existing['payload'], true) == json_decode($payload, true);
            if ($existing['event_type'] !== $type || !$samePayload) {
                throw new \App\Domain\Access\AccessException('idempotency_conflict', 409);
            }
            return (int) $existing['id'];
        }
        $this->execute('INSERT INTO notifications (user_id,event_key,event_type,payload,created_at) '
            . 'VALUES (?,?,?,?,UTC_TIMESTAMP(6))', [$owner, $key, $type, $payload]);
        $id = (int) $this->db->lastInsertId();
        // In-app delivery is the committed owner-visible row, not an email, push or read receipt.
        $this->execute("INSERT INTO notification_outbox (notification_id,channel,status,delivered_at) "
            . "VALUES (?,'in_app','delivered',UTC_TIMESTAMP(6))", [$id]);
        $this->audit($actor, 'notification.created', 'notification', (string) $id, $key);
        return $id;
    }

    public function publishPlan(int $actor, string $code, string $features, array $price, string $reference): int
    {
        $plan = $this->one('SELECT id FROM plans WHERE code=? FOR UPDATE', [$code]);
        if ($plan === null) {
            throw new \InvalidArgumentException('Unknown approved plan name.');
        }
        $versionQuery = 'SELECT COALESCE(MAX(version),0)+1 AS next FROM plan_versions WHERE plan_id=?';
        $version = $this->one($versionQuery, [$plan['id']]);
        $this->execute(
            'INSERT INTO plan_versions (plan_id,version,features,approval_reference,approved_by,'
            . 'approved_at,effective_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
            [$plan['id'], $version['next'], $features, $reference, $actor]
        );
        $id = (int) $this->db->lastInsertId();
        $this->execute(
            'INSERT INTO plan_prices (plan_version_id,currency,amount_minor,interval_code) VALUES (?,?,?,?)',
            [$id, 'NGN', $price['amount_minor'], $price['interval_code']]
        );
        $this->execute('UPDATE plans SET enabled=1 WHERE id=?', [$plan['id']]);
        $this->audit($actor, 'plan.published', 'plan_version', (string) $id, 'plan:' . $id);
        return $id;
    }

    public function insertGrant(int $owner, int $actor, array $grant, string $reference): int
    {
        $this->execute('INSERT INTO entitlements (user_id,subscription_id,feature_code,scope_key,quota_limit,'
            . 'period_seconds,period_anchor_at,starts_at,ends_at,approved_by,approval_reference,approved_at) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$owner, $grant['subscription_id'],
                $grant['feature_code'], $grant['scope_key'], $grant['quota_limit'], $grant['period_seconds'],
                $grant['period_anchor_at'], $grant['starts_at'], $grant['ends_at'], $actor, $reference]);
        return (int) $this->db->lastInsertId();
    }

    public function overlappingGrant(int $owner, array $grant): bool
    {
        return $this->one('SELECT id FROM entitlements WHERE user_id=? AND feature_code=? AND scope_key=? '
            . 'AND revoked_at IS NULL AND starts_at<? AND ends_at>? LIMIT 1 FOR UPDATE', [$owner,
                $grant['feature_code'], $grant['scope_key'], $grant['ends_at'], $grant['starts_at']]) !== null;
    }

    public function revoke(int $owner, int $id): bool
    {
        return $this->execute('UPDATE entitlements SET revoked_at=UTC_TIMESTAMP(6) '
            . 'WHERE user_id=? AND id=? AND revoked_at IS NULL', [$owner, $id]) === 1;
    }

    private function execute(string $sql, array $values): int
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
