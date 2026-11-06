# Phase 5D: model health, approvals and staged deployment

Authority: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 6.4, FK18, migration DAG, 8.6, admin API register and phase 5D. This document is an implementation report and runbook, not an alternate blueprint. The old blueprint path in AGENTS.md is absent; the user's explicit V4 authority applies.

## Implementation status

**Policy/service layer implemented and unit tested; integrated phase 5D remains BLOCKED.** No actual model was approved, deployed, suspended or rolled back. No source credentials or database secrets were read for display or changed. No next phase was started.

The current tree has no canonical model registry/version persistence, dataset/fold/forecast transport, real authorized model-release evidence or model-event outbox adapter. The new production-facing repository implementation explicitly throws unavailable. The test-only repository is under `tests/Support`, outside application autoloading. It must never be substituted for production persistence.

The new constructor-injected PHP service owns health inspection, signed approvals, shadow and limited assignment decisions, critical suspension, approval revocation, explicit rollback and limited serving eligibility. It has no public route or scheduler wiring. Missing backend/evidence cannot activate a model. Existing SPE/MLPE/BEVE research artifacts remain ineligible; their `release_eligible: false` flags and BEVE's `regularized_ensemble` labeling were not altered.

## Changed paths

Added PHP domain objects:

- `src/Domain/ModelHealth/DeploymentScope.php`: immutable partition/platform/league/market scope, stable normalized key, containment and wildcard overlap checks across all eight canonical markets.
- `src/Domain/ModelHealth/HealthObservation.php`: scoped point-in-time health window, observed/available/ingested clocks, sample counts, artifact/schema/manifest hashes and trusted metrics.
- `src/Domain/ModelHealth/HealthPolicy.php`: required metric thresholds, immutable reference histograms and reference clock, numerical Jensen-Shannon feature divergence, freshness/completeness/integrity checks.
- `src/Domain/ModelHealth/DeploymentPolicy.php`: explicitly supplied limited percentage cap, minimum shadow duration and approval lifetime; no production threshold defaults.
- `src/Domain/ModelHealth/ModelRelease.php`: validated canonical release projection, immutable evidence/artifact identity and independent gates; suspended/retired/research versions are ineligible.
- `src/Domain/ModelHealth/DeploymentAssignment.php`: bounded half-open shadow/limited intervals, stable server-subject cohort selection and immutable rollback lineage.
- `src/Domain/ModelHealth/ApprovalSigner.php`: HMAC-SHA256 approval integrity with injectable keyring, rotation support and unknown-key rejection.
- `src/Domain/ModelHealth/OperatorContext.php`: service checks for verified server-session identity, HTTPS, current permission/expiry and CSRF.
- `src/Domain/ModelHealth/ServingContext.php`: server-resolved subject, entitlement and session expiry checks.

Other added paths:

- `src/Contracts/ModelOperationsRepository.php`: atomic transaction/registry-lock boundary.
- `src/Contracts/ModelOperationsStore.php`: canonical version/health/assignment/approval/source/RBAC/audit/outbox persistence contract.
- `src/Repositories/UnavailableModelOperationsRepository.php`: explicit fail-closed production boundary while canonical storage is absent.
- `src/Services/ModelHealthService.php`: `inspect`, `approve`, `deploy`, `observe`, `suspend`, `revokeApproval`, `rollback`, `resolve`.
- `tests/ModelHealthTest.php`: PHPUnit safety/transition/rollback tests.
- `tests/Support/ModelOperationsTestRepository.php`: isolated unit-test transaction double with injected audit/outbox failure and snapshot restoration.
- `tests/Support/ModelHealthTestClock.php`: deterministic test clock.
- `phpunit.xml.dist`: scoped PHPUnit discovery without loading `.env` or invoking existing standalone test scripts.
- `phpcs-model-health.xml`: PSR-12 checks for this phase's PHP source/tests.
- `.github/workflows/model-health-tests.yml`: PHP 8.1/8.2 policy-test and style checks; hosted execution has not been verified.
- `docs/architecture/model-health-phase.md`: this report/runbook.

