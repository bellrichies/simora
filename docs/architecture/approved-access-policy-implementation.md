# Approved V1 access policy — implementation report

2026-11-06, Africa/Lagos. This is a Phase 9 continuation, including the explicitly approved higher simulator limits. The user approved the reconciled policy and exact allocations/comparison semantics; [the approved policy record](access-policy-v1-proposal.md) supplements the authoritative V4 blueprint. Policy approval is resolved; production integration dependencies remain.

## Inspection and implementation

Inspected the local Git tree, V4 sections 6/11/12/13, ledger/schema, access/controller, simulator/config/chart/storage contracts and tests. Preserved existing Phase 7/8/9 edits. Initial verification: MySQL 8.4.13, 42 tables, 54 FKs, five checksummed migrations through `080`, eight markets. No prior migration was edited.

Implemented the approved model/market/history/comparison limits, WAT daily export budgeting, unlimited subscription-lifetime analyses/simulator runs, paid-context/revocation checks, versioned catalog publication and independent numerical/store bounds. Pro's 90-day raw history and all-retained Full comparison are separate permissions. No customer payment/subscription/model release was activated.

Created:

- `src/Domain/Access/PlanPolicy.php`: approved matrix, strict typed snapshot verification, capability/metric/history checks and WAT reset anchor.
- `src/Domain/Simulation/SimulationLimits.php`: trusted current outcome/history bounds.
- `database/migrations/081_approved_access_policy.sql`.
- `tests/PlanPolicyTest.php`.
- `docs/architecture/approved-access-policy-implementation.md`.

Modified:

- `src/Repositories/AccessRepository.php`, `src/Services/AccessService.php`: pinned paid context, confirmation/expiry/trial checks, derived policy grants, revocations, atomic `performPlan` consumption and per-event grant evidence. Generic manual-grant interfaces remain for their existing internal purposes; paid handlers must use the plan gateway.
- `src/Http/AnalyticsApiController.php`, `public/index.php`: lazy constructor-injected real-session plan preflight before unavailable analytics guards.
- `src/Infrastructure/MigrationRunner.php`, `bin/verify-database.php`: `081` registration/preflight and exact approved snapshot/price/provenance verification.
- `src/Domain/Simulation/SimulationConfig.php`, `src/Presenters/SimulationChart.php`: verified ceilings up to 1,000 outcomes/1,001 chart points; unverified default remains 100, verified Trial default 50.
- `src/Contracts/SimulationStore.php`, `src/Services/SimulationService.php`: required current store limits, checked on new/cached/owned reports. Internal adapters must implement the new `limits` method; it cannot use a client plan flag.
- `tests/Support/SimulationTestRepository.php`, `tests/StrategySimulatorTest.php`: all four numerical limits, exact maximum-size bankroll/chart, forged cap, retention and downgrade checks.
- `tests/AccessIntegrationTest.php`, `tests/LegacyRoutesTest.php`, `phpunit.xml.dist`: new schema/policy/payment-ledger fixtures and HTTP/unit regression coverage.
- Approved policy record, initial Phase 9 report and `IMPLEMENTATION_STATUS.md`.

No SSR design, legacy history fields/string errors, browser demo, fixture JSON, workers, secrets or environment configuration changed in this continuation.

## Migration and FK report

**Applied locally:** `081_approved_access_policy`, depending on `080`. Output: `000_schema_ledger`, `020_identity`, `080_access_notifications` already applied; `081_approved_access_policy: applied`.

Zero new tables; two new FKs:

- Extend price intervals with `thirty_days`, retaining historical `month` values.
- Add explicit `approval_kind`; allow a documented product approval without inventing an application-user approver. Operator approvals still require a real actor, and product approvals constrain their source reference. Append four catalog policy/price snapshots and four audits, without provisioning accounts or roles.
- Add subscription unique `(id,plan_version_id)` and entitlement `plan_version_id`, with `fk_entitlement_policy_subscription` to that pair. The existing owner/subscription FK still enforces account ownership.
- Add nullable legacy-compatible `usage_events.entitlement_id`, with `fk_usage_event_entitlement_owner` to `(user_id,id)` on entitlements. New receipts always record the actual authorizing grant. Upgrade carryover preserves original counters and historical receipts.

