<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Contracts\SimulationRepository;
use App\Contracts\SimulationStore;
use App\Domain\Simulation\SimulationScope;
use App\Domain\Simulation\SimulationLimits;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** Unit-only storage, never bound in the web composition root. No production persistence claims. */
final class SimulationTestRepository implements SimulationRepository, SimulationStore
{
    public array $rows = [];
    public array $runs = [];
    public array $audits = [];
    public bool $allow = true;
    public bool $fail = false;
    public int $reads = 0;
    public int $maximumOutcomes = 100;
    public ?DateTimeImmutable $earliestKickoff = null;

    public function limits(int $ownerId, SimulationScope $scope, DateTimeImmutable $now): SimulationLimits
    {
        return new SimulationLimits($this->maximumOutcomes, $this->earliestKickoff);
    }

    public function transaction(callable $work): mixed
    {
        $before = [$this->runs, $this->audits];
        try {
            return $work($this);
        } catch (Throwable $error) {
            [$this->runs, $this->audits] = $before;
            throw $error;
        }
    }

    public function authorized(int $ownerId, SimulationScope $scope, DateTimeImmutable $now): bool
    {
        return $this->allow;
    }

    public function existing(int $ownerId, string $key): ?array
    {
        return $this->runs[$ownerId . ':' . $key] ?? null;
    }

    public function history(SimulationScope $scope, DateTimeImmutable $asOf, int $lastN): array
    {
        $this->reads++;
        return array_slice($this->rows, -$lastN);
    }

    public function append(int $ownerId, string $key, string $fingerprint, array $report): void
    {
        $this->runs[$ownerId . ':' . $key] = compact('ownerId', 'fingerprint', 'report');
        $this->audits[] = ['actor' => $ownerId, 'operation' => 'simulation.created',
            'run_id' => $report['simulation_run_id'], 'history_sha256' => $report['history_sha256']];
        if ($this->fail) {
            throw new RuntimeException('Unit storage failure.');
        }
    }

    public function owned(int $ownerId, string $runId): ?array
    {
        foreach ($this->runs as $run) {
            if ($run['ownerId'] === $ownerId && $run['report']['simulation_run_id'] === $runId) {
                return $run['report'];
            }
        }
        return null;
    }
}
