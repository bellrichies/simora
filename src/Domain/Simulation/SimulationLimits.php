<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Domain\Access\AccessException;

/** Must be resolved from current server-side plan entitlements, never a request plan/access flag. */
final class SimulationLimits
{
    public function __construct(
        public readonly int $maximumOutcomes,
        public readonly ?DateTimeImmutable $earliestKickoff = null
    ) {
        if ($maximumOutcomes < 1 || $maximumOutcomes > 1000) {
            throw new InvalidArgumentException('Invalid verified simulation limit.');
        }
    }

    public function verify(SimulationConfig $config, array $history): void
    {
        if ($config->values['last_n'] > $this->maximumOutcomes) {
            throw new AccessException('simulation_limit_exceeded', 403);
        }
        foreach ($history as $row) {
            if ($this->earliestKickoff !== null && new DateTimeImmutable($row['kickoff_at']) < $this->earliestKickoff) {
                throw new AccessException('history_window_denied', 403);
            }
        }
    }
}
