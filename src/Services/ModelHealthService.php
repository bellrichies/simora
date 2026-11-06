<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clock;
use App\Contracts\ModelOperationsRepository;
use App\Contracts\ModelOperationsStore;
use App\Domain\ModelHealth\ApprovalSigner;
use App\Domain\ModelHealth\DeploymentAssignment;
use App\Domain\ModelHealth\DeploymentPolicy;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\HealthObservation;
use App\Domain\ModelHealth\ModelRelease;
use App\Domain\ModelHealth\OperatorContext;
use App\Domain\ModelHealth\ServingContext;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** No route wiring until canonical DB, authenticated transport and release evidence exist. */
final class ModelHealthService
{
    public function __construct(
        private readonly ModelOperationsRepository $repository,
        private readonly Clock $clock,
        private readonly DeploymentPolicy $policy,
        private readonly ApprovalSigner $signer
    ) {
    }

    public function inspect(OperatorContext $actor, string $versionId, DeploymentScope $scope): array
    {
        $now = $this->clock->now();
        $actor->authorize('models.health.read', $now);
        return $this->repository->transaction(function (ModelOperationsStore $store) use ($versionId, $scope, $now) {
            $version = $this->version($store, $versionId);
            if (!$version->scope->contains($scope)) {
                throw new RuntimeException('Health request is outside model scope.');
            }
            $health = $store->health($versionId, $scope);
            $reasons = $this->policy->health->failures($health, $versionId, $version->artifactSha256, $scope, $now);
            if (!$store->sourceAuthorized($versionId, $scope, $now)) {
                $reasons[] = 'source_authorization';
            }
            return ['version_id' => $versionId, 'scope_key' => $scope->key(),
                'status' => $version->status, 'reasons' => $reasons,
                'observed_at' => $health === null ? null : $this->iso($health->observedAt),
                'manifest_sha256' => $health?->manifestSha256,
                'release_eligible' => $version->eligible($now) && $reasons === [],
                'auto_trade_execution' => false];
        });
    }

    public function approve(
        OperatorContext $actor,
        string $commandKey,
        string $versionId,
        DeploymentScope $scope,
        string $channel,
        DateTimeImmutable $expiresAt
    ): array {
        $now = $this->clock->now();
        $actor->authorize('models.approve', $now);
        if (
            !in_array($channel, ['shadow', 'limited'], true) || $expiresAt <= $now
            || $expiresAt > $now->modify('+' . $this->policy->maximumApprovalSeconds . ' seconds')
        ) {
            throw new InvalidArgumentException('Invalid approval channel or expiry.');
        }
        return $this->command(
            $commandKey,
            'approve',
            [$actor->userId, $versionId, $scope, $channel, $expiresAt],
            function (ModelOperationsStore $store) use (
                $actor,
                $versionId,
                $scope,
                $channel,
                $expiresAt,
                $now,
                $commandKey
            ) {
                $version = $this->requireHealthy($store, $versionId, $scope, $now);
                if ($version->createdBy === $actor->userId || !$store->approverAuthorized($actor->userId, $now)) {
                    throw new RuntimeException('An independent model approver is required.');
                }
                $signed = $this->signer->sign([
                    'schema' => 'simora.model-approval.v1', 'approval_id' => $commandKey,
                    'version_id' => $versionId, 'artifact_sha256' => $version->artifactSha256,
                    'evidence_sha256' => $version->evidenceSha256, 'scope_key' => $scope->key(),
                    'channel' => $channel, 'policy_sha256' => $this->policy->digest(),
                    'approved_by' => $actor->userId, 'issued_at' => $this->iso($now),
                    'expires_at' => $this->iso($expiresAt),
                ]);
                $signed['revoked'] = false;
                $store->saveApproval($commandKey, $signed);
                return ['approval_id' => $commandKey, 'channel' => $channel, 'version_id' => $versionId];
            },
            $actor->userId,
            $now
        );
    }