Independent verifier exit 0 at `2026-11-06T15:58:57Z`: **MySQL 8.4.13, UTC, 42 tables, 56 FKs, eight markets**; six successful checksummed migrations (`000`, `010`, `020`, `030`, `080`, `081`), all FK definitions and four exact approved policies/prices/provenance checked. Applied `081` must not be edited/replayed with a different checksum.

Read-only local-state check confirmed Trial 1 model/2 markets/50 outcomes/86,400 seconds; Standard 2/4/120/1,209,600; Pro 3/8/250/2,592,000; Max 3/8/1,000/2,592,000. All execution flags false. Customer subscriptions, entitlements, usage events and notifications remain **zero**. Tests used disposable databases only.

Direct-SQL immutability hardening remains a deployment gap from the earlier phase: services append versions and strict verification detects unexpected snapshots, but existing broad app DML privileges are not a proof against another direct writer. No SUPER/global MySQL trust setting was enabled.

## Runtime/API behavior and limits

Owners come only from hashed database sessions with HTTPS/live RBAC; writes require CSRF. Paid requests require exactly one active in-period subscription, its pinned effective approved policy, and a trusted internal `payment.confirmed` subscription event with evidence. Trial requires verification before activation and only one paid trial per verified account. Overlong terms, ambiguity, expiry, invalid policies/scopes, unknown capabilities and revoked derived features fail closed.

**A provider verifier/activation handler is not implemented.** The confirmation event is a server-owned ledger seam for the separately gated payment phase, not proof that provider integration exists. Neither the policy service nor migration creates it or activates subscriptions. Tests insert explicitly unit-only confirmation fixtures. Future signed-provider processing must perform audited subscription/trial/renewal/upgrade/downgrade transitions atomically; no unverified callback grants access here.

`performPlan` applies approved scope/metric/history constraints and meters successful transactional work, receipts and audit atomically. Failure rolls everything back, including a newly derived grant. Duplicate requests return the stored result without rerunning work. Fingerprints bind subscription, version, operation, scope and options. Unlimited analyses/runs use lifetime periods, not daily caps. Receipts are bounded; future simulator consumers should store run IDs rather than full large reports.

CSV and Full comparison CSV exports share the approved daily CSV budget. Windows start at 00:00 Africa/Lagos, stored in UTC, with no rollover. Paid upgrades apply the new ceiling to today's already-used count rather than resetting it early; new receipts retain their own grant provenance. Basic cannot export or request agreement/custom dates. Full permits those capabilities; raw history and comparison history retain their distinct bounds. Consumers still require settled publications, authentic odds, sample sizes, sufficient evidence and risk-adjusted evaluation; permissions do not fabricate metrics or guarantee returns.

API diffs: `GET /api/v1/plans` now exposes four approved snapshots (`features.schema_version=simora.access.v1`, explicit null unlimited limits, Pro/Max `interval_code=thirty_days`), with `purchase_available=false`. Protected model/best/history/detail/comparison/preferences/simulation/performance preflight now returns the appropriate 401/403/422 access errors before the old missing-persistence guard; unavailable storage/policies remain 503. Comparison `metrics` is allowlisted; `export=csv` requires Full. Recognized family codes and comma-separated model/market scopes are enforced. Opaque IDs still require real repository ownership/family resolution when those readers exist; no opaque record data is returned now.

Authorized analytics/report/simulation requests still return **503** when canonical persistence is missing, with no quota consumption or synthetic substitution. Public platform/market catalogs and the legacy demo history route retain their contracts. No payment, subscription mutation, real CSV writer, saved-strategy/configuration CRUD or executable trading route was introduced. Real handlers must apply returned constraints and atomically enforce stock capacity/meters; preflight alone is not a data reader or exporter.

