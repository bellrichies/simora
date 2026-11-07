<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\MarketCatalog;
use App\Domain\ModelHealth\OperatorContext;
use App\Domain\Simulation\FixedPoint;
use App\Domain\Simulation\SimulationConfig;
use App\Domain\Simulation\SimulationScope;
use App\Domain\Simulation\StrategySimulator;
use App\Repositories\UnavailableSimulationRepository;
use App\Presenters\SimulationChart;
use App\Services\SimulationService;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimoraTests\Support\ModelHealthTestClock;
use SimoraTests\Support\SimulationTestRepository;

/** Hand-calculated hypothetical ledgers only. No source observations, prediction fixtures or ROI evidence. */
final class StrategySimulatorTest extends TestCase
{
    private function scope(string $market = 'under_25'): SimulationScope
    {
        return new SimulationScope('spe', 'version-unit', 'unit-platform', 'unit-league', $market, 'authorized_live');
    }

    private function config(array $changes = []): SimulationConfig
    {
        return new SimulationConfig(array_replace(['strategy' => 'flat', 'bankroll_minor' => 10000,
            'base_stake_minor' => 100, 'max_stake_minor' => 5000, 'max_loss_minor' => 10000], $changes));
    }

    private function rows(array $outcomes): array
    {
        $rows = [];
        foreach ($outcomes as $index => $outcome) {
            $start = new DateTimeImmutable('2026-11-01T00:00:00Z');
            $kickoff = $start->modify('+' . ($index * 10 + 2) . ' minutes');
            $rows[] = $this->scope()->fields() + ['publication_id' => 'unit-pub-' . $index,
                'settlement_revision_id' => 'unit-revision-' . $index, 'match_id' => 'unit-match-' . $index,
                'outcome' => $outcome, 'published_at' => $kickoff->modify('-1 minute')->format('Y-m-d\TH:i:s\Z'),
                'publication_available_at' => $kickoff->modify('-1 minute')->format('Y-m-d\TH:i:s\Z'),
                'kickoff_at' => $kickoff->format('Y-m-d\TH:i:s\Z'),
                'settled_at' => $kickoff->modify('+2 minutes')->format('Y-m-d\TH:i:s\Z'),
                'settlement_available_at' => $kickoff->modify('+3 minutes')->format('Y-m-d\TH:i:s\Z'),
                'odds_decimal' => '2.000000', 'quote_id' => 'unit-quote-' . $index,
                'odds_observed_at' => $kickoff->modify('-2 minutes')->format('Y-m-d\TH:i:s\Z'),
                'odds_available_at' => $kickoff->modify('-2 minutes')->format('Y-m-d\TH:i:s\Z'),
                'fee_rate_decimal' => '0', 'fee_policy_id' => 'unit-verified-contract',
                'fee_basis' => 'positive_profit', 'void_policy' => 'refund_stake_no_fee'];
        }
        return $rows;
    }

    private function replay(array $rows, array $config = []): array
    {
        return (new StrategySimulator())->replay(
            $this->scope(),
            $this->config($config),
            $rows,
            new DateTimeImmutable('2026-11-06T00:00:00Z')
        );
    }

    public function testFlatGoldenLedgerAndChartReconcileExactly(): void
    {
        $rows = $this->rows(['won', 'lost', 'void']);
        $rows[0]['odds_decimal'] = '1.725000';
        $report = $this->replay($rows);
        self::assertSame(['100', '100', '100'], array_column($report['ledger'], 'stake_minor'));
        self::assertSame(['73', '-100', '0'], array_column($report['ledger'], 'net_minor'));
        self::assertSame('300', $report['total_staked_minor']);
        self::assertSame('273', $report['gross_payouts_minor']);
        self::assertSame('-27', $report['net_minor']);
        self::assertSame('-0.090000', $report['roi_ratio']);
        self::assertSame('9973', $report['ending_bankroll_minor']);
        self::assertSame('100', $report['max_drawdown_minor']);
        self::assertSame(['10000', '10073', '9973', '9973'], array_column($report['chart'], 'bankroll_minor'));
        self::assertSame('100', $report['next_hypothetical']['stake_minor']);
        self::assertTrue($report['next_hypothetical']['allowed']);
        self::assertSame($rows, $report['inclusion_ledger']);
    }