    public function deploy(
        OperatorContext $actor,
        string $commandKey,
        string $approvalId,
        string $versionId,
        DeploymentScope $scope,
        string $channel,
        int $basisPoints,
        DateTimeImmutable $expiresAt
    ): array {
        $now = $this->clock->now();
        $actor->authorize('models.deploy', $now);
        return $this->command($commandKey, 'deploy', [$actor->userId, $approvalId, $versionId, $scope,
            $channel, $basisPoints, $expiresAt], function (ModelOperationsStore $store) use (
                $commandKey,
                $approvalId,
                $versionId,
                $scope,
                $channel,
                $basisPoints,
                $expiresAt,
                $now
            ) {
                $version = $this->requireHealthy($store, $versionId, $scope, $now);
                $approval = $this->requireApproval($store, $approvalId, $version, $scope, $channel, $now);
                $this->checkRollout($channel, $basisPoints, $expiresAt, $approval, $now);
                if ($channel === 'limited') {
                    $this->requireShadow($store, $version, $scope, $now);
                }
                $this->assertNoOverlap($store, $scope, $channel, $now);
                if ($channel === 'limited') {
                    foreach ($store->assignments() as $prior) {
                        if (
                            $prior->channel === 'shadow' && $prior->versionId === $versionId
                            && $prior->scope->key() === $scope->key() && $prior->active($now)
                        ) {
                            $store->saveAssignment($prior->end($now));
                        }
                    }
                }
                $assignment = new DeploymentAssignment(
                    $commandKey,
                    $versionId,
                    $scope,
                    $channel,
                    $basisPoints,
                    $now,
                    $expiresAt,
                    $approval
                );
                $store->saveAssignment($assignment);
                return ['assignment_id' => $commandKey, 'channel' => $channel, 'version_id' => $versionId,
                    'public_eligible' => $channel === 'limited'];
            }, $actor->userId, $now);
    }

    /** Trusted collector only. Persist the alarm and suspension atomically, then return. */
    public function observe(string $commandKey, HealthObservation $observation): array
    {
        $now = $this->clock->now();
        return $this->command(
            $commandKey,
            'health',
            [$observation],
            function (ModelOperationsStore $store) use ($observation, $now) {
                $version = $this->version($store, $observation->versionId);
                if (!$version->scope->contains($observation->scope)) {
                    throw new RuntimeException('Health observation is outside model scope.');
                }
                $reasons = $this->policy->health->failures(
                    $observation,
                    $version->id,
                    $version->artifactSha256,
                    $observation->scope,
                    $now
                );
                if (!$store->sourceAuthorized($version->id, $observation->scope, $now)) {
                    $reasons[] = 'source_authorization';
                }
                $store->saveHealth($observation, $reasons);
                if ($reasons !== []) {
                    $this->suspendVersion($store, $version, $now);
                }
                return ['version_id' => $version->id, 'reasons' => $reasons,
                    'scope_key' => $observation->scope->key(),
                    'manifest_sha256' => $observation->manifestSha256,
                    'severity' => $reasons === [] ? 'info' : 'critical',
                    'status' => $version->status === 'retired' ? 'retired'
                        : ($reasons !== [] || $version->status === 'suspended' ? 'suspended' : 'healthy')];
            },
            null,
            $now
        );
    }

    public function suspend(OperatorContext $actor, string $commandKey, string $versionId, string $reason): array
    {
        $now = $this->clock->now();
        $actor->authorize('models.suspend', $now);
        if (trim($reason) === '' || strlen($reason) > 500) {
            throw new InvalidArgumentException('A bounded suspension reason is required.');
        }
        return $this->command(
            $commandKey,
            'suspend',
            [$actor->userId, $versionId, $reason],
            function (ModelOperationsStore $store) use ($versionId, $now, $reason) {
                $this->suspendVersion($store, $this->version($store, $versionId), $now);
                return ['version_id' => $versionId, 'status' => $store->version($versionId)?->status,
                    'reason' => $reason];
            },
            $actor->userId,
            $now
        );
    }

    public function revokeApproval(OperatorContext $actor, string $commandKey, string $approvalId): array
    {
        $now = $this->clock->now();
        $actor->authorize('models.approve', $now);
        return $this->command(
            $commandKey,
            'revoke_approval',
            [$actor->userId, $approvalId],
            function (ModelOperationsStore $store) use ($approvalId, $now) {
                $approval = $store->approval($approvalId);
                if ($approval === null) {
                    throw new RuntimeException('Approval unavailable.');
                }
                $approval['revoked'] = true;
                $store->saveApproval($approvalId, $approval);
                foreach ($store->assignments() as $assignment) {
                    if (
                        ($assignment->approval['claims']['approval_id'] ?? null) === $approvalId
                        && $assignment->active($now)
                    ) {
                        $store->saveAssignment($assignment->end($now));
                    }
                }
                return ['approval_id' => $approvalId, 'revoked' => true];
            },
            $actor->userId,
            $now
        );
    }

