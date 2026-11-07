# Implementation status

## Phase 11 — gated experience backend implemented; launch approvals remain open

Implemented only referrals/reviews/support and optional shared community-chat infrastructure, preserving PHP SSR demos, legacy history, all eight markets and prior uncommitted work. The user's clarification keeps the existing referral commercial/attribution terms as unapproved proposals and preserves community chat scope. No tracking, referral discount/reward/redemption/reversal, payout, Max priority routing or live widget transmission was activated.

Applied `100_experience_gated` locally: eight canonical tables, fifteen FKs and the existing outbox/audit extensions. Verification: **59 InnoDB tables, 93 FKs, eight successful checksummed migrations, eight markets**. Optional `110_chat_optional` (three tables/six FKs) remains unapplied locally and was rehearsed only in isolated test schemas. All experience feature flags are false; application experience tables are empty. Financial invariant verification remains clean with zero payments/journals.

New services enforce real HTTPS sessions/RBAC/CSRF, verified authors, permanent one-account reviews, independent moderation/publication, owner/assigned-agent support, protected internal/security notes, current chat consent, bans/mutes, minimal projections, bounded input/pagination and shared atomic account rate limits. Focused policy tests **10/13**, final isolated MySQL **11/100** and full default PHPUnit **201/828** passed, including private moderator-ID projections and a two-session/two-process rate race. Scoped PSR-12 and 20-file syntax checks passed. Earlier HTTP/browser timeouts, the passing unchanged full rerun, browser checks and remaining dependencies are recorded in the [Phase 11 delivery report](docs/architecture/experience-phase.md); no timing threshold was relaxed.

All modules remain disabled pending referenced privacy/moderation/retention/operational approvals and roles; chat also requires named privacy/moderation owners and approved abuse/rights procedures. No production UI, retention/erasure workflow, referral financial lifecycle, network flood defense, Redis delivery or staffed launch is declared operational. Phase 12 remains unstarted. Changed paths, exact API/migration/FK contracts, actual outputs, regressions/risks and rollback/restart instructions are in the linked report.

## Phase 10 — Paystack/subscription credit (2026-11-06)

Latest status: implementation verified locally; production release blocked. The user selected Paystack and approved subscription-only credit accounting, no payouts. See [the Phase 10 delivery report](docs/architecture/paystack-subscription-credit-implementation.md) for changed paths, API contracts, all 22 added FKs, actual tests, unresolved dependencies and rollback/restart details. V4 remains authoritative; AGENTS' obsolete source path is corrected.

Migration `090_payments_wallet_gated` applied locally after isolated rehearsal, extending the existing canonical outbox: seven migrations, 51 InnoDB tables, 78 FKs, all eight markets. Payment capture/independent verification, reconciliation, fixed-term purchase/renewal, atomic double-entry journals/audit/notification and stronger payment-backed access are implemented. Sandbox proof never grants production entitlements. Existing PHP SSR and legacy history are preserved; their billing preview stays isolated. Default payments remain disabled and no real provider operation, charge, customer subscription or payout occurred.

Latest completed checks: default PHPUnit **190 tests / 786 assertions**; isolated payment MySQL **17 / 87**; isolated access MySQL **20 / 107**; scoped PSR-12 clean; migration/database/financial invariant checks passed. The final syntax run is recorded in the linked report. Merchant credentials/test delivery, live policies/approval, Redis/outbox operations, append-only DB permissions and live release/security/restore evidence remain unresolved. No later phase started.

## Phase 9 continuation: approved V1 policy published; release dependencies remain

The user approved exact Trial/Standard allocations, all-three/all-eight Pro/Max access, fixed 30-day terms and Basic/Full semantics. Implemented paid model/market/history/metric checks, WAT daily CSV budgets, unlimited lifetime analyses/runs, revocation and upgrade receipt provenance. **Applied `081_approved_access_policy`**, publishing four approved policy/price versions: **42 tables, 56 FKs, six successful checksummed migrations, eight markets**. No customer subscriptions/grants/usage/notifications were activated. Execution is disabled for every plan.

Simulator/store/chart support verified 50/120/250/1,000-outcome limits with current retention checks for new/cached/owned reports. Final tests: isolated MySQL **20 tests / 107 assertions**, standard PHPUnit **174 tests / 736 assertions**, eight standalone PHP checks, three JS checks, scoped PSR-12 and lint of seventeen PHP files passed. One parallel `/platforms` timeout was followed by a passing sequential full rerun without relaxing tests. See [continuation report](docs/architecture/approved-access-policy-implementation.md) for paths, migration/FK/API diffs, outputs, dependencies and rollback/restart.

