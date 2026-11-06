<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ChampionRepository;
use App\Contracts\ChampionStore;
use App\Contracts\Clock;
use App\Domain\ModelComparison\ChampionPolicy;
use App\Domain\ModelComparison\ChampionRecord;
use App\Domain\ModelComparison\ComparisonSnapshot;
use App\Domain\ModelComparison\ComparisonViewer;
use App\Domain\ModelHealth\ApprovalSigner;
use App\Domain\ModelHealth\DeploymentScope;
use App\Domain\ModelHealth\OperatorContext;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ChampionDecisionService
{
    public function __construct(
        private readonly ChampionRepository $repository,
        private readonly Clock $clock,
        private readonly ChampionPolicy $policy,
        private readonly ComparisonSnapshot $reports,
        private readonly ApprovalSigner $signer
    ) {
    }

    public function commit(
        OperatorContext $actor,
        string $key,
        DeploymentScope $scope,
        string $reportSha256,
        DateTimeImmutable $effectiveAt,
        DateTimeImmutable $expiresAt
    ): ChampionRecord {
        $now = $this->clock->now();
        $actor->authorize('models.champions.approve', $now);
        if (
            preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $key) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $reportSha256) !== 1 || $expiresAt <= $effectiveAt
        ) {
            throw new InvalidArgumentException('Invalid champion decision key, digest or effective interval.');
        }
        $fingerprint = hash('sha256', serialize([$actor->userId, $scope, $reportSha256,
            $effectiveAt, $expiresAt, $this->policy->digest()]));
        return $this->repository->transaction(function (ChampionStore $store) use (
            $actor,
            $key,
            $scope,
            $reportSha256,
            $effectiveAt,
            $expiresAt,
            $now,
            $fingerprint
        ) {
            if (!$store->approverAuthorized($actor->userId, $now)) {
                throw new RuntimeException('Champion approver is no longer authorized.');
            }
            $previous = $store->decision($key);
            if ($previous !== null) {
                if (!hash_equals($previous['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Champion decision key reused with different inputs.');
                }
                return $previous['record'];
            }
            if (
                $effectiveAt < $now
                || $expiresAt > $now->modify('+' . $this->policy->maximumDecisionSeconds . ' seconds')
            ) {
                throw new RuntimeException('Champion decisions cannot be backdated or exceed their approved lifetime.');
            }
            $envelope = $store->reportEnvelope($reportSha256);
            if ($envelope === null) {
                throw new RuntimeException('Immutable comparison report unavailable.');
            }
            $report = $this->reports->verify($envelope, $reportSha256);
            [$chosen, $reason] = $this->policy->choose($report, $scope, $now);
            if (!$store->reportApproved($reportSha256, $now)) {
                [$chosen, $reason] = [null, 'report_not_independently_approved'];
            }
            if ($chosen !== null && !$store->modelEligible($chosen, $scope, $now, $now)) {
                [$chosen, $reason] = [null, 'chosen_model_ineligible'];
            }
            if ($chosen !== null && !$this->policy->evidenceFresh($report, $expiresAt->modify('-1 microsecond'))) {
                throw new RuntimeException('Champion lifetime exceeds evidence freshness.');
            }
            $unsigned = new ChampionRecord(
                $key,
                $scope,
                $this->policy->version,
                $reportSha256,
                $chosen,
                $reason,
                $now,
                $effectiveAt,
                $expiresAt,
                $actor->userId,
                []
            );
            $signature = $this->signer->sign($unsigned->claims() + ['policy_sha256' => $this->policy->digest()]);
            $record = new ChampionRecord(
                $key,
                $scope,
                $this->policy->version,
                $reportSha256,
                $chosen,
                $reason,
                $now,
                $effectiveAt,
                $expiresAt,
                $actor->userId,
                $signature
            );
            $store->append($record, $fingerprint, ['operation' => 'model.champion_decided',
                'actor_user_id' => $actor->userId, 'report_sha256' => $reportSha256,
                'scope_key' => $scope->key(), 'decision_key' => $key, 'reason' => $reason]);
            return $record;
        });
    }

    public function resolve(
        DeploymentScope $scope,
        DateTimeImmutable $selectionAsOf,
        ComparisonViewer $viewer
    ): array {
        $now = $this->clock->now();
        if (!$viewer->allowed($now)) {
            return $this->none('access_limited');
        }
        if ($selectionAsOf > $now) {
            return $this->none('future_selection_clock');
        }
        try {
            return $this->repository->transaction(function (ChampionStore $store) use ($scope, $selectionAsOf, $now) {
                $visible = array_values(array_filter(
                    $store->decisions($scope, $this->policy->version),
                    fn ($r) => $r->scope->key() === $scope->key() && $r->policyVersion === $this->policy->version
                    && $r->visible($selectionAsOf)
                ));
                usort($visible, static fn ($a, $b) => $b->decidedAt <=> $a->decidedAt);
                $record = $visible[0] ?? null;
                if ($record === null) {
                    return $this->none('no_committed_decision');
                }
                if (isset($visible[1]) && $visible[1]->decidedAt == $record->decidedAt) {
                    return $this->none('ambiguous_decision_clock');
                }
                $claims = $record->claims() + ['policy_sha256' => $this->policy->digest()];
                $signedClaims = $record->signature['claims'] ?? [];
                ksort($claims);
                ksort($signedClaims);
                if (
                    !$this->signer->verify($record->signature) || $signedClaims !== $claims
                    || $record->expiresAt <= $selectionAsOf
                    || !$store->approverAuthorized($record->approvedBy, $selectionAsOf)
                    || !$store->reportApproved($record->reportSha256, $selectionAsOf)
                ) {
                    return $this->none('decision_expired_or_unapproved');
                }
                $envelope = $store->reportEnvelope($record->reportSha256);
                if ($envelope === null) {
                    return $this->none('report_snapshot_unavailable');
                }
                // Integrity verification only: never recalculate a champion during reads.
                $report = $this->reports->verify($envelope, $record->reportSha256);
                if (!$this->policy->evidenceFresh($report, $selectionAsOf)) {
                    return $this->none('decision_evidence_stale');
                }
                if ($record->modelVersion === null) {
                    return $this->none($record->reason);
                }
                if (!$store->modelEligible($record->modelVersion, $scope, $selectionAsOf, $now)) {
                    return $this->none('chosen_model_ineligible');
                }
                return ['status' => 'qualified', 'model_version' => $record->modelVersion,
                    'decision_key' => $record->key, 'report_sha256' => $record->reportSha256,
                    'selection_as_of' => $selectionAsOf->format(DATE_ATOM), 'auto_trade_execution' => false];
            });
        } catch (Throwable $exception) {
            return $this->none('champion_backend_unavailable');
        }
    }

    private function none(string $reason): array
    {
        return ['status' => 'insufficient_evidence', 'model_version' => null,
            'decision_key' => null, 'reason' => $reason, 'auto_trade_execution' => false];
    }
}