Modified `composer.json` and `composer.lock`: development-only PHPUnit 10.5.66 and PHP_CodeSniffer 4.0.4 with locked dependencies, test-only PSR-4 mapping, `test:model-health` and `lint:model-health` scripts. Production dependencies and PHP constraint remain unchanged. PHPUnit 10 supports the existing PHP 8.1 baseline; see the [official installation requirements](https://docs.phpunit.de/en/10.5/installation.html).

These 22 paths are this phase's changes. Earlier uncommitted/untracked bootstrap, database, live-source and numerical-worker work was preserved. No frontend, controller, numerical worker, existing route or migration file was edited.

## Behavior and safety boundaries

Approvals bind an immutable version, artifact SHA, release evidence SHA, normalized scope, channel, policy SHA, independent approver, issue time and expiry. Model creators cannot approve their own version. Current approver permission is checked again during use. Key removal, tampering, revocation, incompatible policy and expiry fail closed. HMAC is server-side integrity evidence; it is not an independent user's digital certificate or a substitute for verified RBAC/human approval. A production signing key must be provisioned externally and injected; no key was generated or placed in `.env` by this phase.

Model release evidence must independently pass lineage, point-in-time validity, untouched holdout, baseline, calibration, uncertainty, eight-market, source-rights and security gates. The typed evidence projection is for a trusted canonical registry adapter, not client input. Existing numerical artifacts cannot become releases merely by signing an approval. Model validation, training and signed artifact registration remain owned by the absent model platform.

Shadow assignments compute at 100% of their explicitly approved eligible scope, but never serve public predictions. Limited assignments have a policy-defined cap strictly below 100%; production is rejected. Limited activation requires an active, approved shadow assignment, minimum elapsed shadow time and fresh aggregate health whose declared window covers the shadow period. Promotion closes that shadow assignment atomically while retaining its history. The collector must compute metrics on the full declared opportunity cohort; simply declaring a wide window does not prove completeness. No live collector was implemented.

At serving time, the subject and entitlement must come from active server-side state. Stable scope/subject hashing determines limited enrollment. Caller-supplied random IDs must not be accepted. Conflicting assignments, expired approvals, missing entitlement, unsupported scopes and unavailable storage return `no_selection`, never a different version or demo fallback. Wildcard assignments also require fresh health and current source rights at the narrowed requested scope. Shadow results never become a public fallback. Eligibility output is a version/assignment decision only; it does not publish a forecast or change the legacy history response.

Health checks require source lag, queue age, calibration error, Brier degradation, missing-forecast ratio, no-selection ratio, selection drift and odds drift. Feature drift is computed from frozen bin counts using natural-log Jensen-Shannon divergence. No divergence threshold is silently supplied for production; test values are explicitly unit-test-only. Reference clocks must precede the observation window. Missing/empty/incompatible bins, inconsistent feature/sample counts, nonfinite values, insufficient samples, late/future observations, stale windows, failed dependencies, artifact mismatch or source-rights loss fail closed. Negative Brier degradation represents improvement and is permitted.

The health collector must supply authenticated, scoped inputs and manifests. It owns expected-opportunity denominators, point-in-time accepted settlement cohorts, queue/source measurements, and schema/baseline lineage. Arbitrary histograms or booleans from an HTTP caller are not trusted evidence. Nonfinite input diagnostics must be safely represented by a future persistence adapter rather than encoded as invalid JSON numeric values.

Critical observations append their manifest/reasons and suspend the version in the same transaction as assignment closure, audit and outbox. A serving-time health/source failure also records a suspension before returning no selection; audit failure rolls back state and still returns backend unavailable. No successful serving result can escape an uncommitted suspension. Approval-only failures deny serving without silently changing model evidence. Suspension is sticky: later healthy observations do not resume the model. Retirement remains terminal. Recovery/revalidation is a registry responsibility requiring new reviewed evidence; no automatic unsuspend path was added.

Rollback names an explicit previous limited assignment in the same exact scope, current latest assignment, and a fresh approval. The previous target must currently pass release, source, health, permission and policy gates. Rollback may follow a suspension that already ended the current assignment. It creates a new assignment with lineage rather than editing old evidence or reinstating a stale approval. Failed rollback preserves the current assignment. It never picks an earlier version automatically or rolls back to a suspended/research version.

## Transactions, migration and FK report

At phase start `php bin/verify-database.php` performed actual read-only MySQL introspection:

```text
verified_at: 2026-11-06T02:45:24Z
mysql_version: 8.4.13
timezone: +00:00
table_count: 32
foreign_key_count: 39
verified_migrations: 000_schema_ledger, 010_catalog, 020_identity, 030_source_provenance
canonical_markets: 8
```

No migration was added/applied, no table or FK was created, and no database write was performed. Canonical migration `055_model_health` depends on `054` and `020`; `040` and `050`-`054`, including physical `model_versions`, are absent. Creating `055` against guessed version ID types/columns would violate the dependency DAG and FK contract. No isolated approval/health/version tables or alternate registry were created.

Required future ownership: `model_health_events.model_version_id` and `model_deployment_assignments.model_version_id` reference canonical versions; optional platform/league/market scope references canonical catalog entities with league/platform consistency. Assignment `approved_by` references `users`. Signed approval evidence belongs to canonical version/audit/assignment history, not an invented parallel approvals ledger. Exact SQL types and named constraints must follow actual prerequisite migrations when implemented.

**Conflict escalated:** current `transactional_outbox.batch_id` is mandatory and references ingestion batches. Model events cannot be inserted without an ingestion batch. This phase does not fake batch ownership, insert fake ingestion batches, relax the FK or silently change that contract. A separately scoped canonical model-platform/outbox migration must establish proper aggregate ownership before a PDO adapter can be implemented and verified.

`ModelOperationsRepository::transaction` requires one PDO MySQL transaction plus a registry-wide lock held across reads, overlap checks and writes, including wildcard scopes. The future adapter must enforce FK18, immutable approval/evidence records, monotonic revocation, immutable health events, monotonic latest-health projections, unique command keys, and atomic audit/outbox/result persistence. The service's idempotency fingerprint rejects request-key reuse with a different operation/payload/policy. A global lock is deliberately conservative; contention needs benchmarking before production use. The test double verifies service rollback semantics, not MySQL locks or concurrent worker safety. No PDO write adapter is claimed here.

## API and contract diffs

No public HTTP route or response envelope changed. The PHP server-rendered UI and legacy `GET /api/platforms/{slug}/predictions` route remain as before. V4 admin health, activate-version and suspend endpoints remain unimplemented pending production session middleware, RBAC permissions, CSRF boundary, canonical storage and trusted worker transport. Internal context objects test authorization conditions but do not authenticate HTTP requests by themselves.

The new internal approval claims schema is `simora.model-approval.v1`. No external worker protocol, OpenAPI URL, signed research artifact contract or migration checksum was altered. Healthy model lifecycle advancement (`validated`/`shadow`/`approved`/`active`) remains the canonical registry's responsibility; this service represents scoped channel transitions with assignments and only persists fail-closed version suspension. That integration gap is explicit and prevents declaring the full phase complete.

Effective capability status, not newly introduced environment flags:

```text
policy_service_implemented: true
canonical_repository_available: false
admin_routes_enabled: false
live_monitor_collector_enabled: false
shadow_deployments_enabled: false
limited_deployments_enabled: false
production_deployments_enabled: false
auto_trade_execution_enabled: false
```

## Tests actually run

Host PHP: 8.2.12. Final PHPUnit output:

```text
composer test:model-health
PHPUnit 10.5.66
Time: 00:00.214, Memory: 8.00 MB
OK (25 tests, 149 assertions)
```

Tests cover authenticated-context/CSRF/RBAC checks, independent approvals, HMAC tampering and rotation, approver removal/revocation/expiry, research/production rejection, shadow duration/window requirements, stable cohort boundaries, wildcard overlap, eight distinct market scopes, policy/percentage corruption, missing/stale/future/backfilled health, all required metric breaches and nonfinite data, known divergence bounds, drift suspension, source revocation, sticky suspension, terminal retirement, idempotency, conflicting assignment suspension, audit/outbox rollback, healthy explicit rollback after suspension and unsafe-target refusal. The repository and all source/approval evidence in these tests are isolated test doubles; no real model is approved.

Other executed checks:

```text
composer lint:model-health
PSR-12: no errors or warnings
php -l on all 16 phase PHP source/test files
No syntax errors detected
composer validate --strict
./composer.json is valid
composer audit --locked --no-interaction
No security vulnerability advisories found.
php tests/catalog.php
Catalog market and identity tests passed
php tests/identity.php
Identity primitive tests passed
php tests/predictions-hub.php
Predictions hub tests passed
node tests/history-load-more.cjs
Load-more interaction tests passed
git diff --check
exit 0; existing LF/CRLF notices only
```

The first 19-test run reported two rollback setup errors because the prior shadow assignment was still active. Promotion was corrected to close its shadow assignment atomically; the subsequent run passed 19/128. Further boundary tests brought the suite to 24/146, then 25/149 after the terminal-retirement check. Initial style failures were fixed before the final PSR-12 run. The first PHPUnit dependency command lost its caret through the Windows batch wrapper and selected an insecure exact 10.5.0; Composer blocked installation and reverted it. A correctly quoted range installed locked 10.5.66 with a clean audit. No advisory checks were bypassed.

No new failures were observed in the market/identity/predictions/history checks. No hosted CI, PHP 8.1 runtime run, real MySQL concurrent assignment test, DB audit/outbox crash replay, collector/scheduler integration, Redis test, browser visual review, live source drill, numerical-worker rerun or production deployment/rollback drill was performed. Numerical worker code was unchanged, so phase 5B/5C test evidence remains in its existing reports rather than being represented as rerun here.

## Rollback, restart and remaining dependencies

No service, scheduler or deployment was started, so no web/database/worker restart is needed. To reproduce the policy checks, run `composer install --no-interaction --prefer-dist`, `composer test:model-health` and `composer lint:model-health`. Development dependencies are excluded by `composer install --no-dev` for application runtime installs.

Rollback this code phase by reverting only the 22 listed path changes and regenerating Composer autoload/vendor state from the restored lock. Keep the pre-existing Composer production configuration and earlier untracked files; do not delete whole `src`, `tests`, `.github`, `workers`, `composer.json` or `composer.lock` trees/files merely because they were untracked. Preserve `.env`, `.secrets`, database volumes, artifact runtimes and migration ledger. No schema rollback is needed.

For a future operational rollback, first fail closed on the incident, retain all publications/settlements and evidence, obtain a fresh independent approval for the named prior healthy release, then create the replacement assignment transactionally. If any target gate or storage operation fails, keep serving unavailable; do not activate a fallback, rewrite history or re-enable Auto Trade.

Remaining prerequisites: canonical model/FK migrations; a correctly owned model-event outbox; PDO repository implementation and real concurrency/atomicity tests; source rights and trusted collector metrics; production session/CSRF/RBAC/entitlement integration; authenticated idempotent monitor transport; immutable signed registry and artifact validation; independent numerical/release/threshold approvals; and shadow/limited incident/rollback drills on authorized data. Metric thresholds, cohort caps and shadow duration in unit tests are not deployment approvals.

Recommended next action, only if separately requested: resolve the model-platform/outbox ownership prerequisite and then complete the PDO/HTTP/collector integration for this same phase. Do not automatically start ranking, publication, production rollout or another implementation phase.
