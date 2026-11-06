# Implementation status

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
