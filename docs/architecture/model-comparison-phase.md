# Phase 6B: comparison and champion decision implementation report

Authority: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 6.5, FK14, migrations `061`/`062`, section 10, V11 and phase 6B. This is an implementation/evidence report, not another backend blueprint. The user's explicit V4 authority supersedes the absent older blueprint path in AGENTS.md.

## Summary and phase status

**Numerical and PHP policy/service layers implemented and tested. Integrated phase 6B remains BLOCKED.** No real provider/model evaluation, financial return, released report or operational champion was produced. No other implementation phase was started.

The internal Python analytics package computes all-eight-market skill on a fixed common fixture cohort, separate selected returns from recorded stakes/payouts/fees, paired whole-round bootstrap intervals, manifest-backed report snapshots and explicit correction revisions. PHP verifies the Python snapshot bytes, applies an independently approved champion policy and stores/resolves immutable as-of winner or abstention decisions through repository contracts. The production repository explicitly fails unavailable while canonical persistence/transport is absent.

The package is separate from `workers/models/simora_models`; no existing numerical engine, model artifact schema/version/code digest, frontend view, controller or public route was changed. No Auto Trade execution, new ranking/publication logic or strategy simulator was introduced.

## Changed paths

New analytics paths:

- `workers/analytics/simora_analytics/__init__.py`: internal implementation version 1.0.0.
- `workers/analytics/simora_analytics/contracts.py`: immutable plan, frozen forecasts, full opportunity denominator, publication/exposure projection and settlement revision contracts.
- `workers/analytics/simora_analytics/comparison.py`: common-cohort Brier/log loss/reliability, paired model deltas, selected-only integer accounting/coverage and correction manifests.
- `workers/analytics/simora_analytics/intervals.py`: reproducible whole-round percentile bootstrap with shared draws for skill differences and explicit insufficient-sample states.
- `workers/analytics/simora_analytics/snapshots.py`: exclusive, fsynced, HMAC-signed report envelopes; hash/key/signature verification and historical immutable reads.
- `workers/analytics/tests/test_comparison.py`: 18 numerical, temporal, accounting, correction, signature and cross-language tests.
- `workers/analytics/Dockerfile`: pinned-base, non-root Python 3.12 analytics test/runtime package with existing pinned dependencies.
- `workers/analytics/README.md`: internal contracts, operation sequence, interval/accounting limits, report and champion boundaries.
- `workers/.dockerignore`: exclude Python bytecode/test caches from the new workers-root build context.

New PHP paths:

- `src/Domain/ModelComparison/ComparisonSnapshot.php`: exact-byte Python report signature verifier.
- `src/Domain/ModelComparison/ChampionPolicy.php`: sample/denominator, scope, freshness, multiplicity and paired skill-dominance checks; never selects on ROI.
- `src/Domain/ModelComparison/ChampionRecord.php`: signed immutable decision plus separately verified durable availability metadata.
- `src/Domain/ModelComparison/ComparisonViewer.php`: server-resolved comparison entitlement/session context.
- `src/Contracts/ChampionRepository.php`: transactional scope/policy lock boundary.
- `src/Contracts/ChampionStore.php`: canonical report/approval/decision/eligibility/audit/outbox contract.
- `src/Repositories/UnavailableChampionRepository.php`: explicit unavailable production boundary.
- `src/Services/ChampionDecisionService.php`: authorized idempotent decision commits and as-of, fail-closed reads without report recalculation.
- `tests/ModelComparisonTest.php`: 15 PHPUnit tests for champion safety and time travel.
- `tests/Support/ChampionTestRepository.php`: test-only transaction/visibility/audit double; not a real registry or concurrency proof.
- `phpcs-model-comparison.xml`: targeted PSR-12 checks.
- `.github/workflows/model-comparison-tests.yml`: PHP 8.1/8.2 and Python 3.12/3.14 checks, including PHP/Python snapshot interoperability.

Modified `composer.json`: add comparison test/style scripts; make the existing health script explicitly select its health suite. Modified `phpunit.xml.dist`: add the comparison suite while preserving the health suite. No dependency or Composer lock change was needed. Added this report at `docs/architecture/model-comparison-phase.md`.

These are 24 phase paths. The pre-existing dirty/untracked bootstrap/database/source/model-health/numerical work was preserved. The root README, public front controller, secret files, existing migrations and earlier phase reports were not edited by this phase.

## Evaluation populations and confidence intervals