    public function testMartingaleResetsAfterWinAndPreservesVoidStreak(): void
    {
        $report = $this->replay($this->rows(['lost', 'void', 'lost', 'won', 'lost']), ['strategy' => 'martingale']);
        self::assertSame(['100', '200', '200', '400', '100'], array_column($report['ledger'], 'stake_minor'));
        self::assertSame('0', $report['net_minor']);
        self::assertSame(2, $report['max_loss_streak']);
        self::assertSame(1, $report['current_loss_streak']);
        self::assertSame('200', $report['next_hypothetical']['stake_minor']);
    }

    public function testRecoveryUsesRecordedOddsAndAfterFeeOutstandingLoss(): void
    {
        $rows = $this->rows(['lost', 'won', 'won']);
        $rows[1]['odds_decimal'] = '1.500000';
        $rows[1]['fee_rate_decimal'] = '0.100000';
        $report = $this->replay($rows, ['strategy' => 'recovery', 'extra_target_minor' => 10,
            'increment_minor' => 5, 'next_odds_decimal' => '3.000000', 'next_fee_rate_decimal' => '0']);
        // First target 10 at 2.0 -> 10; loss=10. Next gross target ceil(20/.9)=23 -> stake 46 -> 50.
        self::assertSame(['10', '50', '10'], array_column($report['ledger'], 'stake_minor'));
        self::assertSame(['-10', '22', '10'], array_column($report['ledger'], 'net_minor'));
        self::assertSame('3', $report['fees_minor']);
        self::assertSame('22', $report['net_minor']);
        self::assertSame('5', $report['next_hypothetical']['stake_minor']);
        self::assertSame('1.500000', $report['ledger'][1]['odds_decimal']);
    }

    public function testRecoveryCeilsTheStakingFormulaBeforeMinorUnitProfitRounding(): void
    {
        $rows = $this->rows(['won']);
        $rows[0]['odds_decimal'] = '1.500000';
        $report = $this->replay($rows, ['strategy' => 'recovery', 'extra_target_minor' => 1]);
        self::assertSame('2', $report['ledger'][0]['stake_minor']);
    }

    public static function stopCases(): array
    {
        return [
            [['max_stake_minor' => 150, 'strategy' => 'martingale'], 'max_stake', '200'],
            [['bankroll_minor' => 250, 'strategy' => 'martingale'], 'insufficient_bankroll', '200'],
            [['max_steps' => 1, 'strategy' => 'martingale'], 'max_steps', '200'],
            [['max_loss_minor' => 150], 'max_loss', '100'],
        ];
    }

    #[DataProvider('stopCases')]
    public function testRefusedStakeIsRecordedWithoutClipping(array $config, string $reason, string $stake): void
    {
        $report = $this->replay($this->rows(['lost', 'lost', 'won']), $config);
        self::assertCount(1, $report['ledger']);
        self::assertSame($reason, $report['guardrail_breach']);
        self::assertSame($stake, $report['refused_attempts'][0]['attempted_stake_minor']);
        self::assertSame('100', $report['total_staked_minor']);
        self::assertTrue($report['terminated_early']);
        self::assertFalse($report['next_hypothetical']['allowed']);
        self::assertCount(2, $report['chart']);
        self::assertCount(3, $report['inclusion_ledger']);
    }

    public function testNextStakeIsGuardedEvenAfterLastLoss(): void
    {
        $report = $this->replay($this->rows(['lost']), ['strategy' => 'martingale', 'max_stake_minor' => 150]);
        self::assertSame('200', $report['next_hypothetical']['stake_minor']);
        self::assertSame('max_stake', $report['next_hypothetical']['reason']);
        self::assertFalse($report['next_hypothetical']['allowed']);
        self::assertNull($report['guardrail_breach']);
    }