    /** Rollback is an explicit new assignment; never reactivate a suspended or stale target. */
    public function rollback(
        OperatorContext $actor,
        string $commandKey,
        string $currentId,
        string $previousId,
        string $freshApprovalId,
        DateTimeImmutable $expiresAt
    ): array {
        $now = $this->clock->now();
        $actor->authorize('models.rollback', $now);
        return $this->command($commandKey, 'rollback', [$actor->userId, $currentId, $previousId,
            $freshApprovalId, $expiresAt], function (ModelOperationsStore $store) use (
                $commandKey,
                $currentId,
                $previousId,
                $freshApprovalId,
                $expiresAt,
                $now
            ) {
                $assignments = [];
                foreach ($store->assignments() as $item) {
                    $assignments[$item->id] = $item;
                }
                $current = $assignments[$currentId] ?? null;
                $previous = $assignments[$previousId] ?? null;
                if (
                    $current === null || $previous === null || $current->channel !== 'limited'
                    || $previous->channel !== 'limited' || $current->effectiveAt > $now
                    || $previous->active($now) || $previous->effectiveAt >= $current->effectiveAt
                    || $previous->versionId === $current->versionId
                    || $previous->scope->key() !== $current->scope->key()
                ) {
                    throw new RuntimeException('Rollback requires an earlier limited assignment in the same scope.');
                }
                foreach ($assignments as $assignment) {
                    if (
                        $assignment->channel === 'limited' && $assignment->scope->key() === $current->scope->key()
                        && $assignment->effectiveAt > $current->effectiveAt
                    ) {
                        throw new RuntimeException('Rollback source is no longer the latest assignment.');
                    }
                }
                $version = $this->requireHealthy($store, $previous->versionId, $previous->scope, $now);
                $approval = $this->requireApproval(
                    $store,
                    $freshApprovalId,
                    $version,
                    $previous->scope,
                    'limited',
                    $now
                );
                if ($approval['claims']['issued_at'] < $this->iso($current->effectiveAt)) {
                    throw new RuntimeException('Rollback requires a fresh approval.');
                }
                $this->checkRollout('limited', $previous->basisPoints, $expiresAt, $approval, $now);
                if ($current->active($now)) {
                    $store->saveAssignment($current->end($now));
                }
                $this->assertNoOverlap($store, $previous->scope, 'limited', $now);
                $store->saveAssignment(new DeploymentAssignment(
                    $commandKey,
                    $version->id,
                    $previous->scope,
                    'limited',
                    $previous->basisPoints,
                    $now,
                    $expiresAt,
                    $approval,
                    null,
                    $currentId
                ));
                return ['assignment_id' => $commandKey, 'version_id' => $version->id,
                    'rollback_of' => $currentId, 'restored_from' => $previousId];
            }, $actor->userId, $now);
    }