Policy approval is resolved. Identity/TLS/role provisioning, trusted payment handler, canonical model/report/publication/simulator persistence, source rights and database-level immutability gates remain. Admin UI, real exports and strategy/configuration stock-capacity consumers are not declared implemented by storing their limits. Authorized requests to missing analytics readers remain 503 with no usage consumption. No next phase or execution flow was activated.

## Historical initial Phase 9: MySQL implementation verified; policy/release gates open

Policy follow-up: received the user's recommended V1 matrix, explicitly pending final approval. Recorded it in [the policy proposal](docs/architecture/access-policy-v1-proposal.md). Its six-market count conflicts with V4's eight; Trial/Standard model/market identities and Basic/Full comparison semantics are unspecified, and the proposed simulator sample limits exceed the current cap. No grants, versions, runtime code, APIs, migrations or FKs changed in this documentation continuation. Policy reconciliation/approval remains open.

Implemented database-verified sessions/RBAC/CSRF for new access routes, append-only approved plan publishing, exact scoped grants/revocation, transactional/idempotent quotas and in-app owner notifications with delivery/audit records. Added and **applied locally** `080_access_notifications` (ten canonical tables, fifteen FKs, nullable session CSRF digest); verifier now reports **42 tables, 54 FKs, five checksummed migrations, eight markets**. Four disabled plan names and six unassigned permission codes are seeded; no plan versions/prices, subscriptions, entitlements, usage or notifications were created for application users. Prior Phase 7/8 edits and PHP SSR/legacy history are preserved.

Actual tests: isolated MySQL PHPUnit **13 tests / 62 assertions**, including concurrent last-slot and duplicate-key races, passed; standard PHPUnit **148 tests / 658 assertions** passed; eight PHP verifier/regression commands, three JavaScript checks, scoped PSR-12 and lint of all twelve changed PHP files passed. See [Phase 9 report](docs/architecture/access-notifications-phase.md) for paths, migration/FK inventory, API diffs, output, corrected failures, rollback/restart and risks.

Open gates: the approved feature/quota/reset matrix is unresolved (asked the user; unspecified grants remain disabled); production session issuance/TLS/role provisioning and direct-SQL plan immutability require review. Trigger-based immutability was rejected by the isolated MySQL privilege boundary; no global setting or privilege was broadened. Only service-level append-only plan publication is implemented. No billing/provider/external notification sender or trading execution was enabled. Continue this phase's gates only when authorized; no next phase started.

## Phase 8 strategy simulator: numerical core tested; operational completion blocked

Implemented the versioned server-only flat/Martingale/recovery engine with exact checked integer arithmetic, model/version/market-scoped point-in-time publication snapshot validation, recorded odds/verified-fee accounting, caps/refusals, next hypothetical stake, exact equity series and server SVG. Added transactional repository contracts, owner/authorization/idempotency application service and an explicitly unavailable production adapter. No demo history substitutes, wagers, API activation, migrations or FK changes. Existing Phase 7 edits are preserved.

Actual checks on 2026-11-06: MySQL verifier succeeded (8.4.13, same four ledger entries, 32 tables, 39 FKs, eight markets); full PHPUnit **147 tests / 643 assertions** passed, including 59 simulator cases; five standalone PHP regression scripts, three JavaScript checks, scoped PSR-12 and lint of all 11 new PHP files passed. See [Phase 8 report](docs/architecture/strategy-simulator-phase.md) for exact paths/output, policy choices, one corrected test expectation, migration/FK dependencies, API status, remaining risks and rollback/restart.

Production completion is blocked by missing `060` publication/settlement parents and `070` persistence, trusted history export and retained source rights, web authentication/entitlement integration, provider fee/void contracts and risk policy acceptance. The canonical simulator API remains 503; the existing browser UI remains a separate demo. Next action requires explicit authorization of the owning prerequisites and continuation of this phase. No subsequent phase started.

## Phase 7 API/SSR: partial, blocked

Added isolated V1 JSON routing/catalog and explicit dependency guards, preference validation/scope precedence primitives, eight-market SSR configuration with empty states for missing history, and real HTTP legacy regressions. Preserved the original PHP views, legacy history DTO/string errors, checked-in synthetic fixtures and Auto Trade disablement. No migration, FK or business data changed. This is **not** a complete operational API/preferences integration: authenticated entitlements/CSRF, canonical model/publication/ranking persistence and preference FKs/optimistic writes/audit remain blocked by absent prerequisites. Protected routes fail closed with 503 and never serve demo analytics or write preferences.

Actual checks on 2026-11-06: database verifier exit 0 (MySQL 8.4.13, four successful checksummed migrations, 32 tables, 39 FKs, eight markets); PHPUnit **88 tests, 460 assertions** passed; all ten listed standalone PHP scripts and seven JavaScript checks passed; scoped PSR-12, lint of all 14 changed PHP files and diff whitespace checks passed. See [Phase 7 report](docs/architecture/api-ssr-phase.md) for exact changed paths, endpoint/status diffs, command output, initial corrected failures, unrun tests, FK/dependency escalation and rollback/restart plan. No subsequent phase started. Next action: resolve the owning prerequisites and resume Phase 7 only when explicitly authorized.

