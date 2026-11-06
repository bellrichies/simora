<?php

declare(strict_types=1);

namespace App\Domain\ModelComparison;

use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\MarketCatalog;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ChampionPolicy
{
    public function __construct(
        public readonly string $version,
        public readonly string $experimentId,
        public readonly int $minimumFixtures,
        public readonly int $minimumClusters,
        public readonly int $maximumReportAgeSeconds,
        public readonly int $maximumDecisionSeconds,
        public readonly float $familyAlpha,
        public readonly int $familyHypotheses,
        public readonly int $minimumTailReplicates,
        public readonly float $minimumBrierImprovement,
        public readonly float $minimumLogLossImprovement
    ) {
        if (
            $version === '' || $experimentId === '' || $minimumFixtures < 1 || $minimumClusters < 2
            || $maximumReportAgeSeconds < 1 || $maximumDecisionSeconds < 1 || $familyHypotheses < 1
            || $minimumTailReplicates < 1 || !is_finite($familyAlpha) || $familyAlpha <= 0 || $familyAlpha >= 0.5
            || !is_finite($minimumBrierImprovement) || $minimumBrierImprovement < 0
            || !is_finite($minimumLogLossImprovement) || $minimumLogLossImprovement < 0
        ) {
            throw new InvalidArgumentException('An explicit preapproved champion policy is required.');
        }
    }

    public function digest(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }

    public function evidenceFresh(array $report, DateTimeImmutable $at): bool
    {
        $created = $this->timestamp($report['created_at'] ?? null);
        $evaluated = $this->timestamp($report['evaluation_as_of'] ?? null);
        return $created <= $at && $evaluated <= $created
            && $evaluated->modify('+' . $this->maximumReportAgeSeconds . ' seconds') > $at;
    }

    /** Strict paired dominance of every baseline and alternative; selected ROI is never read. */
    public function choose(array $report, DeploymentScope $scope, DateTimeImmutable $now): array
    {
        if (
            $scope->platform === null || $scope->league === null || $scope->market === null
            || ($report['partition'] ?? null) !== $scope->partition
            || ($report['platform'] ?? null) !== $scope->platform || ($report['league'] ?? null) !== $scope->league
            || ($report['policy_version'] ?? null) !== $this->version
            || ($report['experiment_id'] ?? null) !== $this->experimentId
        ) {
            throw new RuntimeException('Champion report scope/policy/experiment mismatch.');
        }
        if (!$this->evidenceFresh($report, $now)) {
            return [null, 'stale_or_future_report'];
        }
        if (($report['release_eligible'] ?? null) !== true) {
            return [null, 'report_not_release_eligible'];
        }
        $models = $report['model_versions'] ?? [];
        $baselines = $report['baseline_versions'] ?? [];
        if (!$this->uniqueIds($models) || !$this->uniqueIds($baselines) || array_intersect($models, $baselines)) {
            throw new RuntimeException('Invalid frozen comparison version set.');
        }
        $hypotheses = 8 * 2 * count($models) * (count($models) + count($baselines) - 1);
        $bootstrap = $report['bootstrap'] ?? [];
        if (
            $this->familyHypotheses < $hypotheses
            || ($bootstrap['method'] ?? null) !== 'whole_virtual_round_percentile'
            || ($bootstrap['paired_skill_draws'] ?? null) !== true
            || !is_numeric($bootstrap['level'] ?? null)
            || $bootstrap['level'] < 1 - $this->familyAlpha / $this->familyHypotheses
            || $bootstrap['level'] >= 1 || !is_int($bootstrap['resamples'] ?? null)
            || $bootstrap['resamples'] * (1 - $bootstrap['level']) / 2 < $this->minimumTailReplicates
        ) {
            return [null, 'uncertainty_or_multiplicity_insufficient'];
        }
        $skill = $report['skill'] ?? [];
        if (
            !is_int($skill['count'] ?? null) || !is_int($skill['round_clusters'] ?? null)
            || $skill['count'] < $this->minimumFixtures || $skill['round_clusters'] < $this->minimumClusters
        ) {
            return [null, 'insufficient_evidence'];
        }
        if (
            !$this->uniqueIds($skill['fixture_ids'] ?? null)
            || count($skill['fixture_ids']) !== $skill['count'] || $skill['round_clusters'] > $skill['count']
        ) {
            throw new RuntimeException('Invalid common-fixture evidence denominator.');
        }
        foreach (array_merge($models, $baselines) as $version) {
            $markets = $skill['models'][$version] ?? [];
            if (array_diff_key(MarketCatalog::all(), $markets) || array_diff_key($markets, MarketCatalog::all())) {
                throw new RuntimeException('Eight canonical comparison markets are required.');
            }
            foreach ($markets as $metrics) {
                if (($metrics['count'] ?? null) !== $skill['count']) {
                    throw new RuntimeException('Model comparison denominators differ.');
                }
                foreach (['brier', 'log_loss'] as $metric) {
                    $value = $metrics[$metric] ?? null;
                    if (
                        (!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0
                        || ($metric === 'brier' && $value > 1)
                    ) {
                        throw new RuntimeException('Invalid model comparison metric.');
                    }
                }
            }
        }
        $winners = [];
        foreach ($models as $model) {
            $dominates = true;
            foreach (array_merge($models, $baselines) as $other) {
                if ($other === $model) {
                    continue;
                }
                foreach (
                    ['brier' => $this->minimumBrierImprovement,
                    'log_loss' => $this->minimumLogLossImprovement] as $metric => $margin
                ) {
                    $delta = $skill['paired_deltas'][$model][$other][$scope->market][$metric] ?? [];
                    $ci = $delta['ci'] ?? [];
                    $lower = $ci['lower'] ?? null;
                    $upper = $ci['upper'] ?? null;
                    if (
                        (!is_float($lower) && !is_int($lower)) || (!is_float($upper) && !is_int($upper))
                        || !is_finite((float) $lower) || !is_finite((float) $upper) || $lower > $upper
                        || ($ci['reason'] ?? null) !== null || $upper >= -$margin
                    ) {
                        $dominates = false;
                    }
                }
            }
            if ($dominates) {
                $winners[] = $model;
            }
        }
        return count($winners) === 1 ? [$winners[0], 'paired_skill_dominance']
            : [null, 'insufficient_evidence'];
    }

    private function uniqueIds(mixed $ids): bool
    {
        return is_array($ids) && array_is_list($ids) && $ids !== []
            && count(array_unique($ids, SORT_REGULAR)) === count($ids)
            && array_filter($ids, static fn ($x) => !is_string($x) || $x === '') === [];
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new RuntimeException('Explicit report timezone required.');
        }
        return new DateTimeImmutable($value);
    }
}