    /** Limited analytics only; shadow is never a public fallback. */
    public function resolve(DeploymentScope $requested, ServingContext $viewer): array
    {
        $now = $this->clock->now();
        if (!$viewer->eligible($now)) {
            return $this->unavailable('access_limited');
        }
        if ($requested->platform === null || $requested->league === null || $requested->market === null) {
            return $this->unavailable('invalid_scope');
        }
        try {
            return $this->repository->transaction(function (ModelOperationsStore $store) use (
                $requested,
                $viewer,
                $now
            ) {
                $matches = array_values(array_filter($store->assignments(), static fn ($a) =>
                    $a->channel === 'limited' && $a->active($now) && $a->scope->contains($requested)));
                if (count($matches) !== 1) {
                    foreach ($matches as $conflicting) {
                        $this->guardSuspend($store, $conflicting, $now);
                    }
                    return $this->unavailable(count($matches) > 1 ? 'deployment_conflict' : 'no_assignment');
                }
                $assignment = $matches[0];
                try {
                    $version = $this->requireHealthy($store, $assignment->versionId, $assignment->scope, $now);
                    if (!$store->sourceAuthorized($version->id, $requested, $now)) {
                        throw new RuntimeException('Narrowed source grant unavailable.');
                    }
                    if ($requested->key() !== $assignment->scope->key()) {
                        $this->requireHealthy($store, $version->id, $requested, $now);
                    }
                } catch (RuntimeException $exception) {
                    $this->guardSuspend($store, $assignment, $now);
                    return $this->unavailable('model_ineligible');
                }
                try {
                    $approvalId = $assignment->approval['claims']['approval_id'] ?? '';
                    $approval = $this->requireApproval(
                        $store,
                        $approvalId,
                        $version,
                        $assignment->scope,
                        'limited',
                        $now
                    );
                    $this->checkRollout('limited', $assignment->basisPoints, $assignment->expiresAt, $approval, $now);
                } catch (RuntimeException $exception) {
                    return $this->unavailable('model_ineligible');
                }
                if (!$assignment->includes($viewer->subjectId)) {
                    return $this->unavailable('outside_limited_cohort');
                }
                return ['status' => 'eligible', 'version_id' => $assignment->versionId,
                    'assignment_id' => $assignment->id, 'artifact_sha256' => $version->artifactSha256,
                    'channel' => 'limited', 'auto_trade_execution' => false];
            });
        } catch (Throwable $exception) {
            return $this->unavailable('model_backend_unavailable');
        }
    }

    private function requireHealthy(
        ModelOperationsStore $store,
        string $versionId,
        DeploymentScope $scope,
        DateTimeImmutable $now
    ): ModelRelease {
        $version = $this->version($store, $versionId);
        if (
            !$version->eligible($now) || !$version->scope->contains($scope)
            || !$store->sourceAuthorized($versionId, $scope, $now)
            || $this->policy->health->failures(
                $store->health($versionId, $scope),
                $versionId,
                $version->artifactSha256,
                $scope,
                $now
            ) !== []
        ) {
            throw new RuntimeException('Model release or current health/source gates failed.');
        }
        return $version;
    }

    private function requireApproval(
        ModelOperationsStore $store,
        string $id,
        ModelRelease $version,
        DeploymentScope $scope,
        string $channel,
        DateTimeImmutable $now
    ): array {
        $approval = $store->approval($id);
        $claims = $approval['claims'] ?? [];
        if (
            $approval === null || ($approval['revoked'] ?? true) !== false || !$this->signer->verify($approval)
            || ($claims['schema'] ?? null) !== 'simora.model-approval.v1'
            || ($claims['approval_id'] ?? null) !== $id || ($claims['version_id'] ?? null) !== $version->id
            || ($claims['artifact_sha256'] ?? null) !== $version->artifactSha256
            || ($claims['evidence_sha256'] ?? null) !== $version->evidenceSha256
            || ($claims['scope_key'] ?? null) !== $scope->key() || ($claims['channel'] ?? null) !== $channel
            || ($claims['policy_sha256'] ?? null) !== $this->policy->digest()
            || !is_int($claims['approved_by'] ?? null) || $claims['approved_by'] < 1
            || $claims['approved_by'] === $version->createdBy
            || !$store->approverAuthorized($claims['approved_by'], $now)
            || !is_string($claims['issued_at'] ?? null) || !is_string($claims['expires_at'] ?? null)
            || $claims['issued_at'] > $this->iso($now) || $claims['expires_at'] <= $this->iso($now)
        ) {
            throw new RuntimeException('Approval invalid, revoked, expired or incompatible.');
        }
        return $approval;
    }

    private function requireShadow(
        ModelOperationsStore $store,
        ModelRelease $version,
        DeploymentScope $scope,
        DateTimeImmutable $now
    ): void {
        foreach ($store->assignments() as $assignment) {
            if (
                $assignment->channel !== 'shadow' || $assignment->versionId !== $version->id
                || $assignment->scope->key() !== $scope->key() || !$assignment->active($now)
                || $assignment->effectiveAt->modify('+' . $this->policy->minimumShadowSeconds . ' seconds') > $now
            ) {
                continue;
            }
            $this->requireApproval(
                $store,
                $assignment->approval['claims']['approval_id'] ?? '',
                $version,
                $scope,
                'shadow',
                $now
            );
            $health = $store->health($version->id, $scope);
            if (
                $health !== null && $health->windowStart <= $assignment->effectiveAt
                && $health->windowEnd >= $now->modify('-' . $this->policy->health->maximumAgeSeconds . ' seconds')
            ) {
                return;
            }
        }
        throw new RuntimeException('Insufficient continuous shadow evidence.');
    }