## Ingestion phase: incomplete, disabled

Added `database/migrations/030_source_provenance.sql`, `src/Domain/Ingestion/{GrantPolicy,PayloadEvidence,Idempotency}.php`, `tests/ingestion.php`, and `docs/architecture/ingestion-phase.md`; modified `src/Infrastructure/MigrationRunner.php` for the catalog prerequisite and source-table preflight. No migration was applied, no API or UI changed, and no provider, scraping, worker, Redis queue, or outbox dispatcher was activated. The migration defines relationships but has not been exercised; the isolated primitives are not an operational ingestion system. The FK report, missing transactional scope checks, blockers and rollback plan are in the ingestion phase document.

Actual checks: `php tests/ingestion.php`, `php tests/catalog.php`, `php tests/identity.php`, `php tests/platform-details.php` and `php tests/predictions-hub.php` passed. `php -l` passed for new ingestion PHP files, test and runner; `git diff --check` returned no whitespace errors with the earlier CRLF warning. `php bin/migrate.php 030_source_provenance` against local MariaDB returned exit 1: `MySQL 8.0.16 or later in the 8.x series is required; found 10.4.32-MariaDB`, before DDL. No MySQL 8 migration, transaction, FK, cursor, outbox, Redis, concurrency or provider integration test was run. The earlier catalog and identity blockers remain.

## Identity phase: incomplete, disabled

Added `database/migrations/020_identity.sql`, `src/Domain/Identity/{AccessPolicy,Credentials,Csrf,SessionToken}.php`, `src/Repositories/IdentityRepository.php`, `tests/identity.php`, and `docs/architecture/identity-phase.md`. Modified `src/Infrastructure/MigrationRunner.php` to support identity's ledger-only dependency and updated catalog operation documentation. No migrations applied, no routes or APIs changed, no billing activation. The existing preview remains unauthenticated; these disconnected primitives must not be treated as secure SSR authentication. The full remaining security and integration work, FK report and rollback plan are in the identity phase document.

Actual tests: `php tests/identity.php`, `php tests/catalog.php`, `php tests/platform-details.php`, and `php tests/predictions-hub.php` passed; PHP lint passed for the new PHP files and modified runner. `git diff --check` returned no whitespace errors (Git warned of LF-to-CRLF conversion for a pre-existing modified file). `php bin/migrate.php 020_identity` against the local MariaDB returned exit 1 with `MySQL 8.0.16 or later in the 8.x series is required; found 10.4.32-MariaDB` before DDL. No MySQL 8, migration, session HTTP, browser, security, concurrency, or RBAC integration test was performed. See below for earlier baseline and catalog blockers.

## Catalog phase: partial, blocked on database verification and upgrade

Added `database/migrations/000_schema_ledger.sql`, `database/migrations/010_catalog.sql`, `bin/migrate.php`, `src/Infrastructure/MigrationRunner.php`, `src/Domain/MarketCatalog.php`, `src/Domain/SourceScopedId.php`, `src/Infrastructure/LegacyCatalogAdapter.php`, and `tests/catalog.php`; modified `src/Domain/MarketSettlement.php`. See [catalog phase](docs/architecture/catalog-phase.md) for FK report, operation and reconciliation plan. No migration was applied; no API or live source, model or trading behavior changed. Existing preview still has six configured markets; canonical market definitions and settlement now support eight. The identity adapter is not yet an integrated legacy database/read-model adapter and the reference-schema upgrade is not implemented. Market-rule immutability has not been enforced operationally. Phase is not complete.

Actual checks (2026-11-06): `php tests/catalog.php` passed boundary/complement and source-scope tests; `php tests/platform-details.php`, `php tests/predictions-hub.php`, `php tests/auto-trade-risk.php`, `php tests/billing.php`, `php tests/review-policy.php` passed; recursive `php -l` on src, bin and tests passed; `node tests/history-load-more.cjs`, `node tests/platform-interactions.cjs`, `node tests/mobile-navigation.cjs` passed. `php bin/migrate.php 010_catalog` with the local MariaDB connection returned exit 1, `MySQL 8.0.16 or later in the 8.x series is required; found 10.4.32-MariaDB`, before DDL. `docker info` failed because the Docker Desktop Linux engine pipe was unavailable. No MySQL 8 fresh install, upgrade, FK, idempotency, checksum, lock, restore or HTTP integration test was run. Existing browser/build baseline limitations below remain.

