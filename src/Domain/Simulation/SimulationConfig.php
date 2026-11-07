<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

use InvalidArgumentException;

/** simora.strategy.v1: bounded NGN-minor-unit policy, integer Martingale multiplier. */
final class SimulationConfig
{
    public readonly array $values;

    public function __construct(array $input, int $verifiedMaximumOutcomes = 100)
    {
        if ($verifiedMaximumOutcomes < 1 || $verifiedMaximumOutcomes > 1000) {
            throw new InvalidArgumentException('Invalid server simulation bound.');
        }
        $defaults = ['last_n' => min(100, $verifiedMaximumOutcomes), 'increment_minor' => 1, 'multiplier' => 2,
            'max_steps' => 10, 'extra_target_minor' => 0, 'next_odds_decimal' => null,
            'next_fee_rate_decimal' => null];
        $required = ['strategy', 'bankroll_minor', 'base_stake_minor', 'max_stake_minor', 'max_loss_minor'];
        if (
            array_diff($required, array_keys($input))
            || array_diff(array_keys($input), [...$required, ...array_keys($defaults)])
        ) {
            throw new InvalidArgumentException('Missing or unknown simulation configuration fields.');
        }
        $values = $input + $defaults;
        if (!in_array($values['strategy'], ['flat', 'martingale', 'recovery'], true)) {
            throw new InvalidArgumentException('Invalid strategy.');
        }
        $moneyKeys = ['bankroll_minor', 'base_stake_minor', 'max_stake_minor', 'max_loss_minor', 'increment_minor'];
        foreach ($moneyKeys as $key) {
            if (!is_int($values[$key]) || $values[$key] < 1 || $values[$key] > FixedPoint::MONEY_LIMIT) {
                throw new InvalidArgumentException($key . ' must be bounded integer minor units.');
            }
        }
        foreach (['last_n' => $verifiedMaximumOutcomes, 'multiplier' => 1000, 'max_steps' => 100] as $key => $maximum) {
            if (!is_int($values[$key]) || $values[$key] < 1 || $values[$key] > $maximum) {
                throw new InvalidArgumentException('Invalid bounded ' . $key . '.');
            }
        }
        if ($values['strategy'] === 'martingale' && $values['multiplier'] < 2) {
            throw new InvalidArgumentException('Martingale multiplier must be an integer >= 2.');
        }
        if (
            !is_int($values['extra_target_minor']) || $values['extra_target_minor'] < 0
            || $values['extra_target_minor'] > FixedPoint::MONEY_LIMIT
        ) {
            throw new InvalidArgumentException('Invalid recovery target.');
        }
        if ($values['next_odds_decimal'] !== null) {
            if (!is_string($values['next_odds_decimal'])) {
                throw new InvalidArgumentException('Next odds must be a decimal string.');
            }
            if (FixedPoint::decimal($values['next_odds_decimal']) <= FixedPoint::SCALE) {
                throw new InvalidArgumentException('Next odds must exceed one.');
            }
        }
        if ($values['next_fee_rate_decimal'] !== null) {
            if (!is_string($values['next_fee_rate_decimal'])) {
                throw new InvalidArgumentException('Next fee rate must be a decimal string.');
            }
            FixedPoint::decimal($values['next_fee_rate_decimal'], FixedPoint::SCALE);
        }
        ksort($values);
        $this->values = $values;
    }
}
