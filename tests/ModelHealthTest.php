<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\ModelHealth\ApprovalSigner;
use App\Domain\ModelHealth\DeploymentAssignment;
use App\Domain\ModelHealth\DeploymentPolicy;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\HealthObservation;
use App\Domain\ModelHealth\HealthPolicy;
use App\Domain\ModelHealth\ModelRelease;
use App\Domain\ModelHealth\OperatorContext;
use App\Domain\ModelHealth\ServingContext;
use App\Repositories\UnavailableModelOperationsRepository;
use App\Services\ModelHealthService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimoraTests\Support\ModelHealthTestClock;
use SimoraTests\Support\ModelOperationsTestRepository;

/** All grants, releases, metrics and actors below are isolated unit-test assertions. */
final class ModelHealthTest extends TestCase
{
    private ModelOperationsTestRepository $store;
    private ModelHealthTestClock $clock;
    private DeploymentScope $scope;
    private HealthPolicy $health;
    private DeploymentPolicy $policy;
    private ApprovalSigner $signer;
    private ModelHealthService $service;
    private OperatorContext $operator;

    protected function setUp(): void
    {
        $this->store = new ModelOperationsTestRepository();
        $this->clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T10:00:00Z'));
        $this->scope = new DeploymentScope('authorized_live', 'test-platform', 'test-league', 'over_25');
        $maximums = array_fill_keys(HealthPolicy::METRICS, 0.2);
        $maximums['source_lag_seconds'] = 100;
        $maximums['queue_age_seconds'] = 100;
        $this->health = new HealthPolicy(
            'unit-test-only',
            $maximums,
            ['goals' => [50, 50]],
            str_repeat('f', 64),
            $this->clock->time->modify('-2 days'),
            0.05,
            100,
            120
        );
        $this->policy = new DeploymentPolicy($this->health, 2000, 60, 3600);
        $this->signer = new ApprovalSigner(['test-key' => str_repeat('test', 8)], 'test-key');
        $this->service = new ModelHealthService($this->store, $this->clock, $this->policy, $this->signer);
        $this->operator = $this->actor();
        $this->store->versions['v1'] = $this->release();
        $this->store->saveHealth($this->sample(), []);
    }

    private function actor(array $changes = []): OperatorContext
    {
        return new OperatorContext(...array_replace([
            'userId' => 2,
            'permissions' => [
                'models.approve', 'models.deploy', 'models.suspend', 'models.rollback', 'models.health.read',
            ],
            'expiresAt' => $this->clock->time->modify('+2 hours'), 'https' => true,
            'sessionCsrf' => str_repeat('a', 64), 'providedCsrf' => str_repeat('a', 64),
        ], $changes));
    }

    private function release(array $changes = []): ModelRelease
    {
        return new ModelRelease(...array_replace([
            'id' => 'v1', 'engine' => 'spe', 'status' => 'validated', 'createdBy' => 1,
            'artifactSha256' => str_repeat('a', 64), 'evidenceSha256' => str_repeat('b', 64),
            'validatedAt' => $this->clock->time->modify('-1 hour'), 'scope' => $this->scope,
            'gates' => array_fill_keys(ModelRelease::GATES, true), 'releaseEligible' => true, 'operatorLabel' => 'spe',
        ], $changes));
    }

    private function sample(array $changes = []): HealthObservation
    {
        return new HealthObservation(...array_replace([
            'versionId' => 'v1', 'scope' => $this->scope, 'artifactSha256' => str_repeat('a', 64),
            'featureSchemaSha256' => str_repeat('f', 64), 'windowStart' => $this->clock->time->modify('-2 minutes'),
            'windowEnd' => $this->clock->time, 'observedAt' => $this->clock->time,
            'availableAt' => $this->clock->time, 'ingestedAt' => $this->clock->time, 'sampleCount' => 100,
            'metrics' => array_fill_keys(HealthPolicy::METRICS, 0.0), 'featureCounts' => ['goals' => [50, 50]],
            'artifactVerified' => true, 'dependenciesHealthy' => true, 'manifestSha256' => str_repeat('d', 64),
        ], $changes));
    }