The plan fixes exact fixture IDs, platform/league/partition, candidate and baseline version IDs, policy/experiment, window, evaluation clock, feature/decision buffers, currency and resampling specification before the first forecast cutoff. Immutable artifact/fitted/fold lineage, target-round exclusion and available-before-decision observations are checked. This adapter compares fixed frozen versions, not silently refitted family versions across folds. Full production lineage authentication remains the model platform's responsibility.

For forecast skill, any missing compared forecast or unavailable/void/disputed result excludes that fixture from every candidate and baseline equally. All eight market metrics use the same matched fixture set, denominators and evaluation clock. Exclusions, opportunity coverage and result manifests are explicit. Brier, clipped log loss and reliability bins are reported; no model is trained or recalibrated here.

Selection returns use the separate full per-model/fixture/market opportunity ledger, including no-picks and unavailable states. Qualified opportunities, forecasts, immutable publications and actual quotes must reconcile exactly. They are never used to shrink the common skill cohort to favorable selected fixtures. Odds require mapped/authorized prematch observation, availability, ingestion and expiry metadata; no quote is synthesized.

Exposure fields are a joined projection of externally recorded stake evidence, not changes to immutable publications. Net is exact integer `returned_minor - actual stake_minor - recorded fee_minor`; provider payouts/void handling must already be verified under the referenced contract. No unit stake, fee exemption, payout or rounding rule is assumed. Missing actual stakes/fees/payout verification, disputed results or pending corrections withhold the entire financial summary, not just inconvenient rows. No stakes yields ROI null. Ratios use Decimal precision 28, half-even; bootstrap conversion is bounded to exact integer range `2^53-1`.

Intervals draw whole virtual rounds with replacement and reuse the same draw multiplicities across all skill columns, yielding paired candidate/baseline differences on the identical cohort. Selected ROI uses its own selected round clusters and is conditional on recorded selections; it is not a policy-selection uncertainty interval. Too few clusters yields null bounds/reasons. The report states nominal level, seed, resample count, quantile method, clustering and unadjusted multiple-testing status. Real-data coverage is not claimed.

Profit factor, counts, coverage, observed odds and cumulative-net drawdown are separate from strategy P&L. The drawdown clock is explicitly a reconstructed as-of settlement curve ordered by fixture-round kickoff, not chronological cashflow or simulated bankroll. No combined cross-market/model financial total is emitted because shared fixtures/exposures are correlated.

Settlement corrections are contiguous, owned, monotonic append-only revisions. As-of evaluation uses only usable revisions and contemporaneously available source results. An unmatched later source correction makes the selection pending until its matching settlement exists. Future corrections cannot change an earlier as-of report. A new clock/snapshot can reference `supersedes_report_sha256`; the original snapshot and decision remain immutable.

## Report snapshots and champion behavior

Reports record scope/window, exact version and fixture-universe hashes, plan/source/input/result/settlement manifests, implementation/code/dependency versions, common skill and separate selected populations. Original inputs/exports must also be retained; digests alone cannot replay absent data. Current research bounds are 10,000 fixtures, eight compared versions, 200–10,000 resamples and 5,000,000 bootstrap round/draw cells. Production-scale performance has not been benchmarked.

The snapshot HMAC signs `schema + LF + key_id + LF + payload_sha256`, preserving exact UTF-8 payload bytes inside the envelope. PHP verifies those same bytes without float/key re-encoding. Exclusive writes refuse overwrite and fsync before return. A crash can leave a partial file, which verification rejects; preservation/new-path retry is required rather than destructive repair. Verification is size-bounded to 32 MiB. No production signing key was created, configured or displayed.

Every report written by the implemented numerical producer stays `research_only`, `release_eligible: false`; the writer rejects attempts to sign release-eligible output. This cannot be made an operational champion by merely toggling a request flag. PHP unit fixtures contain hypothetical future approved metadata solely to exercise policy transitions; those assertions are not provider/model approvals or effectiveness evidence.

Champion commit requires verified server-session permission/CSRF, exact report scope/experiment/policy, trusted snapshot signature, independent canonical report approval, appropriate sample counts and all-eight-market common denominators. It requires strict paired Brier/log-loss dominance over every baseline and alternative, explicit improvement margins, Bonferroni family-level coverage over all declared market/model/metric hypotheses and sufficient bootstrap tail replicates. Thresholds have no production defaults. Actual validated interval coverage and release approval remain external prerequisites; a nominal percentile/Bonferroni calculation alone is not scientific validation. Selected ROI never enters champion choice.

