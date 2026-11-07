<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\MarketCatalog;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Approved product policy. Permission never implies source/model release or executable trading. */
final class PlanPolicy
{
    public const VERSION = 'simora.access.v1';
    public const APPROVAL = 'user-approved:simora-access-v1:2026-11-06';

    public static function all(): array
    {
        $baseMetrics = ['calibrated_probability', 'historical_win_rate', 'outcomes', 'average_odds',
            'realized_roi', 'net_profit', 'equity_chart', 'max_drawdown', 'streaks', 'calibration', 'market_ranking'];
        $rows = [];
        foreach (
            [
            'trial' => [200000, 86400, ['spe'], ['over_15', 'under_35'], 3, 50, 0, 0, 'none', false, 0, 0],
            'standard' => [3000000, 1209600, ['spe', 'mlpe'],
                ['over_15', 'under_35', 'over_25', 'btts_yes'], 14, 120, 3, 2, 'basic', false, 0, 0],
            'pro' => [5000000, 2592000, ['spe', 'mlpe', 'beve'], array_keys(MarketCatalog::all()),
                90, 250, null, 10, 'full', true, 2, 1],
            'max' => [8000000, 2592000, ['spe', 'mlpe', 'beve'], array_keys(MarketCatalog::all()),
                null, 1000, null, 50, 'full', true, 5, 2],
            ] as $code => $v
        ) {
            $rows[$code] = ['schema_version' => self::VERSION, 'code' => $code, 'amount_minor' => $v[0],
                'currency' => 'NGN', 'validity_seconds' => $v[1], 'models' => $v[2], 'markets' => $v[3],
                'analyses_limit' => null, 'simulator_runs_limit' => null, 'history_days' => $v[4],
                'simulation_outcomes' => $v[5], 'saved_strategies_limit' => $v[6], 'csv_exports_per_day' => $v[7],
                'comparison_tier' => $v[8], 'comparison_history_days' => $v[8] === 'basic' ? 14 : null,
                'comparison_metrics' => $v[8] === 'none' ? [] : $baseMetrics,
                'comparison_agreement' => $v[8] === 'full', 'comparison_custom_dates' => $v[8] === 'full',
                'comparison_export' => $v[8] === 'full', 'advanced_analytics' => $v[9],
                'automation_configuration_limit' => $v[10], 'future_trading_sessions_limit' => $v[11],
                'execution_enabled' => false, 'daily_reset_timezone' => 'Africa/Lagos', 'rollover' => false,
                'trial_once_verified' => $code === 'trial'];
        }
        return $rows;
    }

    public static function verify(array $snapshot): array
    {
        $code = $snapshot['code'] ?? null;
        $approved = is_string($code) ? (self::all()[$code] ?? null) : null;
        // v1 is frozen. Later approved admin changes need a new policy version, never a mutable old snapshot.
        if ($approved === null || self::canonical($snapshot) !== self::canonical($approved)) {
            throw new AccessException('policy_unavailable', 503);
        }
        return $approved;
    }

    private static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    public static function authorize(
        array $policy,
        string $feature,
        array $models,
        array $markets,
        array $options,
        DateTimeImmutable $now
    ): array {
        $p = self::verify($policy);
        foreach ([$models, $markets] as $ids) {
            if (!array_is_list($ids) || array_filter($ids, static fn ($id): bool => !is_string($id))) {
                throw new InvalidArgumentException('Model and market scopes must be string lists.');
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new InvalidArgumentException('Duplicate scope identifier.');
            }
        }
        if (array_diff($models, $p['models']) || array_diff($markets, $p['markets'])) {
            throw new AccessException('model_or_market_denied', 403);
        }
        $allowed = match ($feature) {
            'prediction.analyse', 'prediction.history', 'model.catalog', 'model.preferences', 'simulation.run' => true,
            'strategy.save' => $p['saved_strategies_limit'] !== 0,
            'csv.export' => $p['csv_exports_per_day'] > 0,
            'comparison.read' => $p['comparison_tier'] !== 'none',
            'comparison.basic' => $p['comparison_tier'] === 'basic',
            'comparison.full' => $p['comparison_tier'] === 'full',
            'comparison.export' => $p['comparison_export'],
            'advanced.analytics' => $p['advanced_analytics'],
            'automation.configure' => $p['automation_configuration_limit'] > 0,
            default => false, // Includes every executable trading/session action and unknown features.
        };
        if (!$allowed) {
            throw new AccessException('feature_denied', 403);
        }
        if (array_diff(array_keys($options), ['from', 'to', 'last_n', 'metrics'])) {
            throw new InvalidArgumentException('Unknown policy request option.');
        }
        $comparison = str_starts_with($feature, 'comparison.');
        if ($comparison && !$p['comparison_custom_dates'] && (isset($options['from']) || isset($options['to']))) {
            throw new AccessException('custom_dates_denied', 403);
        }
        $historical = $comparison || in_array(
            $feature,
            ['prediction.history', 'simulation.run', 'csv.export', 'advanced.analytics'],
            true
        );
        $days = $historical ? ($comparison ? $p['comparison_history_days'] : $p['history_days']) : null;
        $earliest = $days === null ? null : $now->modify('-' . $days . ' days');
        $from = isset($options['from']) ? self::clock($options['from']) : $earliest;
        $to = isset($options['to']) ? self::clock($options['to']) : $now;
        if (
            ($historical && $to > $now)
            || ($from !== null && ($from > $to || ($earliest !== null && $from < $earliest)))
        ) {
            throw new AccessException('history_window_denied', 403);
        }
        $lastN = $options['last_n'] ?? min(100, $p['simulation_outcomes']);
        if (!is_int($lastN) || $lastN < 1 || $lastN > $p['simulation_outcomes']) {
            throw new AccessException('simulation_limit_exceeded', 403);
        }
        $metrics = $p['comparison_metrics'];
        if ($p['comparison_agreement']) {
            $metrics[] = 'model_agreement';
        }
        if (isset($options['metrics'])) {
            if (
                !is_array($options['metrics']) || !array_is_list($options['metrics'])
                || array_filter($options['metrics'], static fn ($v) => !is_string($v))
            ) {
                throw new InvalidArgumentException('Invalid comparison metric list.');
            }
            if (!$comparison || array_diff($options['metrics'], $metrics)) {
                throw new AccessException('comparison_metric_denied', 403);
            }
            $metrics = $options['metrics'];
        }
        $utc = static fn (?DateTimeImmutable $date): ?string =>
            $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        return ['policy_version' => self::VERSION, 'plan_code' => $p['code'], 'feature' => $feature,
            'models' => $models ?: $p['models'], 'markets' => $markets ?: $p['markets'],
            'from' => $utc($from), 'to' => $utc($to), 'last_n' => $lastN,
            'simulation_outcomes_limit' => $p['simulation_outcomes'], 'metrics' => $metrics,
            'saved_strategies_limit' => $p['saved_strategies_limit'],
            'automation_configuration_limit' => $p['automation_configuration_limit'], 'execution_enabled' => false,
            'requires_settled_publications' => $historical, 'requires_sample_sizes' => true];
    }

    public static function dailyAnchor(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('Africa/Lagos'))->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private static function clock(mixed $input): DateTimeImmutable
    {
        $date = is_string($input) ? DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $input,
            new DateTimeZone('UTC')
        ) : false;
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $input) {
            throw new InvalidArgumentException('Policy dates must be valid UTC timestamps.');
        }
        return $date;
    }
}