    private function shadow(string $id = 'v1'): string
    {
        $approval = $id . '-shadow-approval';
        $this->service->approve(
            $this->operator,
            $approval,
            $id,
            $this->scope,
            'shadow',
            $this->clock->time->modify('+50 minutes')
        );
        $assignment = $id . '-shadow';
        $this->service->deploy(
            $this->operator,
            $assignment,
            $approval,
            $id,
            $this->scope,
            'shadow',
            10000,
            $this->clock->time->modify('+40 minutes')
        );
        return $assignment;
    }

    private function limited(string $id = 'v1'): string
    {
        $this->shadow($id);
        $this->clock->time = $this->clock->time->modify('+60 seconds');
        $this->service->observe($id . '-healthy', $this->sample(['versionId' => $id]));
        $this->service->approve(
            $this->operator,
            $id . '-limited-approval',
            $id,
            $this->scope,
            'limited',
            $this->clock->time->modify('+30 minutes')
        );
        $this->service->deploy(
            $this->operator,
            $id . '-limited',
            $id . '-limited-approval',
            $id,
            $this->scope,
            'limited',
            2000,
            $this->clock->time->modify('+20 minutes')
        );
        return $id . '-limited';
    }

    private function viewer(bool $included = true): ServingContext
    {
        $limited = array_filter($this->store->deployments, static fn ($a) => $a->channel === 'limited');
        $assignment = array_values($limited)[0] ?? null;
        for ($i = 1; $i < 1000; $i++) {
            $subject = 'unit-user:' . $i;
            if ($assignment === null || $assignment->includes($subject) === $included) {
                return new ServingContext($subject, $this->clock->time->modify('+1 hour'), ['limited_analytics']);
            }
        }
        throw new RuntimeException('No unit-test cohort subject.');
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            self::fail('Operation should fail closed.');
        } catch (RuntimeException | InvalidArgumentException $expected) {
            self::assertNotSame('', $expected->getMessage());
        }
    }

    public function testShadowNeverServesAndLimitedEnrollmentIsStable(): void
    {
        $this->shadow();
        self::assertSame('no_assignment', $this->service->resolve($this->scope, $this->viewer())['reason']);
        $this->clock->time = $this->clock->time->modify('+60 seconds');
        $this->service->observe('fresh', $this->sample());
        $expiry = $this->clock->time->modify('+10 minutes');
        $this->service->approve($this->operator, 'limited-approval', 'v1', $this->scope, 'limited', $expiry);
        $this->service->deploy(
            $this->operator,
            'limited',
            'limited-approval',
            'v1',
            $this->scope,
            'limited',
            2000,
            $expiry
        );
        $viewer = $this->viewer();
        $result = $this->service->resolve($this->scope, $viewer);
        self::assertSame('eligible', $result['status']);
        self::assertFalse($result['auto_trade_execution']);
        self::assertSame($result, $this->service->resolve($this->scope, $viewer));
        $outside = $this->service->resolve($this->scope, $this->viewer(false));
        self::assertSame('outside_limited_cohort', $outside['reason']);
    }

    public function testLimitedRequiresRealShadowDurationAndWholeWindow(): void
    {
        $expiry = $this->clock->time->modify('+30 minutes');
        $this->service->approve($this->operator, 'a', 'v1', $this->scope, 'limited', $expiry);
        $deploy = fn () => $this->service->deploy(
            $this->operator,
            'l',
            'a',
            'v1',
            $this->scope,
            'limited',
            1000,
            $expiry
        );
        $this->denied($deploy);
        $this->shadow();
        $this->denied($deploy);
        $this->clock->time = $this->clock->time->modify('+60 seconds');
        $this->store->saveHealth($this->sample(['windowStart' => $this->clock->time->modify('-10 seconds')]), []);
        $this->denied($deploy);
        self::assertArrayNotHasKey('l', $this->store->deployments);
    }

    public function testDriftHasKnownBoundsAndFrozenSchema(): void
    {
        self::assertSame(0.0, $this->health->divergence([50, 50], [500, 500]));
        self::assertEqualsWithDelta(log(2), $this->health->divergence([100, 0], [0, 100]), 1e-12);
        self::assertEqualsWithDelta(
            $this->health->divergence([10, 90], [80, 20]),
            $this->health->divergence([80, 20], [10, 90]),
            1e-12
        );
        foreach ([[0, 0], [1], [1, -1], [1.5, 1], [PHP_INT_MAX, PHP_INT_MAX]] as $bad) {
            $this->denied(fn () => $this->health->divergence([50, 50], $bad));
        }
        $reasons = $this->health->failures(
            $this->sample(['featureSchemaSha256' => str_repeat('c', 64)]),
            'v1',
            str_repeat('a', 64),
            $this->scope,
            $this->clock->time
        );
        self::assertContains('feature_schema_mismatch', $reasons);
    }

    public function testEveryMetricMissingNonfiniteOrBreachedFailsClosed(): void
    {
        foreach (HealthPolicy::METRICS as $metric) {
            foreach ([null, NAN, INF, 1000] as $value) {
                $metrics = array_fill_keys(HealthPolicy::METRICS, 0.0);
                $metrics[$metric] = $value;
                self::assertContains('metric_' . $metric, $this->health->failures(
                    $this->sample(['metrics' => $metrics]),
                    'v1',
                    str_repeat('a', 64),
                    $this->scope,
                    $this->clock->time
                ));
            }
        }
        $metrics = array_fill_keys(HealthPolicy::METRICS, 0.0);
        $metrics['brier_degradation'] = -0.1;
        self::assertSame([], $this->health->failures(
            $this->sample(['metrics' => $metrics]),
            'v1',
            str_repeat('a', 64),
            $this->scope,
            $this->clock->time
        ));
    }

    public function testFutureLateBackfillAndOldMetricWindowsAreRejected(): void
    {
        $future = $this->clock->time->modify('+1 microsecond');
        foreach (
            [['availableAt' => $future], ['ingestedAt' => $future],
            ['observedAt' => $future, 'availableAt' => $future, 'ingestedAt' => $future],
            ['windowStart' => $this->clock->time->modify('-10 minutes'),
                'windowEnd' => $this->clock->time->modify('-5 minutes')]] as $changes
        ) {
            self::assertContains('health_stale_or_future', $this->health->failures(
                $this->sample($changes),
                'v1',
                str_repeat('a', 64),
                $this->scope,
                $this->clock->time
            ));
        }
        $this->denied(fn () => $this->sample(['availableAt' => $this->clock->time->modify('-1 second')]));
    }

    public function testCriticalDriftSuspendsAllAssignmentsAndCannotAutoResume(): void
    {
        $this->limited();
        $result = $this->service->observe('drift', $this->sample(['featureCounts' => ['goals' => [100, 0]]]));
        self::assertSame('suspended', $result['status']);
        self::assertContains('feature_drift_goals', $result['reasons']);
        foreach ($this->store->deployments as $assignment) {
            self::assertFalse($assignment->active($this->clock->time));
        }
        self::assertSame('suspended', $this->service->observe('healthy-again', $this->sample())['status']);
        $this->denied(fn () => $this->service->approve(
            $this->operator,
            'new-approval',
            'v1',
            $this->scope,
            'limited',
            $this->clock->time->modify('+1 minute')
        ));
    }

    public function testStaleHealthServingGuardPersistsSuspensionAndHasNoFallback(): void
    {
        $this->limited();
        $this->store->versions['v2'] = $this->release(['id' => 'v2']);
        $this->clock->time = $this->clock->time->modify('+120 seconds');
        self::assertSame('no_selection', $this->service->resolve($this->scope, $this->viewer())['status']);
        self::assertSame('suspended', $this->store->versions['v1']->status);
        self::assertSame('model.guard_suspend', end($this->store->events)['operation']);
        self::assertSame('no_assignment', $this->service->resolve($this->scope, $this->viewer())['reason']);
    }

    public function testSourceRevocationAndMissingHealthFailClosed(): void
    {
        $this->limited();
        $this->store->sourceAllowed = false;
        self::assertSame('model_ineligible', $this->service->resolve($this->scope, $this->viewer())['reason']);
        self::assertSame('suspended', $this->store->versions['v1']->status);
        $this->store->observations = [];
        self::assertSame(['health_missing'], $this->health->failures(
            null,
            'v1',
            str_repeat('a', 64),
            $this->scope,
            $this->clock->time
        ));
    }

    public function testArtifactMismatchDependencyFailureAndInsufficientSamplesFail(): void
    {
        foreach (
            [['artifactVerified' => false], ['artifactSha256' => str_repeat('e', 64)],
            ['dependenciesHealthy' => false], ['sampleCount' => 1], ['featureCounts' => []],
            ['featureCounts' => ['goals' => [1, 1]]]] as $changes
        ) {
            self::assertNotEmpty($this->health->failures(
                $this->sample($changes),
                'v1',
                str_repeat('a', 64),
                $this->scope,
                $this->clock->time
            ));
        }
    }

    public function testIndependentApprovalAndSecureSessionCsrfRbacRequired(): void
    {
        $this->store->approvers[1] = true;
        foreach (
            [['userId' => 1], ['permissions' => []], ['https' => false], ['providedCsrf' => null],
            ['expiresAt' => $this->clock->time]] as $changes
        ) {
            $this->denied(fn () => $this->service->approve(
                $this->actor($changes),
                'a',
                'v1',
                $this->scope,
                'shadow',
                $this->clock->time->modify('+1 minute')
            ));
        }
        self::assertSame([], $this->store->events);
    }

    public function testResearchArtifactsIncompleteGatesAndProductionChannelCannotRelease(): void
    {
        foreach (
            [['releaseEligible' => false], ['gates' => []], ['status' => 'trained'],
            ['engine' => 'beve', 'operatorLabel' => 'regularized_ensemble'],
            ['validatedAt' => $this->clock->time->modify('+1 second')]] as $changes
        ) {
            $this->store->versions['v1'] = $this->release($changes);
            $this->denied(fn () => $this->service->approve(
                $this->operator,
                'a',
                'v1',
                $this->scope,
                'shadow',
                $this->clock->time->modify('+1 minute')
            ));
        }
        $this->denied(fn () => $this->service->approve(
            $this->operator,
            'production',
            'v1',
            $this->scope,
            'production',
            $this->clock->time->modify('+1 minute')
        ));
        $this->denied(fn () => new DeploymentScope('synthetic'));
    }

    public function testApprovalTamperingRevocationAndApproverRemoval(): void
    {
        $this->limited();
        $approval = $this->store->approvals['v1-limited-approval'];
        $this->store->approvals['v1-limited-approval']['claims']['artifact_sha256'] = str_repeat('f', 64);
        self::assertSame('model_ineligible', $this->service->resolve($this->scope, $this->viewer())['reason']);
        $this->store->approvals['v1-limited-approval'] = $approval;
        $this->store->approvers[2] = false;
        self::assertSame('model_ineligible', $this->service->resolve($this->scope, $this->viewer())['reason']);
        $this->store->approvers[2] = true;
        $this->service->revokeApproval($this->operator, 'revoke', 'v1-limited-approval');
        self::assertTrue($this->store->approvals['v1-limited-approval']['revoked']);
        self::assertSame('no_assignment', $this->service->resolve($this->scope, $this->viewer())['reason']);
    }

    public function testChangedPolicyOrCorruptPercentageCannotWidenLimitedRollout(): void
    {
        $this->limited();
        $a = $this->store->deployments['v1-limited'];
        $this->store->deployments[$a->id] = new DeploymentAssignment(
            $a->id,
            $a->versionId,
            $a->scope,
            'limited',
            10000,
            $a->effectiveAt,
            $a->expiresAt,
            $a->approval
        );
        self::assertSame('model_ineligible', $this->service->resolve($this->scope, $this->viewer())['reason']);
        $changed = new ModelHealthService(
            $this->store,
            $this->clock,
            new DeploymentPolicy($this->health, 1000, 60, 3600),
            $this->signer
        );
        self::assertSame('model_ineligible', $changed->resolve($this->scope, $this->viewer())['reason']);
    }

    public function testWildcardOverlapIsRejectedAndEightMarketsAreDistinct(): void
    {
        $this->shadow();
        $global = new DeploymentScope('authorized_live');
        $this->store->versions['global'] = $this->release(['id' => 'global', 'scope' => $global]);
        $this->store->saveHealth($this->sample(['versionId' => 'global', 'scope' => $global]), []);
        $expiry = $this->clock->time->modify('+10 minutes');
        $this->service->approve($this->operator, 'ga', 'global', $global, 'shadow', $expiry);
        $this->denied(fn () => $this->service->deploy(
            $this->operator,
            'gd',
            'ga',
            'global',
            $global,
            'shadow',
            10000,
            $expiry
        ));
        $keys = [];
        foreach (array_keys(\App\Domain\MarketCatalog::all()) as $market) {
            $scope = new DeploymentScope('authorized_live', 'test-platform', 'test-league', $market);
            $keys[] = $scope->key();
            self::assertTrue($global->contains($scope));
        }
        self::assertCount(8, array_unique($keys));
        $other = new DeploymentScope('authorized_live', 'test-platform', 'test-league', 'under_25');
        self::assertFalse($this->scope->overlaps($other));
    }

    public function testCommandsAreIdempotentAndPayloadCollisionIsRejected(): void
    {
        $expiry = $this->clock->time->modify('+10 minutes');
        $first = $this->service->approve($this->operator, 'a', 'v1', $this->scope, 'shadow', $expiry);
        self::assertSame($first, $this->service->approve($this->operator, 'a', 'v1', $this->scope, 'shadow', $expiry));
        self::assertCount(1, $this->store->events);
        $this->denied(fn () => $this->service->approve($this->operator, 'a', 'v1', $this->scope, 'limited', $expiry));
        $this->service->deploy($this->operator, 'd', 'a', 'v1', $this->scope, 'shadow', 10000, $expiry);
        $this->service->deploy($this->operator, 'd', 'a', 'v1', $this->scope, 'shadow', 10000, $expiry);
        self::assertCount(1, $this->store->deployments);
        self::assertCount(2, $this->store->events);
    }

    public function testAuditOutboxFailureRollsBackHealthSuspensionAndAssignments(): void
    {
        $this->limited();
        $events = count($this->store->events);
        $this->store->failAppend = true;
        $this->denied(fn () => $this->service->observe('bad', $this->sample(['artifactVerified' => false])));
        self::assertSame('validated', $this->store->versions['v1']->status);
        self::assertTrue($this->store->deployments['v1-limited']->active($this->clock->time));
        self::assertCount($events, $this->store->events);
        $this->clock->time = $this->clock->time->modify('+120 seconds');
        self::assertSame('model_backend_unavailable', $this->service->resolve($this->scope, $this->viewer())['reason']);
        self::assertSame('validated', $this->store->versions['v1']->status);
    }

    public function testRollbackAfterSuspensionRequiresFreshHealthyExplicitPreviousVersion(): void
    {
        $this->limited();
        $this->service->revokeApproval($this->operator, 'close-v1', 'v1-limited-approval');
        $originalEnd = $this->store->deployments['v1-limited']->endedAt;
        $this->clock->time = $this->clock->time->modify('+1 second');
        $this->store->versions['v2'] = $this->release(['id' => 'v2']);
        $this->store->saveHealth($this->sample(['versionId' => 'v2']), []);
        $this->limited('v2');
        $this->service->suspend($this->operator, 'stop-v2', 'v2', 'unit-test incident');
        $this->service->observe('refresh-v1', $this->sample());
        $expiry = $this->clock->time->modify('+10 minutes');
        $this->service->approve($this->operator, 'rollback-approval', 'v1', $this->scope, 'limited', $expiry);
        $result = $this->service->rollback(
            $this->operator,
            'rollback',
            'v2-limited',
            'v1-limited',
            'rollback-approval',
            $expiry
        );
        self::assertSame('v1', $result['version_id']);
        self::assertSame('v2-limited', $this->store->deployments['rollback']->rollbackOf);
        self::assertSame($originalEnd, $this->store->deployments['v1-limited']->endedAt);
        self::assertSame('eligible', $this->service->resolve($this->scope, $this->viewer())['status']);
        self::assertSame('suspended', $this->store->versions['v2']->status);
    }

    public function testFailedRollbackDoesNotEndCurrentAssignment(): void
    {
        $this->limited();
        $this->service->revokeApproval($this->operator, 'close-v1', 'v1-limited-approval');
        $this->clock->time = $this->clock->time->modify('+1 second');
        $this->store->versions['v2'] = $this->release(['id' => 'v2']);
        $this->store->saveHealth($this->sample(['versionId' => 'v2']), []);
        $this->limited('v2');
        $this->store->versions['v1'] = $this->store->versions['v1']->suspended();
        $this->denied(fn () => $this->service->rollback(
            $this->operator,
            'rollback',
            'v2-limited',
            'v1-limited',
            'v1-limited-approval',
            $this->clock->time->modify('+10 minutes')
        ));
        self::assertTrue($this->store->deployments['v2-limited']->active($this->clock->time));
        self::assertArrayNotHasKey('rollback', $this->store->deployments);
    }

    public function testMissingBackendEntitlementAndIncompleteScopeFailClosed(): void
    {
        $service = new ModelHealthService(
            new UnavailableModelOperationsRepository(),
            $this->clock,
            $this->policy,
            $this->signer
        );
        self::assertSame('model_backend_unavailable', $service->resolve($this->scope, $this->viewer())['reason']);
        $this->denied(fn () => $service->approve(
            $this->operator,
            'a',
            'v1',
            $this->scope,
            'shadow',
            $this->clock->time->modify('+10 minutes')
        ));
        $noPlan = new ServingContext('test-user', $this->clock->time->modify('+1 hour'), []);
        self::assertSame('access_limited', $this->service->resolve($this->scope, $noPlan)['reason']);
        $incomplete = $this->service->resolve(new DeploymentScope('authorized_live'), $this->viewer());
        self::assertSame('invalid_scope', $incomplete['reason']);
    }

    public function testHealthInspectionRequiresAuthorizationAndShowsStickySuspension(): void
    {
        self::assertTrue($this->service->inspect($this->operator, 'v1', $this->scope)['release_eligible']);
        $this->denied(fn () => $this->service->inspect($this->actor(['permissions' => []]), 'v1', $this->scope));
        $this->service->suspend($this->operator, 's', 'v1', 'unit-test stop');
        $health = $this->service->inspect($this->operator, 'v1', $this->scope);
        self::assertFalse($health['release_eligible']);
        self::assertSame('suspended', $health['status']);
        self::assertSame(str_repeat('d', 64), $health['manifest_sha256']);
    }

    public function testApprovalExpiryKeyRotationAndPercentageBoundaries(): void
    {
        $claims = ['version' => 'unit-test-only'];
        $signed = $this->signer->sign($claims);
        $rotated = new ApprovalSigner(
            ['test-key' => str_repeat('test', 8), 'new-key' => str_repeat('next', 8)],
            'new-key'
        );
        self::assertTrue($rotated->verify($signed));
        self::assertFalse((new ApprovalSigner(['new-key' => str_repeat('next', 8)], 'new-key'))->verify($signed));
        $expiry = $this->clock->time->modify('+1 minute');
        $this->service->approve($this->operator, 'a', 'v1', $this->scope, 'shadow', $expiry);
        $this->denied(fn () => $this->service->deploy(
            $this->operator,
            'd',
            'a',
            'v1',
            $this->scope,
            'shadow',
            9999,
            $expiry
        ));
        $this->clock->time = $expiry;
        $this->denied(fn () => $this->service->deploy(
            $this->operator,
            'd',
            'a',
            'v1',
            $this->scope,
            'shadow',
            10000,
            $expiry->modify('+1 minute')
        ));
    }

    public function testNarrowedHealthAndSourceRightsCannotHideBehindWildcardScope(): void
    {
        $global = new DeploymentScope('authorized_live');
        $this->store->versions['v1'] = $this->release(['scope' => $global]);
        $this->scope = $global;
        $this->store->saveHealth($this->sample(), []);
        $this->limited();
        $exact = new DeploymentScope('authorized_live', 'test-platform', 'test-league', 'under_15');
        self::assertSame('model_ineligible', $this->service->resolve($exact, $this->viewer())['reason']);
        self::assertSame('suspended', $this->store->versions['v1']->status);
    }

    public function testConflictingAssignmentsSuspendRatherThanSelectAnArbitraryVersion(): void
    {
        $this->limited();
        $a = $this->store->deployments['v1-limited'];
        $this->store->versions['v2'] = $this->release(['id' => 'v2']);
        $this->store->deployments['corrupt'] = new DeploymentAssignment(
            'corrupt',
            'v2',
            $a->scope,
            'limited',
            1000,
            $a->effectiveAt,
            $a->expiresAt,
            $a->approval
        );
        self::assertSame('deployment_conflict', $this->service->resolve($this->scope, $this->viewer())['reason']);
        self::assertSame('suspended', $this->store->versions['v1']->status);
        self::assertSame('suspended', $this->store->versions['v2']->status);
    }

    public function testFutureReferenceInsufficientFeatureCountsAndUnapprovedPolicyFail(): void
    {
        $reference = new HealthPolicy(
            'test-only',
            $this->health->maximums,
            ['goals' => [50, 50]],
            str_repeat('f', 64),
            $this->clock->time,
            0.05,
            100,
            120
        );
        self::assertContains('feature_schema_mismatch', $reference->failures(
            $this->sample(),
            'v1',
            str_repeat('a', 64),
            $this->scope,
            $this->clock->time
        ));
        self::assertContains('feature_samples_goals', $this->health->failures(
            $this->sample(['featureCounts' => ['goals' => [100, 100]]]),
            'v1',
            str_repeat('a', 64),
            $this->scope,
            $this->clock->time
        ));
        $this->denied(fn () => new DeploymentPolicy($this->health, 10000, 60, 3600));
        $this->denied(fn () => new HealthPolicy(
            'test-only',
            [],
            ['goals' => [50, 50]],
            str_repeat('f', 64),
            $this->clock->time,
            0.05,
            100,
            120
        ));
    }

    public function testRetirementRemainsTerminalDuringHealthAndManualSuspension(): void
    {
        $this->store->versions['v1'] = $this->release(['status' => 'retired']);
        $result = $this->service->observe('retired-health', $this->sample(['artifactVerified' => false]));
        self::assertSame('retired', $result['status']);
        $manual = $this->service->suspend($this->operator, 'retired-stop', 'v1', 'unit-test stop');
        self::assertSame('retired', $manual['status']);
        self::assertFalse($this->store->versions['v1']->eligible($this->clock->time));
    }
}
