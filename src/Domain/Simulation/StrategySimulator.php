<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

/** Pure hypothetical replay. Inputs must come from an authorized immutable publication snapshot. */
final class StrategySimulator
{
    public const VERSION = 'simora.strategy.v1';

    public function replay(
        SimulationScope $scope,
        SimulationConfig $config,
        array $history,
        DateTimeImmutable $asOf
    ): array {
        if (PHP_INT_SIZE !== 8 || count($history) > $config->values['last_n']) {
            throw new InvalidArgumentException('Unsupported runtime or unbounded history snapshot.');
        }
        $this->validateHistory($scope, $history, $asOf);
        $c = $config->values;
        $bankroll = $peak = $trough = $c['bankroll_minor'];
        $totalStaked = $payouts = $fees = $maxDrawdown = $maxStake = $streak = $maxStreak = $cycleNet = 0;
        $ledger = $refusals = $matches = [];
        $chart = [['ordinal' => 0, 'bankroll_minor' => (string) $bankroll, 'net_minor' => '0']];
        $stop = null;
        $availableAfter = null;
        foreach ($history as $row) {
            $reason = null;
            if (isset($matches[$row['match_id']])) {
                $reason = 'correlated_exposure';
            } elseif (
                $availableAfter !== null && new DateTimeImmutable($row['publication_available_at']) <= $availableAfter
            ) {
                $reason = 'overlapping_exposure';
            } elseif (!in_array($row['outcome'], ['won', 'lost', 'void'], true)) {
                $reason = 'unresolved_settlement';
            } elseif (
                !is_string($row['quote_id'] ?? null) || $row['quote_id'] === ''
                || !is_string($row['odds_observed_at'] ?? null) || !is_string($row['odds_available_at'] ?? null)
            ) {
                $reason = 'missing_quote_provenance';
            } elseif (
                ($row['fee_basis'] ?? null) !== 'positive_profit'
                || !is_string($row['fee_policy_id'] ?? null) || $row['fee_policy_id'] === ''
                || ($row['outcome'] === 'void' && ($row['void_policy'] ?? null) !== 'refund_stake_no_fee')
            ) {
                $reason = 'unsupported_settlement_contract';
            }
            $price = $this->price($row['odds_decimal'] ?? null);
            $rate = $this->feeRate($row['fee_rate_decimal'] ?? null);
            $attempt = $this->attempt($c, $bankroll, $cycleNet, $streak, $price, $rate);
            $reason ??= $attempt['reason'];
            if ($reason !== null) {
                $stop = $reason;
                $refusals[] = ['publication_id' => $row['publication_id'],
                    'attempted_stake_minor' => $attempt['stake_minor'], 'reason' => $reason,
                    'bankroll_minor' => (string) $bankroll];
                break;
            }
            $stake = (int) $attempt['stake_minor'];
            $profit = $row['outcome'] === 'won'
                ? FixedPoint::round(FixedPoint::multiply($stake, $price - FixedPoint::SCALE), FixedPoint::SCALE) : 0;
            $fee = FixedPoint::round(FixedPoint::multiply($profit, $rate), FixedPoint::SCALE);
            $payout = $row['outcome'] === 'lost' ? 0 : FixedPoint::add($stake, $profit);
            $net = $payout - $stake - $fee;
            $before = $bankroll;
            $bankroll = FixedPoint::add($bankroll, $net);
            $cycleNet = FixedPoint::add($cycleNet, $net);
            if ($c['strategy'] === 'recovery' && $cycleNet >= $c['extra_target_minor']) {
                $cycleNet = 0; // Explicit cycle target reached, after fees; no reset merely because one pick won.
            }
            $streak = match ($row['outcome']) {
                'lost' => $streak + 1,
                'won' => 0,
                default => $streak,
            };
            $maxStreak = max($maxStreak, $streak);
            $peak = max($peak, $bankroll);
            $trough = min($trough, $bankroll);
            $maxDrawdown = max($maxDrawdown, $peak - $bankroll);
            $maxStake = max($maxStake, $stake);
            $totalStaked = FixedPoint::add($totalStaked, $stake);
            $payouts = FixedPoint::add($payouts, $payout);
            $fees = FixedPoint::add($fees, $fee);
            $matches[$row['match_id']] = true;
            $availableAfter = new DateTimeImmutable($row['settlement_available_at']);
            $ledger[] = $row + ['ordinal' => count($ledger) + 1,
                'stake_minor' => (string) $stake, 'gross_payout_minor' => (string) $payout,
                'fee_minor' => (string) $fee, 'net_minor' => (string) $net,
                'bankroll_before_minor' => (string) $before, 'bankroll_after_minor' => (string) $bankroll];
            $chart[] = ['ordinal' => count($ledger), 'bankroll_minor' => (string) $bankroll,
                'net_minor' => (string) ($bankroll - $c['bankroll_minor'])];
        }
        // Future fees/source are unknown. A next stake is a conditional scenario, never an executable order.
        $next = $this->attempt(
            $c,
            $bankroll,
            $cycleNet,
            $streak,
            $this->price($c['next_odds_decimal']),
            $this->feeRate($c['next_fee_rate_decimal']),
            false
        );
        if ($stop !== null) {
            $next['reason'] = 'replay_stopped:' . $stop;
        }
        $next['allowed'] = $next['reason'] === null;
        $next['odds_decimal'] = $c['next_odds_decimal'];
        $next['fee_rate_decimal'] = $c['next_fee_rate_decimal'];
        $net = $bankroll - $c['bankroll_minor'];
        return ['schema_version' => self::VERSION, 'scope' => $scope->fields(),
            'as_of' => $asOf->format('Y-m-d\TH:i:s.uP'), 'config' => $c,
            'history_sha256' => hash('sha256', json_encode($history, JSON_THROW_ON_ERROR)),
            'inclusion_ledger' => $history,
            'ledger' => $ledger, 'refused_attempts' => $refusals, 'chart' => $chart,
            'total_staked_minor' => (string) $totalStaked, 'gross_payouts_minor' => (string) $payouts,
            'fees_minor' => (string) $fees, 'net_minor' => (string) $net,
            'roi_ratio' => FixedPoint::ratio($net, $totalStaked),
            'ending_bankroll_minor' => (string) $bankroll, 'peak_bankroll_minor' => (string) $peak,
            'ruined' => $bankroll === 0,
            'trough_bankroll_minor' => (string) $trough, 'max_drawdown_minor' => (string) $maxDrawdown,
            'max_stake_minor' => (string) $maxStake, 'max_loss_streak' => $maxStreak,
            'current_loss_streak' => $streak, 'guardrail_breach' => $stop,
            'included_count' => count($history), 'replayed_count' => count($ledger),
            'terminated_early' => count($ledger) < count($history), 'next_hypothetical' => $next,
            'warnings' => ['Hypothetical replay; no wagers or recovery guarantee.',
                'Overlapping or correlated exposures stop this single-position policy.',
                'Next stake uses supplied hypothetical inputs; no source/model eligibility implied.']];
    }