The simulator independently checks store-derived outcome/retention limits on new/cached/owned reports, including after downgrade. Its accounting/rounding/bankroll/stake/loss/step rules remain unchanged; only approved bounded history/chart capacity increases. Its PDO history/run adapter remains unavailable. The browser simulator remains a separate demo.

## Tests actually run

```text
powershell -NoProfile -ExecutionPolicy Bypass -File bin/test-access.ps1
OK (20 tests, 107 assertions)

php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist
OK (174 tests, 736 assertions)
```

MySQL coverage includes fresh migration/upgrade order and idempotent rerun, approved snapshots/30-day metadata, session/RBAC/CSRF, concurrent same-key/last-slot races, ownership FKs, rollback, WAT reset windows, payment-ledger gating, once-only verified Trial, expiry, unlimited analyses versus daily exports, paid upgrade carryover/provenance, scoped API/metric denial before missing readers, and revoked-feature denial. Standard tests include exact 50/120/250/1,000-outcome accounting/chart bounds, forged caps/retention/owned reports and real old PHP/history HTTP regressions. Focused policy/simulator run: **85 tests / 257 assertions**. An earlier MySQL run passed 19/104 before revocation coverage increased it to 20/107.

An initial parallel full run timed out on the unchanged `/platforms` preview (174 tests, 731 assertions, one failure/one warning). No threshold or product code was relaxed. After the other fixture/cache regression scripts completed, the full suite reran without concurrent SSR/cache readers and passed 174/736 in 38.617 seconds. The precise timeout cause was not profiled; this is not load/SLO certification. The isolated 20-case run took 2:50.581 on this host.

Additional final exit-0 checks: `php tests/migration-checksum.php`, `mysql-prerequisites.php`, `identity.php`, `catalog.php`, `billing.php`, `platform-details.php`, `predictions-hub.php`, `auto-trade-risk.php` all printed their respective passed messages. `node tests/history-load-more.cjs`, `platform-interactions.cjs`, `auto-trade-draft.cjs` passed. Scoped PSR-12 passed without errors/warnings; PHP lint passed for all seventeen added/modified PHP files. Formatting findings were corrected; diff whitespace checks passed with existing LF/CRLF notices.

Not run: hosted CI/deployment, browser HTTPS login/rotation, admin configuration UI, real provider verification/webhooks/customer lifecycle, real exporters, strategy/configuration persistence, actual published-model replay, numerical workers, direct-SQL hardening, load or restore drill. Unit histories and confirmation fixtures never enter live data or model evaluation.

## Status, rollback and restart

Approval and allocations are resolved. Catalog publication and backend policy/quota/numerical constraints are implemented and tested. Production release still depends on identity issuance/TLS/roles, independently verified payment processing, model/source/report/publication/simulator persistence, retained-source rights and database immutability review. Admin UI, stock-capacity consumers, real exporters and cross-request prediction caching are not declared operational by policy metadata. Later admin changes require a new approved audited policy version; never rewrite v1 agreements. Every plan's live execution remains disabled.

Prefer code rollback retaining additive schema, six-entry ledger, migration/checksums, approval snapshots and audit. Remove only this continuation's hunks, preserving earlier Phase 7/8/9 work; disable affected callers rather than mixing old/new receipt or simulator-store contracts. Keep schema-aware runner/verifier support. No customer access was seeded and no financial/provider/worker was activated.

Database reversal is not automatic/transactional because MySQL DDL commits; it requires backup and review of later real receipts/subscriptions/grants with a separately approved additive reversal plan. Never delete/rewrite applied `080`/`081` or remove historical attribution/FKs to match an old checkout. Scratch databases and their temporary grants were cleaned up by the harness.

Restart: inspect Git/ledger; run `php bin/migrate.php 081_approved_access_policy` idempotently, `php bin/verify-database.php`, the isolated access harness and default PHPUnit suite. Activate no customer access from unverified callbacks. Continue owning integration/release work only when explicitly requested; no automatic next phase.
