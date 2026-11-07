<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clock;
use App\Contracts\SimulationRepository;
use App\Contracts\SimulationStore;
use App\Domain\ModelHealth\OperatorContext;
use App\Domain\Simulation\SimulationConfig;
use App\Domain\Simulation\SimulationScope;
use App\Domain\Simulation\StrategySimulator;
use App\Presenters\SimulationChart;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Server application boundary; no client-supplied history, identity, plan or authorization claims. */
final class SimulationService
{
    public function __construct(
        private readonly SimulationRepository $repository,
        private readonly StrategySimulator $simulator,
        private readonly Clock $clock,
        private readonly SimulationChart $chart
    ) {
    }

    public function run(
        OperatorContext $actor,
        string $key,
        SimulationScope $scope,
        SimulationConfig $config,
        DateTimeImmutable $asOf
    ): array {
        $now = $this->clock->now();
        $actor->authorize('simulations.create', $now);
        $asOf = $asOf->setTimezone(new DateTimeZone('UTC'));
        if (preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $key) !== 1 || $asOf > $now) {
            throw new InvalidArgumentException('Invalid idempotency key or future replay clock.');
        }
        $fingerprint = hash('sha256', json_encode([
            StrategySimulator::VERSION, $scope->fields(), $config->values, $asOf->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR));
        return $this->repository->transaction(function (SimulationStore $store) use (
            $actor,
            $key,
            $scope,
            $config,
            $asOf,
            $now,
            $fingerprint
        ): array {
            if (!$store->authorized($actor->userId, $scope, $now)) {
                throw new RuntimeException('Simulation entitlement or model access denied.');
            }
            $limits = $store->limits($actor->userId, $scope, $now);
            $limits->verify($config, []);
            $existing = $store->existing($actor->userId, $key);
            if ($existing !== null) {
                if (!hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Simulation idempotency key reused with different inputs.');
                }
                $limits->verify($config, $existing['report']['inclusion_ledger']);
                return $existing['report'];
            }
            $history = $store->history($scope, $asOf, $config->values['last_n']);
            $limits->verify($config, $history);
            $report = $this->simulator->replay($scope, $config, $history, $asOf);
            $report['chart_svg'] = $this->chart->render($report['chart']);
            $report['simulation_run_id'] = 'sim_' . bin2hex(random_bytes(16));
            $report['created_at'] = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            $store->append($actor->userId, $key, $fingerprint, $report);
            return $report;
        });
    }

    public function owned(OperatorContext $actor, SimulationScope $scope, string $runId): ?array
    {
        $now = $this->clock->now();
        $actor->authorize('simulations.read', $now);
        if (preg_match('/^sim_[a-f0-9]{32}$/D', $runId) !== 1) {
            throw new InvalidArgumentException('Invalid simulation run ID.');
        }
        return $this->repository->transaction(function (SimulationStore $store) use ($actor, $scope, $runId, $now) {
            if (!$store->authorized($actor->userId, $scope, $now)) {
                throw new RuntimeException('Simulation access denied.');
            }
            $report = $store->owned($actor->userId, $runId);
            if ($report !== null && $report['scope'] !== $scope->fields()) {
                throw new RuntimeException('Simulation scope access denied.');
            }
            if ($report !== null) {
                $limits = $store->limits($actor->userId, $scope, $now);
                $limits->verify(new SimulationConfig($report['config'], 1000), $report['inclusion_ledger']);
            }
            return $report;
        });
    }
}