    private function attempt(
        array $c,
        int $bankroll,
        int $cycleNet,
        int $streak,
        ?int $price,
        ?int $rate,
        bool $requireSettlement = true
    ): array {
        $stake = null;
        $reason = null;
        try {
            if (($requireSettlement || $c['strategy'] === 'recovery') && ($price === null || $rate === null)) {
                $reason = $price === null ? 'missing_or_invalid_odds' : 'missing_or_invalid_fee';
            } else {
                $stake = $c['base_stake_minor'];
                if ($c['strategy'] === 'martingale') {
                    for ($step = 0; $step < $streak; $step++) {
                        $stake = FixedPoint::multiply($stake, $c['multiplier']);
                    }
                } elseif ($c['strategy'] === 'recovery') {
                    $target = FixedPoint::add(max(0, -$cycleNet), $c['extra_target_minor']);
                    if ($target > 0) {
                        if ($rate === FixedPoint::SCALE) {
                            return ['stake_minor' => null, 'reason' => 'no_after_fee_return'];
                        }
                        // Conservative verified-fee extension: ceil gross target before solving stake.
                        $grossTarget = FixedPoint::ceil(
                            FixedPoint::multiply($target, FixedPoint::SCALE),
                            FixedPoint::SCALE - $rate
                        );
                        $stake = FixedPoint::ceil(
                            FixedPoint::multiply($grossTarget, FixedPoint::SCALE),
                            $price - FixedPoint::SCALE
                        );
                    }
                }
                $stake = FixedPoint::multiply(FixedPoint::ceil($stake, $c['increment_minor']), $c['increment_minor']);
                $net = $bankroll - $c['bankroll_minor'];
                $reason = match (true) {
                    $c['strategy'] === 'martingale' && $streak >= $c['max_steps'] => 'max_steps',
                    $stake > $c['max_stake_minor'] => 'max_stake',
                    $stake > $bankroll => 'insufficient_bankroll',
                    $net - $stake < -$c['max_loss_minor'] => 'max_loss',
                    default => null,
                };
            }
        } catch (OverflowException) {
            $stake = null;
            $reason = 'arithmetic_overflow';
        }
        return ['stake_minor' => $stake === null ? null : (string) $stake, 'reason' => $reason];
    }