    public function testRecoveryNeedsHypotheticalOddsAndFeesForNextStake(): void
    {
        $report = $this->replay([], ['strategy' => 'recovery']);
        self::assertFalse($report['next_hypothetical']['allowed']);
        self::assertSame('missing_or_invalid_odds', $report['next_hypothetical']['reason']);
        self::assertNull($report['roi_ratio']);
        self::assertSame('0', $report['net_minor']);
        self::assertCount(1, $report['chart']);
    }

    public static function refusedRows(): array
    {
        return [
            [['odds_decimal' => null], 'missing_or_invalid_odds'],
            [['odds_decimal' => '1.000000'], 'missing_or_invalid_odds'],
            [['odds_decimal' => 2.0], 'missing_or_invalid_odds'],
            [['odds_decimal' => '2.0000001'], 'missing_or_invalid_odds'],
            [['quote_id' => null, 'odds_observed_at' => null, 'odds_available_at' => null], 'missing_quote_provenance'],
            [['fee_rate_decimal' => null], 'missing_or_invalid_fee'],
            [['fee_basis' => 'unverified'], 'unsupported_settlement_contract'],
            [['outcome' => 'disputed'], 'unresolved_settlement'],
            [['outcome' => 'void', 'void_policy' => 'unknown'], 'unsupported_settlement_contract'],
        ];
    }

    #[DataProvider('refusedRows')]
    public function testUnsafeRecordsStopWithoutInventedReturns(array $change, string $reason): void
    {
        $rows = $this->rows(['won']);
        $rows[0] = array_replace($rows[0], $change);
        $report = $this->replay($rows);
        self::assertSame($reason, $report['guardrail_breach']);
        self::assertSame([], $report['ledger']);
        self::assertNull($report['roi_ratio']);
    }

    public function testCorrelatedAndOverlappingExposuresNeverReuseSameBankroll(): void
    {
        foreach (['match', 'time'] as $mode) {
            $rows = $this->rows(['won', 'won']);
            if ($mode === 'match') {
                $rows[1]['match_id'] = $rows[0]['match_id'];
            } else {
                $rows[0]['settlement_available_at'] = '2026-11-01T00:20:00Z';
            }
            $report = $this->replay($rows);
            self::assertCount(1, $report['ledger']);
            $reason = $mode === 'match' ? 'correlated_exposure' : 'overlapping_exposure';
            self::assertSame($reason, $report['guardrail_breach']);
        }
    }

    public function testPriorResultMustBeKnownAtPublicationDecisionRatherThanKickoff(): void
    {
        $rows = $this->rows(['won', 'won']);
        // The second match kicks off at 00:12, but its publication was visible before the first settled.
        $rows[1]['published_at'] = $rows[1]['publication_available_at'] = '2026-11-01T00:03:00Z';
        $rows[1]['odds_observed_at'] = $rows[1]['odds_available_at'] = '2026-11-01T00:02:00Z';
        $report = $this->replay($rows);
        self::assertSame('overlapping_exposure', $report['guardrail_breach']);
        self::assertCount(1, $report['ledger']);
    }

    public function testRuinAndIdenticalStrategyCohortsAreExplicit(): void
    {
        $rows = $this->rows(['lost', 'lost', 'won']);
        $reports = [];
        foreach (['flat', 'martingale', 'recovery'] as $strategy) {
            $reports[] = $this->replay($rows, ['strategy' => $strategy,
                'bankroll_minor' => 350, 'next_odds_decimal' => '2', 'next_fee_rate_decimal' => '0']);
        }
        self::assertSame($reports[0]['history_sha256'], $reports[1]['history_sha256']);
        self::assertSame($reports[1]['history_sha256'], $reports[2]['history_sha256']);
        self::assertFalse($reports[0]['terminated_early']);
        self::assertTrue($reports[1]['terminated_early']);
        $ruin = $this->replay($this->rows(['lost']), ['bankroll_minor' => 100]);
        self::assertTrue($ruin['ruined']);
        self::assertSame('0', $ruin['ending_bankroll_minor']);
        self::assertFalse($ruin['next_hypothetical']['allowed']);
    }