Ties, insufficient evidence, research/unapproved/stale reports or an unavailable winner produce an explicit null champion, never a runner-up. Decisions bind the immutable report, selected version/abstention, policy, scope, approver and decision/effective/expiry clocks. New decisions cannot be backdated, and positive decisions cannot outlive report freshness. Request-key replay is idempotent even after time advances; collisions with different payloads fail.

The repository must separately attach verified actual durable-commit availability, which can be later than `decided_at`. Pending/unverified availability is invisible. An effective time does not prove a transaction was committed. The test double simulates a two-second availability delay; it is not proof of MySQL commit timestamp handling.

As-of reads take the latest visible effective committed decision and check signature, original snapshot integrity, time-valid report/approver approval, expiry/freshness and current model health/source eligibility. They never recompute a winner from the report or a later dataset, and never fall back behind a newer abstention, expired decision or unhealthy winner. Current safety may deny serving an archived winner; it does not rewrite that archived decision or immutable publication references. This serving resolver is not an unrestricted historical replay endpoint. Entitlement contexts must be produced by verified server-side session/access code, not client-supplied claims.

## Database migrations, FK and outbox report

Actual read-only `php bin/verify-database.php` introspection at phase start returned:

```text
verified_at: 2026-11-06T13:36:23Z
mysql_version: 8.4.13
timezone: +00:00
table_count: 32
foreign_key_count: 39
verified_migrations: 000_schema_ledger, 010_catalog, 020_identity, 030_source_provenance
canonical_markets: 8
```

No migration was added/applied; no table/FK, ledger checksum, financial record or database row was changed. Canonical `061_model_comparisons` depends on `060`, and `062_champion_decisions` depends on `061`. The locally absent `040`–`060` prerequisites include model versions, folds, immutable forecasts/publications and auditable settlements. No isolated comparison/champion/model tables, guessed FK types or parallel prediction system were introduced.

Future ownership must preserve `model_comparison_reports` references to canonical experiment/backtest lineage, scope/partition and frozen version-set/report hash. FK14 requires `model_champion_decisions` to reference the exact comparison report, selection policy, nullable chosen model version and approver user. Enforce unique decision keys and normalized scope/policy/decision clocks under one transaction/exclusion lock, with immutable evidence, audit, outbox and command result committed together. SQL types, named FKs and real fresh/upgrade tests must follow actual prerequisite migrations.

**Existing contract conflict remains escalated:** `transactional_outbox.batch_id` is mandatory and ingestion-owned. This phase does not insert fake batches or relax its FK to carry report/champion events. A canonical aggregate-owned outbox migration and authenticated trusted exporter are required before a PDO adapter/consumer can be implemented and verified. No production PDO write adapter, MySQL locking or crash-replay guarantee is claimed by the unit doubles.

## API diffs and security/source impact

No HTTP endpoint or existing envelope changed. PHP server-rendered routes/design and legacy `GET /api/platforms/{slug}/predictions` remain untouched. New public REST/presenter integration belongs to phase 7 and was not started. No source provider connection, worker transport, session route, entitlements database, Redis consumer or Auto Trade execution was enabled.

New internal contracts: `simora.comparison-report.v1`, `simora.comparison-snapshot.v1`, `simora.champion-decision.v1`; Python DTO/operation details are in `workers/analytics/README.md`. Existing `simora.model.v1`, numerical package 1.1.0, evaluation CLI schemas and public history remain compatible because model-package source files were not modified.

Source assertions and release booleans in trusted DTOs are not an authorization boundary. Production requires independently authenticated canonical exports, source/retention/public-release rights, current healthy approved versions, server-side auth/CSRF/RBAC/entitlements and separately provisioned signing keyrings. Synthetic observations/quotes/money in tests are isolated handwritten algorithm fixtures and never enter live storage or release evidence. No profitability claim or real financial ROI is made.

Effective capability status (not new environment flags):

```text
offline_comparison_mathematics: implemented_tested
champion_policy_service: implemented_tested
canonical_comparison_repository: unavailable
publication_settlement_exports: unavailable
released_report_producer: unavailable
public_comparison_routes: disabled
operational_champion_selection: disabled
auto_trade_execution: disabled
```

## Tests actually run and results

Host: isolated existing Python 3.14.4 with `PYTHONPATH=workers/models;workers/analytics`, PHP 8.2.12 and PHPUnit 10.5.66.

