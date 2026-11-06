<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use DateTimeImmutable;
use InvalidArgumentException;

/** Explicit operator-approved thresholds; no production defaults are invented. */
final class HealthPolicy
{
    public const METRICS = [
        'source_lag_seconds', 'queue_age_seconds', 'calibration_error', 'brier_degradation',
        'missing_forecast_ratio', 'no_selection_ratio', 'selection_drift', 'odds_drift',
    ];

    public function __construct(
        public readonly string $version,
        public readonly array $maximums,
        public readonly array $referenceCounts,
        public readonly string $featureSchemaSha256,
        public readonly DateTimeImmutable $referenceAsOf,
        public readonly float $maximumFeatureDivergence,
        public readonly int $minimumSamples,
        public readonly int $maximumAgeSeconds
    ) {
        $keys = array_keys($maximums);
        sort($keys);
        $required = self::METRICS;
        sort($required);
        if (
            $version === '' || $keys !== $required || $minimumSamples < 1 || $maximumAgeSeconds < 1
            || !is_finite($maximumFeatureDivergence) || $maximumFeatureDivergence < 0
            || $maximumFeatureDivergence > log(2) || $referenceCounts === []
            || preg_match('/^[a-f0-9]{64}$/D', $featureSchemaSha256) !== 1
        ) {
            throw new InvalidArgumentException('Incomplete health policy.');
        }
        foreach ($maximums as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
                throw new InvalidArgumentException('Invalid health threshold.');
            }
        }
        foreach ($referenceCounts as $feature => $counts) {
            if (!is_string($feature) || $feature === '' || !$this->validCounts($counts)) {
                throw new InvalidArgumentException('Invalid frozen feature reference.');
            }
        }
    }

    public function digest(): string
    {
        $data = get_object_vars($this);
        $data['referenceAsOf'] = $this->referenceAsOf->format('U.u');
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** Natural-log Jensen-Shannon divergence on the same immutable feature bins. */
    public function divergence(array $reference, array $current): float
    {
        if (
            !$this->validCounts($reference) || !$this->validCounts($current)
            || count($reference) !== count($current)
        ) {
            throw new InvalidArgumentException('Missing, empty or incompatible feature bins.');
        }
        $referenceTotal = array_sum($reference);
        $currentTotal = array_sum($current);
        $value = 0.0;
        foreach ($reference as $index => $count) {
            $p = $count / $referenceTotal;
            $q = $current[$index] / $currentTotal;
            $m = ($p + $q) / 2;
            $value += $p > 0 ? $p * log($p / $m) / 2 : 0;
            $value += $q > 0 ? $q * log($q / $m) / 2 : 0;
        }
        return max(0.0, $value);
    }

    /** @return list<string> Every returned reason is a critical fail-closed alarm. */
    public function failures(
        ?HealthObservation $sample,
        string $versionId,
        string $artifactSha256,
        DeploymentScope $scope,
        DateTimeImmutable $now
    ): array {
        if ($sample === null) {
            return ['health_missing'];
        }
        $reasons = [];
        if ($sample->versionId !== $versionId || $sample->scope->key() !== $scope->key()) {
            $reasons[] = 'health_scope_mismatch';
        }
        if (
            $sample->observedAt > $now || $sample->availableAt > $now || $sample->ingestedAt > $now
            || $sample->observedAt->modify('+' . $this->maximumAgeSeconds . ' seconds') <= $now
            || $sample->windowEnd->modify('+' . $this->maximumAgeSeconds . ' seconds') <= $now
        ) {
            $reasons[] = 'health_stale_or_future';
        }
        if (!$sample->artifactVerified || !hash_equals($artifactSha256, $sample->artifactSha256)) {
            $reasons[] = 'artifact_integrity';
        }
        if (!$sample->dependenciesHealthy) {
            $reasons[] = 'dependency_unhealthy';
        }
        if ($sample->sampleCount < $this->minimumSamples) {
            $reasons[] = 'insufficient_health_samples';
        }
        foreach ($this->maximums as $metric => $maximum) {
            $value = $sample->metrics[$metric] ?? null;
            if (
                (!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || ($value < 0 && $metric !== 'brier_degradation')
                || $value > $maximum
            ) {
                $reasons[] = 'metric_' . $metric;
            }
        }
        if (
            $this->referenceAsOf > $sample->windowStart
            || !hash_equals($this->featureSchemaSha256, $sample->featureSchemaSha256)
            || array_diff_key($this->referenceCounts, $sample->featureCounts) !== []
            || array_diff_key($sample->featureCounts, $this->referenceCounts) !== []
        ) {
            $reasons[] = 'feature_schema_mismatch';
        } else {
            foreach ($this->referenceCounts as $feature => $counts) {
                $current = $sample->featureCounts[$feature];
                if (
                    !$this->validCounts($current) || count($current) !== count($counts)
                    || array_sum($current) < $this->minimumSamples || array_sum($current) !== $sample->sampleCount
                ) {
                    $reasons[] = 'feature_samples_' . $feature;
                } elseif ($this->divergence($counts, $current) > $this->maximumFeatureDivergence) {
                    $reasons[] = 'feature_drift_' . $feature;
                }
            }
        }
        return $reasons;
    }

    private function validCounts(mixed $counts): bool
    {
        return is_array($counts) && array_is_list($counts) && count($counts) >= 2
            && array_filter($counts, static fn ($x) => !is_int($x) || $x < 0) === []
            && is_int(array_sum($counts)) && array_sum($counts) > 0;
    }
}