    public static function invalidHistory(): array
    {
        return array_map(static fn (array $change): array => [$change], [
            ['model_code' => 'beve'], ['model_version_id' => 'wrong'], ['market_id' => 'over_35'],
            ['dataset_partition' => 'synthetic'], ['platform_id' => 'wrong'], ['league_id' => 'wrong'],
            ['publication_available_at' => '2026-11-01T00:02:00Z'],
            ['odds_available_at' => '2026-11-01T00:01:01Z'],
            ['settlement_available_at' => '2026-11-07T00:00:00Z'],
            ['kickoff_at' => '2026-02-30T00:00:00Z'], ['kickoff_at' => '2026-11-01T99:00:00Z'],
            ['stake_minor' => '1'],
        ]);
    }

    #[DataProvider('invalidHistory')]
    public function testScopeAndPointInTimeChecksRejectLeaks(array $change): void
    {
        $rows = $this->rows(['won']);
        $rows[0] = array_replace($rows[0], $change);
        $this->expectException(InvalidArgumentException::class);
        $this->replay($rows);
    }

    public function testUnorderedAndDuplicatePublicationsAreRejected(): void
    {
        $rows = $this->rows(['lost', 'won']);
        foreach ([array_reverse($rows), [$rows[0], $rows[0]]] as $bad) {
            try {
                $this->replay($bad);
                self::fail('Invalid order accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllEightMarketsAreModelScoped(): void
    {
        foreach (array_keys(MarketCatalog::all()) as $market) {
            $rows = $this->rows(['won']);
            $rows[0]['market_id'] = $market;
            $report = (new StrategySimulator())->replay(
                $this->scope($market),
                $this->config(),
                $rows,
                new DateTimeImmutable('2026-11-06T00:00:00Z')
            );
            self::assertSame($market, $report['scope']['market_id']);
            self::assertCount(1, $report['ledger']);
        }
    }

    public function testIntegerArithmeticChecksOverflowAndLargeCentRounding(): void
    {
        self::assertSame(73, FixedPoint::round(72500000, 1000000));
        self::assertSame(72, FixedPoint::round(72499999, 1000000));
        self::assertSame('0.333333', FixedPoint::ratio(1, 3));
        $report = $this->replay($this->rows(['won']), ['base_stake_minor' => 1000000000,
            'bankroll_minor' => 1000000000, 'max_stake_minor' => 1000000000, 'max_loss_minor' => 1000000000]);
        self::assertSame('2000000000', $report['ending_bankroll_minor']);
        $this->expectException(OverflowException::class);
        FixedPoint::multiply(PHP_INT_MAX, 2);
    }

    public function testRecoveryOverflowAndNoAfterFeeReturnAreExplicitRefusals(): void
    {
        $rows = $this->rows(['won']);
        $rows[0]['fee_rate_decimal'] = '0.999999';
        $report = $this->replay($rows, ['strategy' => 'recovery', 'extra_target_minor' => 1000000000]);
        self::assertSame('arithmetic_overflow', $report['guardrail_breach']);
        self::assertSame('0', $report['total_staked_minor']);
        $rows[0]['fee_rate_decimal'] = '1';
        $report = $this->replay($rows, ['strategy' => 'recovery', 'extra_target_minor' => 1]);
        self::assertSame('no_after_fee_return', $report['guardrail_breach']);
    }

    public function testIncrementRoundingDoesNotCompoundMartingaleRounding(): void
    {
        $report = $this->replay($this->rows(['lost', 'won']), ['strategy' => 'martingale',
            'base_stake_minor' => 101, 'increment_minor' => 5]);
        self::assertSame(['105', '205'], array_column($report['ledger'], 'stake_minor'));
        self::assertSame('105', $report['next_hypothetical']['stake_minor']);
    }

    public function testServerSvgUsesLedgerPointsAndHandlesEmptyHistory(): void
    {
        $rows = $this->rows(['won', 'lost', 'void']);
        $rows[0]['odds_decimal'] = '1.725000';
        $svg = (new SimulationChart())->render($this->replay($rows)['chart']);
        self::assertStringContainsString('points="12,108 204,20 396,140 588,140"', $svg);
        self::assertStringContainsString('cx="12" cy="108"', $svg);
        self::assertStringContainsString('Hypothetical bankroll', $svg);
        self::assertStringContainsString(
            'points="12,140"',
            (new SimulationChart())->render($this->replay([])['chart'])
        );
        $this->expectException(InvalidArgumentException::class);
        (new SimulationChart())->render([['ordinal' => 0, 'bankroll_minor' => '<script>']]);
    }

    public static function invalidConfigs(): array
    {
        return array_map(static fn (array $change): array => [$change], [
            ['bankroll_minor' => 100.0], ['base_stake_minor' => '100'], ['base_stake_minor' => 0],
            ['max_loss_minor' => -1], ['max_stake_minor' => PHP_INT_MAX], ['increment_minor' => 0],
            ['last_n' => 101], ['multiplier' => 2.5], ['max_steps' => PHP_INT_MAX],
            ['strategy' => 'guaranteed_recovery'], ['next_odds_decimal' => 2.0],
            ['next_odds_decimal' => '1'], ['next_fee_rate_decimal' => '1.000001'], ['owner_id' => 1],
        ]);
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigNeverEntersFinancialCalculations(array $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->config($change);
    }

    public function testCorrectionsChangeNewReplaysButKeepOldReportEvidence(): void
    {
        $rows = $this->rows(['won']);
        $old = $this->replay($rows);
        $rows[0]['settlement_revision_id'] = 'unit-revision-corrected';
        $rows[0]['outcome'] = 'lost';
        $new = $this->replay($rows);
        self::assertSame('100', $old['net_minor']);
        self::assertSame('-100', $new['net_minor']);
        self::assertNotSame($old['history_sha256'], $new['history_sha256']);
        self::assertSame('unit-revision-0', $old['ledger'][0]['settlement_revision_id']);
    }

    private function actor(int $owner = 1, bool $csrf = true): OperatorContext
    {
        $token = str_repeat('a', 64);
        return new OperatorContext(
            $owner,
            ['simulations.create', 'simulations.read'],
            new DateTimeImmutable('2026-11-07T00:00:00Z'),
            true,
            $token,
            $csrf ? $token : 'invalid'
        );
    }

    public function testServicePinsLastNAndIdempotentImmutableOwnerReport(): void
    {
        $repository = new SimulationTestRepository();
        $repository->rows = $this->rows(['lost', 'won', 'lost']);
        $clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z'));
        $service = new SimulationService($repository, new StrategySimulator(), $clock, new SimulationChart());
        $report = $service->run(
            $this->actor(),
            'unit-key',
            $this->scope(),
            $this->config(['last_n' => 2]),
            $clock->now()
        );
        self::assertSame(['unit-pub-1', 'unit-pub-2'], array_column($report['ledger'], 'publication_id'));
        $repository->rows = [];
        self::assertSame($report, $service->run(
            $this->actor(),
            'unit-key',
            $this->scope(),
            $this->config(['last_n' => 2]),
            $clock->now()
        ));
        self::assertCount(1, $repository->audits);
        self::assertSame(1, $repository->reads);
        self::assertSame($report, $service->owned($this->actor(), $this->scope(), $report['simulation_run_id']));
        self::assertNull($service->owned($this->actor(2), $this->scope(), $report['simulation_run_id']));
        $this->expectException(RuntimeException::class);
        $service->run($this->actor(), 'unit-key', $this->scope(), $this->config(), $clock->now());
    }

    public function testStorageFailureRollsBackRunAndAuditInContractDouble(): void
    {
        $repository = new SimulationTestRepository();
        $repository->rows = $this->rows(['won']);
        $repository->fail = true;
        $clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z'));
        $service = new SimulationService($repository, new StrategySimulator(), $clock, new SimulationChart());
        try {
            $service->run($this->actor(), 'unit-key', $this->scope(), $this->config(), $clock->now());
            self::fail('Failure not propagated.');
        } catch (RuntimeException) {
            self::assertSame([], $repository->runs);
            self::assertSame([], $repository->audits);
        }
    }

    public function testCsrfAndEntitlementChecksHappenBeforeHistoryReads(): void
    {
        $repository = new SimulationTestRepository();
        $clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z'));
        $service = new SimulationService($repository, new StrategySimulator(), $clock, new SimulationChart());
        foreach ([false, true] as $csrf) {
            $repository->allow = false;
            try {
                $service->run($this->actor(1, $csrf), 'unit-key', $this->scope(), $this->config(), $clock->now());
                self::fail('Unauthorized access accepted.');
            } catch (RuntimeException) {
                self::assertSame(0, $repository->reads);
                self::assertSame([], $repository->runs);
            }
        }
    }

    public function testProductionAdapterCannotSubstituteDemoHistory(): void
    {
        $service = new SimulationService(
            new UnavailableSimulationRepository(),
            new StrategySimulator(),
            new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z')),
            new SimulationChart()
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Immutable published history');
        $service->run($this->actor(), 'unit-key', $this->scope(), $this->config(), new DateTimeImmutable('2026-11-06'));
    }

    public function testApprovedHigherCapsAndChartUseExactBoundedAccounting(): void
    {
        $rows = $this->rows(array_fill(0, 1000, 'won'));
        foreach ($rows as &$row) {
            $row['odds_decimal'] = '1000.000000';
            foreach (
                ['published_at', 'publication_available_at', 'kickoff_at', 'settled_at',
                'settlement_available_at', 'odds_observed_at', 'odds_available_at'] as $field
            ) {
                $row[$field] = (new DateTimeImmutable($row[$field]))->modify('-1 month')->format('Y-m-d\TH:i:s\Z');
            }
        }
        unset($row);
        $clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z'));
        foreach ([50, 120, 250, 1000] as $maximum) {
            $repository = new SimulationTestRepository();
            $repository->maximumOutcomes = $maximum;
            $repository->rows = array_slice($rows, 0, $maximum);
            $service = new SimulationService($repository, new StrategySimulator(), $clock, new SimulationChart());
            $config = new SimulationConfig(array_replace($this->config()->values, ['last_n' => $maximum,
                'bankroll_minor' => 1000000000, 'base_stake_minor' => 1000000000,
                'max_stake_minor' => 1000000000, 'max_loss_minor' => 1000000000]), $maximum);
            $report = $service->run($this->actor(), 'unit-large', $this->scope(), $config, $clock->now());
            self::assertSame($maximum, $report['replayed_count']);
            self::assertCount($maximum + 1, $report['chart']);
            self::assertStringContainsString('<svg', $report['chart_svg']);
        }
        self::assertSame('999001000000000', $report['ending_bankroll_minor']);
        $repository->maximumOutcomes = 50;
        $this->expectException(RuntimeException::class);
        $service->owned($this->actor(), $this->scope(), $report['simulation_run_id']);
    }

    public function testRequestCannotRaiseTheStoreCapOrBypassTheHistoryFloor(): void
    {
        $clock = new ModelHealthTestClock(new DateTimeImmutable('2026-11-06T00:00:00Z'));
        $repository = new SimulationTestRepository();
        $repository->maximumOutcomes = 50;
        $service = new SimulationService($repository, new StrategySimulator(), $clock, new SimulationChart());
        try {
            $config = new SimulationConfig(array_replace($this->config()->values, ['last_n' => 1000]), 1000);
            $service->run($this->actor(), 'unit-forged-cap', $this->scope(), $config, $clock->now());
            self::fail('Caller raised verified plan cap.');
        } catch (RuntimeException) {
            self::assertSame(0, $repository->reads);
        }
        $repository->rows = $this->rows(['won']);
        $repository->earliestKickoff = new DateTimeImmutable('2026-11-05T00:00:00Z');
        $this->expectException(RuntimeException::class);
        $service->run(
            $this->actor(),
            'unit-old-history',
            $this->scope(),
            new SimulationConfig(array_replace($this->config()->values, ['last_n' => 50]), 50),
            $clock->now()
        );
    }
}