```text
python -m unittest discover -s workers/analytics/tests -v
Ran 18 tests in 2.940s
OK
UNIT ONLY paired-round interval coverage: 137/160 at nominal 90%

composer test:model-comparison
OK (15 tests, 51 assertions)
composer test:model-health
OK (25 tests, 149 assertions)
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist
Time: 00:00.233, Memory: 8.00 MB
OK (40 tests, 200 assertions)
```

The 137/160 known-law coverage result is a seeded finite unit diagnostic, not proof of nominal/real-data calibration. It exercises fully correlated within-round fixtures so treating those fixtures as independent would distort uncertainty. Identical predictions have exactly zero paired-difference intervals; duplicating whole correlated observations preserves clustered draw behavior. The host Python-to-PHP signature test passed. No unit-test financial output is reported as a real provider result.

Container verification:

```text
docker build -f workers/analytics/Dockerfile -t simora-analytics:phase6b workers
exit 0
docker run --rm --network none --read-only --tmpfs /tmp simora-analytics:phase6b -m unittest discover -s tests -v
Ran 18 tests in 2.581s
OK (skipped=1)
UNIT ONLY paired-round interval coverage: 137/160 at nominal 90%
```

That is 17 container passes and one PHP interoperability skip because the numerical image has no PHP. The host run passed all 18 without skips. The container ran non-root with no network/read-only root and left no service running.

Additional executed checks:

```text
composer lint:model-comparison
PSR-12: no errors or warnings
composer lint:model-health
PSR-12: no errors or warnings
composer validate --strict
./composer.json is valid
composer audit --locked --no-interaction
No security vulnerability advisories found.
python -m pip check
No broken requirements found.
Python AST parsing of workers/analytics/**/*.py
Analytics AST syntax checks passed: 6 files
php -l on all 10 added PHP source/test files
No syntax errors detected
php tests/catalog.php
Catalog market and identity tests passed
php tests/predictions-hub.php
Predictions hub tests passed
node tests/history-load-more.cjs
Load-more interaction tests passed
```

Initial 16-test Python and 13-test PHP runs passed; additional coverage, void-contract, evidence-integrity and freshness checks brought the final suites to 18 and 15. Initial PSR-12 formatting/line warnings were corrected before final checks. No functional test failure was observed in this phase. Existing health/catalog/hub/history checks show no regression in those tested contracts.

Not run: hosted CI, PHP 8.1 runtime, real authorized model comparison, independently accepted uncertainty coverage/multiplicity study, actual MySQL fresh/upgrade/concurrency/audit/outbox crash tests, distributed worker authentication/replay, performance/load benchmarks, browser visual review, live release/rollback drill or the unchanged model-worker 5B/5C suite. Those earlier test results remain in their phase reports and are not represented as rerun here.

## Rollback, restart and remaining dependencies

No production service, model release, publication or champion was activated, so no web/database/worker restart or schema rollback is required. Reproduce the README checks with the existing pinned numerical environment and Composer dev dependencies, or rebuild/run `simora-analytics:phase6b`.

Code rollback: revert only the 24 listed path changes; restore the prior Composer scripts and PHPUnit XML while preserving previous production/dev dependencies, `composer.lock`, model-health tests and unrelated untracked work. Remove only the new analytics/comparison files if rolling back uncommitted additions; do not delete whole `workers`, `src`, `tests`, `.github`, secret or database directories. Regenerate Composer autoload state if needed. Preserve private report/input artifacts, model runtimes, migration ledger and database volumes. There is no model artifact invalidation to undo because existing model-package code/digests were preserved.

Future operational rollback is append-only: retain original report/decision/publication evidence, create a newly approved decision or explicit abstention effective only from its legitimate commit/visibility time, and recheck health/source gates. Do not rewrite a historical champion, auto-select a previous model or fabricate revised settlement returns.

Outstanding blockers: canonical model, publication and settlement persistence; actual recorded exposure ownership and provider settlement contracts; correctly owned model/report/champion outbox; trusted point-in-time exporter and immutable input/report storage; PDO/FK/concurrency/durable-visibility implementation; independently approved released-report producer, statistical release thresholds and coverage/multiplicity protocol; server auth/RBAC/entitlement read integration; and authorized source/model data. The current research report producer intentionally cannot satisfy production champion release gates.

Recommended next action, only when explicitly requested: resolve those canonical model/publication/settlement/outbox prerequisites and complete the same phase's DB/export integration. Do not automatically proceed to REST/SSR integration, simulations, publication or another phase.
