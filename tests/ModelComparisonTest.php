<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\MarketCatalog;
use App\Domain\ModelComparison\ChampionPolicy;
use App\Domain\ModelComparison\ComparisonSnapshot;
use App\Domain\ModelComparison\ComparisonViewer;
use App\Domain\ModelHealth\ApprovalSigner;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\OperatorContext;
use App\Repositories\UnavailableChampionRepository;
use App\Services\ChampionDecisionService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimoraTests\Support\ChampionTestRepository;
use SimoraTests\Support\ModelHealthTestClock;

/** Hypothetical approved metadata tests only, not provider/model release evidence. */
final class ModelComparisonTest extends TestCase
{
    private const REPORT_KEY = 'unit-test-report-key-never-production';
    private ChampionTestRepository $store;
    private ModelHealthTestClock $clock;
    private ChampionPolicy $policy;
    private DeploymentScope $scope;
    private ChampionDecisionService $service;
    private OperatorContext $actor;
    private ComparisonViewer $viewer;

    protected function setUp(): void
    {
        $this->store = new ChampionTestRepository();
        $this->clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T13:00:00Z'));
        $this->scope = new DeploymentScope('authorized_live', 'test-platform', 'test-league', 'over_25');
        $this->policy = new ChampionPolicy(
            'test-policy',
            'test-experiment',
            20,
            5,
            3600,
            3600,
            0.1,
            64,
            1,
            0.001,
            0.001
        );
        $this->service = $this->makeService($this->store);
        $this->actor = new OperatorContext(
            2,
            ['models.champions.approve'],
            $this->clock->time->modify('+3 hours'),
            true,
            str_repeat('a', 64),
            str_repeat('a', 64)
        );
        $this->viewer = new ComparisonViewer(
            'unit-reader',
            $this->clock->time->modify('+3 hours'),
            ['model_comparisons']
        );
    }

    private function makeService($store): ChampionDecisionService
    {
        return new ChampionDecisionService(
            $store,
            $this->clock,
            $this->policy,
            new ComparisonSnapshot(['unit-key' => self::REPORT_KEY]),
            new ApprovalSigner(['unit-decisions' => str_repeat('decision-test-key', 2)], 'unit-decisions')
        );
    }

    private function report(): array
    {
        $models = ['alpha-v1', 'beta-v1'];
        $versions = [...$models, 'base-v1'];
        $metrics = $deltas = [];
        foreach ($versions as $i => $version) {
            foreach (array_keys(MarketCatalog::all()) as $market) {
                $metrics[$version][$market] = ['count' => 100, 'brier' => 0.1 + 0.1 * $i,
                    'log_loss' => 0.3 + 0.1 * $i];
                if ($version === 'base-v1') {
                    continue;
                }
                foreach ($versions as $j => $other) {
                    if ($other === $version) {
                        continue;
                    }
                    foreach (['brier', 'log_loss'] as $metric) {
                        $point = ($i - $j) * 0.1;
                        $deltas[$version][$other][$market][$metric] = ['estimate' => $point,
                            'ci' => ['lower' => $point - 0.02, 'upper' => $point + 0.02, 'reason' => null]];
                    }
                }
            }
        }
        return ['schema_version' => 'simora.comparison-report.v1', 'release_eligible' => true,
            'partition' => 'authorized_live', 'platform' => 'test-platform', 'league' => 'test-league',
            'policy_version' => 'test-policy', 'experiment_id' => 'test-experiment',
            'created_at' => $this->clock->time->modify('-1 minute')->format(DATE_ATOM),
            'evaluation_as_of' => $this->clock->time->modify('-2 minutes')->format(DATE_ATOM),
            'model_versions' => $models, 'baseline_versions' => ['base-v1'],
            'bootstrap' => ['method' => 'whole_virtual_round_percentile', 'paired_skill_draws' => true,
                'level' => 0.999, 'resamples' => 10000],
            'skill' => ['count' => 100, 'round_clusters' => 20,
                'fixture_ids' => array_map(static fn ($i) => 'f' . $i, range(1, 100)),
                'models' => $metrics, 'paired_deltas' => $deltas],
            'selected' => ['beta-v1' => ['roi' => '99999'], 'alpha-v1' => ['roi' => '-1']]];
    }