    private function assertNoOverlap(
        ModelOperationsStore $store,
        DeploymentScope $scope,
        string $channel,
        DateTimeImmutable $now
    ): void {
        foreach ($store->assignments() as $assignment) {
            if (
                $assignment->active($now) && $assignment->channel === $channel
                && $assignment->scope->overlaps($scope)
            ) {
                throw new RuntimeException('Overlapping deployment scope.');
            }
        }
    }

    private function checkRollout(
        string $channel,
        int $basisPoints,
        DateTimeImmutable $expiresAt,
        array $approval,
        DateTimeImmutable $now
    ): void {
        if (
            !in_array($channel, ['shadow', 'limited'], true) || $basisPoints < 1
            || ($channel === 'shadow' && $basisPoints !== 10000)
            || ($channel === 'limited' && $basisPoints > $this->policy->maximumLimitedBasisPoints)
            || $expiresAt <= $now || $this->iso($expiresAt) > $approval['claims']['expires_at']
        ) {
            throw new RuntimeException('Rollout exceeds approved channel, percentage or interval.');
        }
    }

    private function suspendVersion(ModelOperationsStore $store, ModelRelease $version, DateTimeImmutable $now): void
    {
        if ($version->status !== 'retired') {
            $store->saveVersion($version->suspended());
        }
        foreach ($store->assignments() as $assignment) {
            if ($assignment->versionId === $version->id && $assignment->active($now)) {
                $store->saveAssignment($assignment->end($now));
            }
        }
    }

    private function guardSuspend(
        ModelOperationsStore $store,
        DeploymentAssignment $assignment,
        DateTimeImmutable $now
    ): void {
        $key = 'model-guard:' . hash('sha256', $assignment->id . ':' . $assignment->versionId);
        if ($store->command($key) !== null) {
            return;
        }
        $version = $this->version($store, $assignment->versionId);
        $this->suspendVersion($store, $version, $now);
        $result = ['version_id' => $version->id,
            'status' => $version->status === 'retired' ? 'retired' : 'suspended', 'reason' => 'serving_guard'];
        $store->appendOperation($key, hash('sha256', $key), ['operation' => 'model.guard_suspend',
            'actor_user_id' => null, 'occurred_at' => $this->iso($now),
            'policy_sha256' => $this->policy->digest(), 'result' => $result], $result);
    }

    private function version(ModelOperationsStore $store, string $id): ModelRelease
    {
        $version = $store->version($id);
        if ($version === null) {
            throw new RuntimeException('Model version unavailable.');
        }
        return $version;
    }

    private function command(
        string $key,
        string $operation,
        array $payload,
        callable $work,
        ?int $actor,
        DateTimeImmutable $now
    ): array {
        if (preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A bounded idempotency key is required.');
        }
        $fingerprint = hash('sha256', serialize([$operation, $payload, $this->policy->digest()]));
        return $this->repository->transaction(function (ModelOperationsStore $store) use (
            $key,
            $operation,
            $fingerprint,
            $work,
            $actor,
            $now
        ) {
            $prior = $store->command($key);
            if ($prior !== null) {
                if (!hash_equals($prior['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Idempotency key reused with a different command.');
                }
                return $prior['result'];
            }
            $result = $work($store);
            $store->appendOperation($key, $fingerprint, ['operation' => 'model.' . $operation,
                'actor_user_id' => $actor, 'occurred_at' => $this->iso($now),
                'policy_sha256' => $this->policy->digest(), 'result' => $result], $result);
            return $result;
        });
    }

    private function unavailable(string $reason): array
    {
        return ['status' => 'no_selection', 'reason' => $reason, 'version_id' => null,
            'assignment_id' => null, 'auto_trade_execution' => false];
    }

    private function iso(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
