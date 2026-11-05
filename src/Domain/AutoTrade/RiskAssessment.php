<?php

declare(strict_types=1);

namespace App\Domain\AutoTrade;

/** Pure, conservative exposure check. This does not authorize or submit bets. */
final class RiskAssessment
{
    /**
     * Values are non-negative minor units in the same currency. The caller must
     * supply an authoritative, transactionally locked snapshot in production.
     */
    public function evaluate(
        int $stake,
        int $maxStake,
        int $realizedLoss,
        int $unsettledExposure,
        int $lossBudget,
        int $availableBalance
    ): array {
        if ($stake <= 0 || $maxStake <= 0 || $realizedLoss < 0 || $unsettledExposure < 0
            || $lossBudget <= 0 || $availableBalance < 0) {
            throw new \InvalidArgumentException('Invalid risk inputs.');
        }

        $reasons = [];
        if ($stake > $maxStake) {
            $reasons[] = 'max_stake';
        }
        if ($stake > $availableBalance) {
            $reasons[] = 'insufficient_balance';
        }
        // Subtraction avoids overflow from summing untrusted monetary values.
        if ($realizedLoss > $lossBudget
            || $unsettledExposure > $lossBudget - min($realizedLoss, $lossBudget)
            || $stake > $lossBudget - min($realizedLoss, $lossBudget)
                - min($unsettledExposure, $lossBudget - min($realizedLoss, $lossBudget))) {
            $reasons[] = 'loss_budget';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }
}