    private function snapshot(array $report): string
    {
        $payload = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);
        $this->store->reports[$hash] = json_encode(
            ['schema_version' => ComparisonSnapshot::SCHEMA,
            'key_id' => 'unit-key', 'payload_json' => $payload, 'payload_sha256' => $hash,
            'signature' => hash_hmac('sha256', ComparisonSnapshot::SCHEMA . "\nunit-key\n" . $hash, self::REPORT_KEY)],
            JSON_THROW_ON_ERROR
        );
        return $hash;
    }

    private function commit(string $key, ?array $report = null, ?DateTimeImmutable $effectiveAt = null)
    {
        $sha = $this->snapshot($report ?? $this->report());
        return $this->service->commit(
            $this->actor,
            $key,
            $this->scope,
            $sha,
            $effectiveAt ?? $this->clock->time,
            $this->clock->time->modify('+30 minutes')
        );
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

    public function testPairedSkillChoosesWinnerWithoutReadingSelectedRoi(): void
    {
        $record = $this->commit('d1');
        self::assertSame('alpha-v1', $record->modelVersion);
        self::assertSame('paired_skill_dominance', $record->reason);
        self::assertNull($record->availableAt);
        $pending = $this->service->resolve($this->scope, $this->clock->time, $this->viewer);
        self::assertSame('no_committed_decision', $pending['reason']);
        $this->clock->time = $this->clock->time->modify('+2 seconds');
        $resolved = $this->service->resolve($this->scope, $this->clock->time, $this->viewer);
        self::assertSame('alpha-v1', $resolved['model_version']);
    }

    public function testFutureEffectiveAndDurablyVisibleDecisionsDoNotLeakBackward(): void
    {
        $before = $this->clock->time;
        $this->commit('d1', null, $before->modify('+5 minutes'));
        $this->clock->time = $before->modify('+10 minutes');
        self::assertSame('no_committed_decision', $this->service->resolve(
            $this->scope,
            $before->modify('+4 minutes'),
            $this->viewer
        )['reason']);
        self::assertSame('alpha-v1', $this->service->resolve(
            $this->scope,
            $before->modify('+5 minutes'),
            $this->viewer
        )['model_version']);
        $past = $this->service->resolve($this->scope, $before, $this->viewer);
        self::assertSame('no_committed_decision', $past['reason']);
    }

    public function testNewAbstentionSupersedesOlderWinnerWithoutFallback(): void
    {
        $this->commit('old');
        $oldAsOf = $this->clock->time->modify('+2 seconds');
        $this->clock->time = $this->clock->time->modify('+1 minute');
        $report = $this->report();
        $report['skill']['count'] = 1;
        $this->commit('abstain', $report);
        $this->clock->time = $this->clock->time->modify('+2 seconds');
        self::assertNull($this->service->resolve($this->scope, $this->clock->time, $this->viewer)['model_version']);
        self::assertSame('alpha-v1', $this->service->resolve($this->scope, $oldAsOf, $this->viewer)['model_version']);
        self::assertCount(2, $this->store->records);
    }

    public function testLaterReportCannotChangeCommittedChampion(): void
    {
        $record = $this->commit('d1');
        $later = $this->report();
        $later['selected']['beta-v1']['roi'] = '100000000';
        $this->snapshot($later);
        $this->clock->time = $this->clock->time->modify('+2 seconds');
        self::assertSame($record->reportSha256, $this->service->resolve(
            $this->scope,
            $this->clock->time,
            $this->viewer
        )['report_sha256']);
        self::assertSame('alpha-v1', $this->store->records['d1']['record']->modelVersion);
    }

    public function testMissingUnapprovedResearchOrStaleReportsCannotCreateChampion(): void
    {
        foreach (['research', 'stale', 'future', 'approval'] as $i => $mode) {
            $this->clock->time = $this->clock->time->modify('+1 minute');
            $report = $this->report();
            if ($mode === 'research') {
                $report['release_eligible'] = false;
            } elseif ($mode === 'stale') {
                $report['evaluation_as_of'] = $this->clock->time->modify('-2 hours')->format(DATE_ATOM);
            } elseif ($mode === 'future') {
                $report['created_at'] = $this->clock->time->modify('+1 minute')->format(DATE_ATOM);
            } else {
                $this->store->reportApproval = false;
            }
            self::assertNull($this->commit('a' . $i, $report)->modelVersion);
        }
        $this->denied(fn () => $this->service->commit(
            $this->actor,
            'missing',
            $this->scope,
            str_repeat('a', 64),
            $this->clock->time,
            $this->clock->time->modify('+30 minutes')
        ));
    }

    public function testMultiplicityIntervalsCoverageAndTieFailuresAbstain(): void
    {
        foreach (['level', 'tail', 'ci', 'tie', 'clusters'] as $i => $mode) {
            $this->clock->time = $this->clock->time->modify('+1 minute');
            $report = $this->report();
            if ($mode === 'level') {
                $report['bootstrap']['level'] = 0.95;
            } elseif ($mode === 'tail') {
                $report['bootstrap']['resamples'] = 10;
            } elseif ($mode === 'clusters') {
                $report['skill']['round_clusters'] = 1;
            } else {
                $report['skill']['paired_deltas']['alpha-v1']['beta-v1']['over_25']['brier']['ci']['upper'] = 0;
            }
            self::assertNull($this->commit('fail' . $i, $report)->modelVersion);
        }
    }

    public function testAllEightMarketDenominatorsAndScopeMustMatch(): void
    {
        $report = $this->report();
        $report['skill']['models']['beta-v1']['under_15']['count'] = 99;
        $this->denied(fn () => $this->commit('badcount', $report));
        $report = $this->report();
        unset($report['skill']['models']['alpha-v1']['under_15']);
        $this->denied(fn () => $this->commit('missingmarket', $report));
        $report = $this->report();
        $report['league'] = 'other-league';
        $this->denied(fn () => $this->commit('crossscope', $report));
        self::assertSame([], $this->store->records);
    }

    public function testUnavailableWinnerDoesNotSelectRunnerUpAndLaterSuspensionDeniesReads(): void
    {
        $this->commit('d1');
        $this->store->eligible['alpha-v1'] = false;
        $this->clock->time = $this->clock->time->modify('+2 seconds');
        self::assertSame('chosen_model_ineligible', $this->service->resolve(
            $this->scope,
            $this->clock->time,
            $this->viewer
        )['reason']);
        $this->clock->time = $this->clock->time->modify('+1 minute');
        $record = $this->commit('d2');
        self::assertNull($record->modelVersion);
        self::assertSame('chosen_model_ineligible', $record->reason);
    }

    public function testExpiredLatestDecisionNeverFallsBackToPriorDecision(): void
    {
        $this->commit('old');
        $this->clock->time = $this->clock->time->modify('+1 minute');
        $sha = $this->snapshot($this->report());
        $this->service->commit(
            $this->actor,
            'latest',
            $this->scope,
            $sha,
            $this->clock->time,
            $this->clock->time->modify('+5 seconds')
        );
        $this->clock->time = $this->clock->time->modify('+5 seconds');
        self::assertSame('decision_expired_or_unapproved', $this->service->resolve(
            $this->scope,
            $this->clock->time,
            $this->viewer
        )['reason']);
    }

    public function testReplayAfterTimeAdvancesIsIdempotentAndKeyCollisionFails(): void
    {
        $sha = $this->snapshot($this->report());
        $effective = $this->clock->time;
        $expires = $effective->modify('+30 minutes');
        $first = $this->service->commit($this->actor, 'd1', $this->scope, $sha, $effective, $expires);
        $this->clock->time = $effective->modify('+1 minute');
        $again = $this->service->commit($this->actor, 'd1', $this->scope, $sha, $effective, $expires);
        self::assertSame($first->claims(), $again->claims());
        self::assertCount(1, $this->store->audit);
        $this->denied(fn () => $this->service->commit(
            $this->actor,
            'd1',
            $this->scope,
            $sha,
            $this->clock->time,
            $expires
        ));
        $this->denied(fn () => $this->service->commit(
            $this->actor,
            'backdated',
            $this->scope,
            $sha,
            $effective,
            $expires
        ));
    }

    public function testAuditFailureRollsBackAndDuplicateDecisionClockIsRejected(): void
    {
        $this->store->failAudit = true;
        $this->denied(fn () => $this->commit('failed'));
        self::assertSame([], $this->store->records);
        $this->store->failAudit = false;
        $this->commit('first');
        $this->denied(fn () => $this->commit('same-clock'));
        self::assertCount(1, $this->store->records);
    }

    public function testTamperingAndWrongKeysCannotApproveSnapshot(): void
    {
        $sha = $this->snapshot($this->report());
        $envelope = json_decode($this->store->reports[$sha], true, 512, JSON_THROW_ON_ERROR);
        $envelope['payload_json'] .= ' ';
        $this->store->reports[$sha] = json_encode($envelope, JSON_THROW_ON_ERROR);
        $this->denied(fn () => $this->service->commit(
            $this->actor,
            'tamper',
            $this->scope,
            $sha,
            $this->clock->time,
            $this->clock->time->modify('+30 minutes')
        ));
        $verifier = new ComparisonSnapshot(['unit-key' => str_repeat('wrong', 8)]);
        $this->denied(fn () => $verifier->verify($this->store->reports[$sha], $sha));
        self::assertSame([], $this->store->records);
    }

    public function testLostOrCorruptSnapshotDeniesAlreadyCommittedWinner(): void
    {
        $record = $this->commit('d1');
        $this->clock->time = $this->clock->time->modify('+2 seconds');
        $original = $this->store->reports[$record->reportSha256];
        unset($this->store->reports[$record->reportSha256]);
        self::assertNull($this->service->resolve($this->scope, $this->clock->time, $this->viewer)['model_version']);
        $this->store->reports[$record->reportSha256] = $original . 'bad-json';
        self::assertNull($this->service->resolve($this->scope, $this->clock->time, $this->viewer)['model_version']);
        self::assertSame('alpha-v1', $this->store->records['d1']['record']->modelVersion);
    }

    public function testFutureActivationCannotExtendPastEvidenceFreshness(): void
    {
        $sha = $this->snapshot($this->report());
        $this->denied(fn () => $this->service->commit(
            $this->actor,
            'too-late',
            $this->scope,
            $sha,
            $this->clock->time->modify('+58 minutes'),
            $this->clock->time->modify('+59 minutes')
        ));
        self::assertSame([], $this->store->records);
    }

    public function testPermissionsEntitlementsFutureClockAndMissingBackendFailClosed(): void
    {
        $bad = new OperatorContext(2, [], $this->clock->time->modify('+1 hour'), true, null, null);
        $sha = $this->snapshot($this->report());
        $this->denied(fn () => $this->service->commit(
            $bad,
            'a',
            $this->scope,
            $sha,
            $this->clock->time,
            $this->clock->time->modify('+30 minutes')
        ));
        $viewer = new ComparisonViewer('unit-user', $this->clock->time->modify('+1 hour'), []);
        $limited = $this->service->resolve($this->scope, $this->clock->time, $viewer);
        self::assertSame('access_limited', $limited['reason']);
        self::assertSame('future_selection_clock', $this->service->resolve(
            $this->scope,
            $this->clock->time->modify('+1 second'),
            $this->viewer
        )['reason']);
        $service = $this->makeService(new UnavailableChampionRepository());
        self::assertSame('champion_backend_unavailable', $service->resolve(
            $this->scope,
            $this->clock->time,
            $this->viewer
        )['reason']);
    }
}