Next: obtain an isolated MySQL 8 test database and inspect the actual target schema/ledger; implement and review the additive legacy migration and adapter, then exercise fresh/upgrade/rollback and concurrency tests. Stop at this phase until its gates are met; no subsequent phase was started.

## Phase 0: audit delivered, exit gate blocked

The audit and traceability documents are prepared; Phase 0 is **not complete**. The V4 exit requires approved conflict resolutions and baseline evidence. The configured/deployed database and ledger could not be identified, MySQL 8 was not verified, and some browser checks did not complete. V4 also includes Composer/CI and `000_schema_ledger` in the baseline; no Composer or CI files, migration runner or ledger were implemented or verified in this documentation-only work. Do not interpret the target DAG as applied. No next phase was started.

Changed paths: `docs/architecture/current-state-audit.md`, `docs/architecture/source-contract-conflicts.md`, `docs/architecture/decision-records/README.md`, `docs/traceability.md`, `IMPLEMENTATION_STATUS.md`. Pre-existing user changes are listed in the audit and were preserved. Migrations added/applied: none. FK changes: none. API changes: none. Security/source/trading changes: none.

## Actual baseline commands and results

Commands run from repository root on 2026-11-06; tests are standalone scripts, not PHPUnit. PHP 8.2.12, Node 26.13.0, npm 11.20.0. A zero exit indicates the script reported success, not production readiness.

| Command | Exit / output |
| --- | --- |
| `php tests/auto-trade-risk.php` | 0 — Auto Trade risk checks passed. |
| `php tests/billing.php` | 0 — Billing tests passed. |
| `php tests/platform-details.php` | 0 — Platform dataset, service and integration-contract tests passed. |
| `php tests/predictions-hub.php` | 0 — Predictions hub tests passed. |
| `php tests/review-policy.php` | 0 — Review policy tests passed. |
| `node tests/auto-trade-draft.cjs` | 0 — Auto Trade preview draft tests passed. |
| `node tests/community-chat.cjs` | 0 — Community chat widget tests passed. |
| `node tests/history-load-more.cjs` | 0 — Load-more interaction tests passed. |
| `node tests/mobile-navigation.cjs` | 0 — Mobile navigation tests passed. |
| `node tests/overview-rings.cjs` | 0 — Overview doughnut values and responsive layouts verified. |
| `node tests/platform-interactions.cjs` | 0 — Platform interaction tests passed. |
| `node tests/recurring-countdown.cjs` | 0 — Recurring countdown tests passed. |
| `node tests/platforms-page.cjs` | Interrupted after initial hang; bounded rerun timed out at 30 seconds. No pass or failure established. |
| `node tests/referrals-page.cjs` | Timed out at 30 seconds. No pass or failure established. |
| `node tests/reviews-page.cjs` | Timed out at 30 seconds. No pass or failure established. |
| `node tests/settings-page.cjs` | Timed out at 30 seconds. No pass or failure established. |
| `node tests/support-page.cjs` | Timed out at 30 seconds. No pass or failure established. |
| `node tests/wallet-page.cjs` | Timed out at 30 seconds. No pass or failure established. |
| `npm run build:css` | Started in a 60-second bounded subprocess; no result was returned and the test runner was interrupted. Build completion is unverified. |

Additional read-only checks: `git status --short --branch` and `git branch --show-current` identified the checkout and existing changes; `git log -1 --format='%h %cI %s'` returned `83f6f1e`; `php -v`, `node -v`, `npm -v` returned the versions above. `C:\xampp\mysql\bin\mysql.exe --host=127.0.0.1 --port=3306 --user=root --connect-timeout=3 --batch --skip-column-names -e 'SELECT VERSION(); SHOW DATABASES;'` returned MariaDB 10.4.32 and no database named Simora. No application DB was identified and no ledger query was possible. A Python CSV check found 105 unique names and IDs 1–105; `rg` counted 105 traceability rows. These are documentation checks, not migration or functional tests.

## Blockers and restart

Identify the intended local/deployed MySQL 8 database and obtain authorized read-only access; inspect `SHOW CREATE TABLE`, schema migration ledger, checksums and constraints before proposing any upgrade. Resolve the V4 versus AGENTS source-path conflict and approve documented contract decisions. Obtain provider/source rights and other independent approvals before enabling gated functions. Investigate browser-test timeouts and rerun the full relevant suite. Design and verify ledger/runner, Composer and CI within an explicitly approved Phase 0 continuation, including a fresh and upgrade rehearsal where appropriate. No automated migration rollback is assumed for MySQL DDL.

Rollback for these changes: remove only the five newly created audit/status documents if desired; preserve the pre-existing user changes and untracked V4/register. There were no database, API or runtime changes to roll back. Restart by confirming the working tree and environment again, then address the blockers above; do not automatically start Phase 1.