    private function price(mixed $value): ?int
    {
        try {
            $scaled = is_string($value) ? FixedPoint::decimal($value) : 0;
            return $scaled > FixedPoint::SCALE ? $scaled : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function feeRate(mixed $value): ?int
    {
        try {
            return is_string($value) ? FixedPoint::decimal($value, FixedPoint::SCALE) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function validateHistory(SimulationScope $scope, array $history, DateTimeImmutable $asOf): void
    {
        $ids = [];
        $previous = null;
        foreach ($history as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid history row.');
            }
            foreach ($scope->fields() as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    throw new InvalidArgumentException('History must match one model/version/market/source scope.');
                }
            }
            foreach (['publication_id', 'settlement_revision_id', 'match_id', 'outcome'] as $key) {
                if (!is_string($row[$key] ?? null) || $row[$key] === '' || strlen($row[$key]) > 128) {
                    throw new InvalidArgumentException('Invalid immutable publication/settlement identity.');
                }
            }
            $times = [];
            foreach (
                ['published_at', 'publication_available_at', 'kickoff_at', 'settled_at',
                'settlement_available_at', 'odds_observed_at', 'odds_available_at'] as $key
            ) {
                $value = $row[$key] ?? null;
                if (in_array($key, ['odds_observed_at', 'odds_available_at'], true) && $value === null) {
                    continue; // Missing quote provenance is an auditable refused attempt, not a fabricated quote clock.
                }
                if (
                    !is_string($value)
                    || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $value) !== 1
                ) {
                    throw new InvalidArgumentException('History timestamps must be explicit UTC.');
                }
                try {
                    $time = new DateTimeImmutable($value);
                } catch (\Exception $exception) {
                    throw new InvalidArgumentException('Invalid calendar timestamp.', 0, $exception);
                }
                if ($time->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                    throw new InvalidArgumentException('Invalid calendar timestamp.');
                }
                $times[$key] = $time;
            }
            $order = [$times['kickoff_at'], $row['publication_id']];
            if (
                isset($ids[$row['publication_id']]) || ($previous !== null && $order <= $previous)
                || $times['published_at'] > $times['publication_available_at']
                || $times['publication_available_at'] >= $times['kickoff_at']
                || (isset($times['odds_observed_at'], $times['odds_available_at'])
                    && $times['odds_observed_at'] > $times['odds_available_at'])
                || (isset($times['odds_available_at']) && $times['odds_available_at'] > $times['published_at'])
                || $times['settled_at'] < $times['kickoff_at']
                || $times['settlement_available_at'] < $times['settled_at']
                || $times['settlement_available_at'] > $asOf
            ) {
                throw new InvalidArgumentException('Duplicate, unordered or non-point-in-time publication history.');
            }
            // Client-controlled derived amounts must never override the computed ledger.
            if (
                array_intersect(array_keys($row), ['ordinal', 'stake_minor', 'gross_payout_minor', 'fee_minor',
                'net_minor', 'bankroll_before_minor', 'bankroll_after_minor'])
            ) {
                throw new InvalidArgumentException('History contains derived simulation amounts.');
            }
            $ids[$row['publication_id']] = true;
            $previous = $order;
        }
    }
}
