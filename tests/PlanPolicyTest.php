<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\Access\AccessException;
use App\Domain\Access\PlanPolicy;
use App\Domain\MarketCatalog;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanPolicyTest extends TestCase
{
    private function decision(
        string $plan,
        string $feature,
        array $models = [],
        array $markets = [],
        array $options = []
    ): array {
        return PlanPolicy::authorize(
            PlanPolicy::all()[$plan],
            $feature,
            $models,
            $markets,
            $options,
            new DateTimeImmutable('2026-11-06T12:00:00Z')
        );
    }

    public function testApprovedAllocationsAndFixedDurations(): void
    {
        $p = PlanPolicy::all();
        self::assertSame(['spe'], $p['trial']['models']);
        self::assertSame(['over_15', 'under_35'], $p['trial']['markets']);
        self::assertSame(['spe', 'mlpe'], $p['standard']['models']);
        self::assertSame(['over_15', 'under_35', 'over_25', 'btts_yes'], $p['standard']['markets']);
        foreach (['pro', 'max'] as $plan) {
            self::assertSame(['spe', 'mlpe', 'beve'], $p[$plan]['models']);
            self::assertSame(array_keys(MarketCatalog::all()), $p[$plan]['markets']);
            self::assertSame(2592000, $p[$plan]['validity_seconds']);
        }
        foreach ($p as $policy) {
            self::assertNull($policy['analyses_limit']);
            self::assertNull($policy['simulator_runs_limit']);
            self::assertFalse($policy['execution_enabled']);
        }
    }

    public static function denied(): array
    {
        return [
            ['trial', 'prediction.analyse', ['mlpe'], [], []],
            ['trial', 'prediction.analyse', ['spe'], ['under_15'], []],
            ['standard', 'prediction.analyse', ['beve'], [], []],
            ['standard', 'prediction.analyse', ['spe'], ['btts_no'], []],
            ['trial', 'comparison.basic', [], [], []],
            ['standard', 'comparison.full', [], [], []],
            ['standard', 'comparison.export', [], [], []],
            ['standard', 'comparison.basic', [], [], ['metrics' => ['model_agreement']]],
            ['standard', 'comparison.basic', [], [], ['from' => '2026-11-01T00:00:00Z']],
            ['trial', 'simulation.run', [], [], ['last_n' => 51]],
            ['standard', 'simulation.run', [], [], ['last_n' => 121]],
            ['pro', 'simulation.run', [], [], ['last_n' => 251]],
            ['max', 'simulation.run', [], [], ['last_n' => 1001]],
            ['pro', 'auto_trade.execute', [], [], []],
            ['max', 'trading.session', [], [], []],
            ['trial', 'csv.export', [], [], []],
            ['trial', 'strategy.save', [], [], []],
            ['standard', 'advanced.analytics', [], [], []],
            ['max', 'unknown.feature', [], [], []],
            ['trial', 'prediction.history', [], [], ['from' => '2026-11-01T00:00:00Z']],
        ];
    }

    #[DataProvider('denied')]
    public function testModelMarketFeatureAndRangeRestrictions(
        string $plan,
        string $feature,
        array $models,
        array $markets,
        array $options
    ): void {
        $this->expectException(AccessException::class);
        $this->decision($plan, $feature, $models, $markets, $options);
    }

    public function testComparisonVersusPredictionHistoryAndMetricGates(): void
    {
        $basic = $this->decision('standard', 'comparison.basic');
        self::assertSame('2026-10-23T12:00:00.000000Z', $basic['from']);
        self::assertContains('realized_roi', $basic['metrics']);
        self::assertContains('calibration', $basic['metrics']);
        self::assertNotContains('model_agreement', $basic['metrics']);
        $full = $this->decision('pro', 'comparison.full');
        self::assertNull($full['from']);
        self::assertContains('model_agreement', $full['metrics']);
        $history = $this->decision('pro', 'prediction.history');
        self::assertSame('2026-08-08T12:00:00.000000Z', $history['from']);
        self::assertTrue($full['requires_settled_publications']);
        self::assertTrue($full['requires_sample_sizes']);
        self::assertFalse($this->decision('pro', 'prediction.analyse')['requires_settled_publications']);
    }

    public function testWATMidnightBoundaryUsesUtcAndNoRollover(): void
    {
        foreach (['2026-11-05T23:00:00Z', '2026-11-06T22:59:59Z'] as $clock) {
            self::assertSame(
                '2026-11-05T23:00:00+00:00',
                PlanPolicy::dailyAnchor(new DateTimeImmutable($clock))->format(DATE_ATOM)
            );
        }
        self::assertSame(
            '2026-11-06T23:00:00+00:00',
            PlanPolicy::dailyAnchor(new DateTimeImmutable('2026-11-06T23:00:00Z'))->format(DATE_ATOM)
        );
        self::assertFalse(PlanPolicy::all()['max']['rollover']);
    }

    public function testSnapshotVerificationDoesNotCoerceStringsOrEnableTrading(): void
    {
        $p = PlanPolicy::all()['max'];
        $p['simulation_outcomes'] = '1000';
        try {
            PlanPolicy::verify($p);
            self::fail('String count accepted.');
        } catch (AccessException $error) {
            self::assertSame('policy_unavailable', $error->errorCode);
        }
        $p = PlanPolicy::all()['max'];
        $p['execution_enabled'] = true;
        $this->expectException(AccessException::class);
        PlanPolicy::verify($p);
    }
}
