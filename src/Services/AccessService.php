<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Domain\Access\PlanPolicy;
use App\Domain\Identity\SessionToken;
use App\Repositories\AccessRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AccessService
{
    public function __construct(private readonly AccessRepository $repository)
    {
    }

    public function plans(): array
    {
        return array_map(static function (array $row): array {
            $row['features'] = json_decode($row['features'], true, 32, JSON_THROW_ON_ERROR);
            $row['effective_at'] = self::utc($row['effective_at']);
            $row['purchase_available'] = false;
            return $row;
        }, $this->repository->plans());
    }

    public function subscriptions(SessionCredentials $credentials): array
    {
        return $this->repository->transaction(function () use ($credentials): array {
            $owner = $this->authenticate($credentials, 'access.read', false);
            return array_map(static function (array $row): array {
                $row['starts_at'] = self::utc($row['starts_at']);
                $row['ends_at'] = self::utc($row['ends_at']);
                return $row;
            }, $this->repository->subscriptions($owner));
        });
    }

    /** Internal administrative entry point. No purchase, subscription activation or implicit grants. */
    public function approvePlan(SessionCredentials $admin, string $code, array $features, string $reference): int
    {
        $prices = ['trial' => [200000, 'day'], 'standard' => [3000000, 'two_weeks'],
            'pro' => [5000000, 'month'], 'max' => [8000000, 'month']];
        if (
            !isset($prices[$code]) || $features === [] || !array_is_list($features)
            || count($features) > 100 || $reference === '' || strlen($reference) > 255
        ) {
            throw new InvalidArgumentException('Approved features and evidence are required for a known plan.');
        }
        $seen = [];
        foreach ($features as $rule) {
            if (
                !is_array($rule) || count($rule) !== 4
                || !isset($rule['feature_code'], $rule['scope_key'], $rule['period_seconds'])
                || !array_key_exists('quota_limit', $rule)
            ) {
                throw new InvalidArgumentException('Plan rules require feature, scope, quota and period.');
            }
            self::key($rule['feature_code'], 64);
            self::key($rule['scope_key'], 128);
            if (
                ($rule['quota_limit'] !== null && (!is_int($rule['quota_limit']) || $rule['quota_limit'] < 0
                || $rule['quota_limit'] > 1000000000)) || !is_int($rule['period_seconds'])
                || $rule['period_seconds'] < 1 || $rule['period_seconds'] > 2678400
            ) {
                throw new InvalidArgumentException('Invalid plan quota or reset period.');
            }
            $key = $rule['feature_code'] . '/' . $rule['scope_key'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate plan feature scope.');
            }
            $seen[$key] = true;
        }
        return $this->repository->transaction(function () use ($admin, $code, $features, $reference, $prices): int {
            $actor = $this->authenticate($admin, 'access.admin', true);
            return $this->repository->publishPlan(
                $actor,
                $code,
                json_encode($features, JSON_THROW_ON_ERROR),
                ['amount_minor' => $prices[$code][0], 'interval_code' => $prices[$code][1]],
                $reference
            );
        });
    }

    public function requireEntitlement(SessionCredentials $credentials, string $feature, string $scope): array
    {
        self::key($feature, 64);
        self::key($scope, 128);
        return $this->repository->transaction(function () use ($credentials, $feature, $scope): array {
            $owner = $this->authenticate($credentials, 'access.read', false);
            $grants = $this->repository->grants($owner, $feature, $scope);
            if (count($grants) !== 1) {
                throw new AccessException('entitlement_denied', 403);
            }
            return $grants[0];
        });
    }

    public function requirePlanRequest(
        SessionCredentials $credentials,
        string $feature,
        array $models = [],
        array $markets = [],
        array $options = []
    ): array {
        return $this->repository->transaction(function () use ($credentials, $feature, $models, $markets, $options) {
            $owner = $this->authenticate($credentials, 'access.read', false);
            [$context, $policy, $now] = $this->planContext($owner);
            $decision = PlanPolicy::authorize($policy, $feature, $models, $markets, $options, $now);
            $this->checkPolicyRevocation($owner, $context, $policy, $feature);
            return $decision;
        });
    }

    /** Trusted feature handler applies returned constraints; no client can supply its owner or plan. */
    public function performPlan(
        SessionCredentials $credentials,
        string $feature,
        array $models,
        array $markets,
        array $options,
        string $requestId,
        string $operationHash,
        callable $action
    ): array {
        self::key($requestId, 128);
        if (
            !in_array($feature, ['prediction.analyse', 'simulation.run', 'csv.export', 'comparison.export'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $operationHash) !== 1
        ) {
            throw new InvalidArgumentException('Unsupported metered plan operation.');
        }
        return $this->repository->transaction(function () use (
            $credentials,
            $feature,
            $models,
            $markets,
            $options,
            $requestId,
            $operationHash,
            $action
        ): array {
            $owner = $this->authenticate($credentials, 'access.consume', true);
            [$context, $policy, $now] = $this->planContext($owner);
            $decision = PlanPolicy::authorize($policy, $feature, $models, $markets, $options, $now);
            $this->checkPolicyRevocation($owner, $context, $policy, $feature);
            $daily = in_array($feature, ['csv.export', 'comparison.export'], true);
            $quotaFeature = $daily ? 'csv.export' : $feature;
            $limit = $daily ? $policy['csv_exports_per_day'] : null;
            $start = new DateTimeImmutable($context['starts_at'], new DateTimeZone('UTC'));
            $anchor = $daily ? PlanPolicy::dailyAnchor($start) : $start;
            $period = $daily ? 86400 : $policy['validity_seconds'];
            $grant = $this->repository->policyGrant(
                $owner,
                $context,
                $quotaFeature,
                $limit,
                $period,
                $anchor->format('Y-m-d H:i:s.u')
            );
            $claims = [$context['subscription_id'], $context['plan_version_id'], $feature,
                $models, $markets, $options, $operationHash];
            $fingerprint = hash('sha256', json_encode($claims, JSON_THROW_ON_ERROR));
            return $this->consumeGrant(
                $owner,
                $grant,
                $quotaFeature,
                'global',
                $requestId,
                1,
                $fingerprint,
                static fn ($db) => $action($db, $decision),
                true
            );
        });
    }

    private function checkPolicyRevocation(int $owner, array $context, array $policy, string $feature): void
    {
        $features = [$feature];
        if ($feature === 'comparison.read') {
            $features[] = 'comparison.' . $policy['comparison_tier'];
        }
        if ($feature === 'comparison.export') {
            $features[] = 'csv.export';
        }
        if ($this->repository->policyRevoked($owner, $context, $features)) {
            throw new AccessException('entitlement_revoked', 403);
        }
    }

    private function planContext(int $owner): array
    {
        $contexts = $this->repository->activePlanContexts($owner);
        if (count($contexts) !== 1) {
            throw new AccessException('paid_subscription_required', 403);
        }
        $context = $contexts[0];
        $policy = PlanPolicy::verify(json_decode($context['features'], true, 32, JSON_THROW_ON_ERROR));
        $start = new DateTimeImmutable($context['starts_at'], new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($context['ends_at'], new DateTimeZone('UTC'));
        $maximumEnd = $start->modify('+' . $policy['validity_seconds'] . ' seconds');
        if ($context['code'] !== $policy['code'] || $end > $maximumEnd) {
            throw new AccessException('subscription_policy_mismatch', 403);
        }
        if (
            $policy['trial_once_verified'] && ($context['email_verified_at'] === null
            || new DateTimeImmutable($context['email_verified_at'], new DateTimeZone('UTC')) > $start
            || $this->repository->paidTrialCount($owner) !== 1)
        ) {
            throw new AccessException('trial_ineligible', 403);
        }
        $now = new DateTimeImmutable($this->repository->now(), new DateTimeZone('UTC'));
        return [$context, $policy, $now];
    }

    /** Callback must perform only transactional DB/outbox work. No network calls or irreversible side effects. */
    public function consume(
        SessionCredentials $credentials,
        string $feature,
        string $scope,
        string $requestId,
        int $cost,
        string $operationHash,
        callable $action
    ): array {
        self::key($feature, 64);
        self::key($scope, 128);
        self::key($requestId, 128);
        if ($cost < 1 || $cost > 1000000 || preg_match('/^[a-f0-9]{64}$/D', $operationHash) !== 1) {
            throw new InvalidArgumentException('Invalid consumption cost or canonical operation hash.');
        }
        $fingerprint = hash('sha256', json_encode([$scope, $cost, $operationHash], JSON_THROW_ON_ERROR));
        return $this->repository->transaction(function () use (
            $credentials,
            $feature,
            $scope,
            $requestId,
            $cost,
            $fingerprint,
            $action
        ): array {
            $owner = $this->authenticate($credentials, 'access.consume', true);
            $grants = $this->repository->grants($owner, $feature, $scope);
            if (count($grants) !== 1) {
                throw new AccessException('entitlement_denied', 403);
            }
            return $this->consumeGrant($owner, $grants[0], $feature, $scope, $requestId, $cost, $fingerprint, $action);
        });
    }

    public function notifications(SessionCredentials $credentials, int $before = PHP_INT_MAX, int $limit = 20): array
    {
        if ($before < 1 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid notification cursor or limit.');
        }
        return $this->repository->transaction(function () use ($credentials, $before, $limit): array {
            $owner = $this->authenticate($credentials, 'notifications.read', false);
            return array_map(static function (array $row): array {
                $row['payload'] = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
                $row['created_at'] = self::utc($row['created_at']);
                $row['read_at'] = $row['read_at'] === null ? null : self::utc($row['read_at']);
                return $row;
            }, $this->repository->notifications($owner, $before, $limit));
        });
    }

    public function markRead(SessionCredentials $credentials, int $id, string $correlation): array
    {
        self::key($correlation, 128);
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid notification ID.');
        }
        return $this->repository->transaction(function () use ($credentials, $id, $correlation): array {
            $owner = $this->authenticate($credentials, 'notifications.update', true);
            $row = $this->repository->markRead($owner, $id);
            if ($row === null) {
                throw new AccessException('not_found', 404);
            }
            if ($row['changed'] ?? false) {
                $this->repository->audit($owner, 'notification.read', 'notification', (string) $id, $correlation);
            }
            unset($row['changed']);
            $row['read_at'] = self::utc($row['read_at']);
            return $row;
        });
    }

    public function notify(SessionCredentials $admin, int $owner, string $key, string $type, array $payload): int
    {
        self::key($key, 128);
        self::key($type, 64);
        if (
            array_keys($payload) !== ['title', 'body'] || !is_string($payload['title']) || !is_string($payload['body'])
            || strlen($payload['title']) > 200 || strlen($payload['body']) > 4000
        ) {
            throw new InvalidArgumentException('Notification payload must contain bounded plain-text title and body.');
        }
        return $this->repository->transaction(function () use ($admin, $owner, $key, $type, $payload): int {
            $actor = $this->authenticate($admin, 'notifications.publish', true);
            if (!$this->repository->lockOwner($owner)) {
                throw new AccessException('not_found', 404);
            }
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $id = $this->repository->publishNotification($owner, $actor, $key, $type, $json);
            return $id;
        });
    }

    public function grant(SessionCredentials $admin, int $owner, array $grant, string $reference): int
    {
        $keys = ['subscription_id', 'feature_code', 'scope_key', 'quota_limit', 'period_seconds',
            'period_anchor_at', 'starts_at', 'ends_at'];
        if (
            array_diff($keys, array_keys($grant)) || array_diff(array_keys($grant), $keys)
            || $reference === '' || strlen($reference) > 255
        ) {
            throw new InvalidArgumentException('A complete approved grant policy and evidence reference are required.');
        }
        self::key($grant['feature_code'], 64);
        self::key($grant['scope_key'], 128);
        if (
            ($grant['subscription_id'] !== null
                && (!is_int($grant['subscription_id']) || $grant['subscription_id'] < 1))
            || ($grant['quota_limit'] !== null && (!is_int($grant['quota_limit']) || $grant['quota_limit'] < 0
                || $grant['quota_limit'] > 1000000000)) || !is_int($grant['period_seconds'])
            || $grant['period_seconds'] < 1 || $grant['period_seconds'] > 2678400
        ) {
            throw new InvalidArgumentException('Invalid grant limits.');
        }
        foreach (['period_anchor_at', 'starts_at', 'ends_at'] as $field) {
            $date = is_string($grant[$field]) ? DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                $grant[$field],
                new DateTimeZone('UTC')
            ) : false;
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $grant[$field]) {
                throw new InvalidArgumentException('Grant clocks must be valid UTC timestamps.');
            }
            $grant[$field] = $date->format('Y-m-d H:i:s');
        }
        return $this->repository->transaction(function () use ($admin, $owner, $grant, $reference): int {
            $actor = $this->authenticate($admin, 'access.admin', true);
            if (!$this->repository->lockOwner($owner)) {
                throw new AccessException('not_found', 404);
            }
            if (
                $grant['starts_at'] < $this->repository->now() || $grant['ends_at'] <= $grant['starts_at']
                || $grant['period_anchor_at'] > $grant['starts_at']
            ) {
                throw new InvalidArgumentException('Grant cannot be backdated or use an inverted clock window.');
            }
            if ($this->repository->overlappingGrant($owner, $grant)) {
                throw new AccessException('overlapping_entitlement', 409);
            }
            $id = $this->repository->insertGrant($owner, $actor, $grant, $reference);
            $this->repository->audit($actor, 'entitlement.granted', 'entitlement', (string) $id, 'grant:' . $id);
            return $id;
        });
    }

    public function revoke(SessionCredentials $admin, int $owner, int $id): bool
    {
        return $this->repository->transaction(function () use ($admin, $owner, $id): bool {
            $actor = $this->authenticate($admin, 'access.admin', true);
            if (!$this->repository->lockOwner($owner)) {
                throw new AccessException('not_found', 404);
            }
            $changed = $this->repository->revoke($owner, $id);
            if ($changed) {
                $this->repository->audit($actor, 'entitlement.revoked', 'entitlement', (string) $id, 'revoke:' . $id);
            }
            return $changed;
        });
    }

    private function consumeGrant(
        int $owner,
        array $grant,
        string $feature,
        string $scope,
        string $requestId,
        int $cost,
        string $fingerprint,
        callable $action,
        bool $carryPolicy = false
    ): array {
        $existing = $this->repository->usageEvent($owner, $feature, $requestId);
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                throw new AccessException('idempotency_conflict', 409);
            }
            $result = json_decode($existing['result_json'], true, 32, JSON_THROW_ON_ERROR);
            return ['result' => $result, 'replayed' => true];
        }
        $now = new DateTimeImmutable($this->repository->now(), new DateTimeZone('UTC'));
        $anchor = new DateTimeImmutable($grant['period_anchor_at'], new DateTimeZone('UTC'));
        if ($anchor > $now) {
            throw new AccessException('entitlement_not_effective', 403);
        }
        $seconds = (int) $grant['period_seconds'];
        $offset = intdiv($now->getTimestamp() - $anchor->getTimestamp(), $seconds) * $seconds;
        $period = $anchor->modify('+' . $offset . ' seconds')->format('Y-m-d H:i:s.u');
        $counter = $this->repository->counter($owner, $grant, $period);
        if (!$carryPolicy && (int) $counter['entitlement_id'] !== (int) $grant['id']) {
            throw new AccessException('quota_policy_changed', 409);
        }
        $used = (int) $counter['used_count'];
        if (
            $used > PHP_INT_MAX - $cost
            || ($grant['quota_limit'] !== null && $used + $cost > (int) $grant['quota_limit'])
        ) {
            throw new AccessException('quota_exhausted', 429);
        }
        $result = $this->repository->business($action);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($json) > 131072) {
            throw new InvalidArgumentException('Transactional operation result is too large.');
        }
        $this->repository->consume((int) $counter['id'], $cost);
        $this->repository->recordUsage(
            $owner,
            $feature,
            $requestId,
            (int) $counter['id'],
            $fingerprint,
            $cost,
            $json,
            (int) $grant['id']
        );
        $this->repository->audit($owner, 'quota.consumed', 'usage_counter', (string) $counter['id'], $requestId);
        return ['result' => $result, 'replayed' => false];
    }

    private function authenticate(SessionCredentials $credentials, string $permission, bool $write): int
    {
        return SessionAuthorization::owner($this->repository, $credentials, $permission, $write);
    }

    private static function key(mixed $value, int $maximum): void
    {
        if (!is_string($value) || strlen($value) > $maximum || preg_match('/^[a-zA-Z0-9_.:|=-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid canonical access identifier.');
        }
    }

    private static function utc(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
