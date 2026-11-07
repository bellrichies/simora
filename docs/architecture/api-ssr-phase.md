# Phase 7 API/SSR implementation report

Date: 2026-11-06 (Africa/Lagos). Status: **partial, blocked; not release-ready**.

Authoritative specification: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 4, 11, 12, V16 and Phase 7. The user's explicit V4 instruction supersedes the older source path in AGENTS.md; that older file is absent. This report records implementation evidence and is not an alternate blueprint.

## Inspection and scope

Started on a clean `main` tree at `845cb7e` (`feature: Implementation of backend phase 4-6`). Inspected the front controller, SSR controllers/views/services, demo repository and fixture validator/generator, identity repository/session primitives, model-health/comparison reports, Composer/PHPUnit configuration, migration SQL and database verifier. Previous phase reports explicitly list canonical model/publication/settlement persistence and authenticated entitlement integration as outstanding dependencies.

Executed `php bin/verify-database.php` before editing. Exit 0; actual output included:

```text
verified_at: 2026-11-06T14:06:29Z
mysql_version: 8.4.13
timezone: +00:00
table_count: 32
foreign_key_count: 39
verified_migrations:
  000_schema_ledger
  010_catalog
  020_identity
  030_source_provenance
canonical_markets: 8
```

The verifier compares every ledger checksum/status, physical table inventory, market IDs, InnoDB engines and FK columns against migration files. This was a read-only check of the configured local database, not a fresh install or production audit. No model, publication, entitlement or preference tables are present in that inventory.

Implementation plan executed: isolate the V1 JSON dispatch from demo SSR; expose the source-independent catalog; register analytics routes with explicit dependency errors; implement preference validation/precedence without bypassing missing ownership/model persistence; expose eight SSR markets while freezing legacy fixtures; add HTTP/unit regression coverage; report incomplete gates. No earlier phase's persistence or security scope was implemented implicitly.

## Changed paths

Added:

- `src/Http/AnalyticsApiController.php`: exact method/path routing, static-before-dynamic precedence and dependency errors.
- `src/Http/ApiResponse.php`: structured JSON response, no-store, nosniff and HEAD body suppression.
- `src/Services/AnalyticsApiService.php`: catalog DTOs and bounded best-query validation.
- `src/Domain/ModelPreference.php`: strict input validation, normalized scope and precedence. This is a policy primitive, not an operational preference service.
- `views/components/model-access.php`: explicit production model/preference unavailability notice.
- `tests/ApiSsrTest.php`: catalog, method/route, validation, unavailable-dependency and preference policy tests.
- `tests/LegacyRoutesTest.php`: isolated loopback PHP server and real HTTP front-controller regressions; server is terminated after the suite.
- `docs/architecture/api-ssr-phase.md`: this report.

Modified:

- `public/index.php`: dispatch `/api/v1` before constructing or reading demo repositories; existing legacy controller remains in place.
- `public/assets/platform.js`, `tests/platform-interactions.cjs`: preserve unavailable simulation state during stake edits/submissions/increments when there are no recorded selections; regression coverage verifies no invented zero ROI appears.
- `config/app.php`: add `under_15` and `over_35` to existing SSR market configuration; retain every previous ID, label and default.
- `src/Infrastructure/DemoDatasetGenerator.php`: freeze generation to the original six-market fixture profile, including original market order/indexes. Additional supported markets do not create outcome-selected fixtures, odds or histories. Checked-in JSON remains byte-for-byte untouched and reproducibility tests pass.
- `views/platform.php`: model-access notice, explicit missing-pick explanation, unavailable hit rate and simulation message for markets with no stored history.
- `views/predictions.php`, `views/settings.php`: model-access notice; existing GET forms and browser-local settings remain.
- `tests/platform-details.php`: retain assertions for the original six histories and add empty-market/league assertions for the two additional markets.
- `phpunit.xml.dist`: register the API/SSR and HTTP suites alongside existing suites.
- `IMPLEMENTATION_STATUS.md`: Phase 7 evidence and blockers.

## Migration and FK report

Migrations added/applied: **none**. Tables, constraints, grants and ledger rows changed: **none**. Existing 32 tables and 39 FKs verified before implementation. No duplicate prediction, model or preference table was introduced, and existing migrations/checksums were not edited.

V4's `071_model_preferences` depends on `020` and `050`, requires a user FK and optional model FK, and a unique non-null `(user_id,scope_key)` plus optimistic row version. `050` and its canonical model parent do not exist here. Creating a preference table without that relationship, storing preferences in generic settings, or manufacturing model rows would break the requested contract. Consequently preference persistence, version conflict responses and transactional audit writes remain **blocked**, rather than being emulated in sessions/local storage or migrated ahead of prerequisites.

## API diff and current behavior

All V1 responses have `{data,error,meta}`. Error is an object with `code` and `message`; meta includes server-generated `request_id`, UTC `as_of`, and `data_mode: unavailable`. Catalog support does not imply connected sources. Responses use JSON, `Cache-Control: no-store`, and `X-Content-Type-Options: nosniff`. Unknown V1 paths return JSON 404; wrong methods return JSON 405 with Allow. HEAD has no response body and is not declared a supported read method.

| Method | Path | Implemented behavior |
| --- | --- | --- |
| GET | `/api/v1/platforms` | 200 static catalog; each provider explicitly unavailable |
| GET | `/api/v1/prediction-markets` | 200 all eight canonical rules with complement and rule_version 1 |
| GET | `/api/v1/platforms/{slug}/leagues` | 503 `source_unavailable`; unknown platform 404 |
| GET | `/api/v1/leagues/{id}/rounds` | 503 `source_unavailable` |
| GET | `/api/v1/rounds/{id}/matches` | 503 `source_unavailable` |
| GET | `/api/v1/matches/{id}` and `/api/v1/matches/{id}/odds` | 503 `source_unavailable` |
| GET | `/api/v1/models`, `/api/v1/models/compare`, `/api/v1/models/{id}`, `/api/v1/models/{id}/performance` | 503 `access_unavailable` |
| GET | `/api/v1/predictions/best` | 422 for invalid/ambiguous scope; otherwise 503 `access_unavailable` |
| GET | `/api/v1/predictions/history`, `/api/v1/predictions/{id}` | 503 `access_unavailable` |
| GET, PUT | `/api/v1/me/model-preferences` | 503 `access_unavailable`; no reads or writes attempted |
| POST | `/api/v1/backtests`, `/api/v1/simulations/strategy` | 503 `access_unavailable`; no jobs or runs created |
| GET | `/api/v1/backtests/{id}`, `/api/v1/simulations/{id}` | 503 `access_unavailable` |
| GET | `/api/v1/analytics/platforms/{slug}/performance` | 503 `access_unavailable`; unknown platform 404 |
| GET | `/api/v1/admin/models/health` | 503 `access_unavailable` |
| POST | `/api/v1/admin/models/{id}/training-runs`, `/activate-version`, `/suspend` | 503 `access_unavailable`; no administrative mutation |

These 503 paths are registered dependency guards, **not completed business endpoints**. Authenticated reads, CSRF, owner authorization, entitlement checks, queued writes, pagination/cursors and operational admin behavior are not asserted to exist. In particular, suspend is not an emergency operational control in this release. Duplicate preference and simulator aliases remain 404. Auto Trade execution has no enabled route/connector.

Best-query validation separates `model_mode` and `ranking_mode`, requires platform/league/from/to scope, validates all market IDs, canonical lowercase `model_code` in manual mode, exact UTC dates, scalar bounded inputs and limit 1..100. This does not establish that a model is eligible, released or entitled. No inference/ranking/source collection is run from a web request. No fabricated `no_selection` is returned: the missing ranking store prevents persisting its required auditable result, so the endpoint returns unavailable.

Preference policy validates scope/platform/market/model mode, manual family code and integer expected version; rejects unknown fields including supplied owner ID. Resolution is combined override, market, platform, global, then null (no approved product default invented). Only future owner-restricted rows may be passed to resolution. Release/entitlement checks and optimistic transactional persistence remain required before use. The policy is intentionally not wired to a write route while those checks are absent.

Legacy `GET /api/platforms/{slug}/predictions` retains original DTO fields, page pagination, demo metadata, string errors and method checks. Under 1.5 and Over 3.5 now resolve to the requested market with empty rows, rather than silently selecting the default. Existing PHP SSR URLs/design/forms remain; six-market demo data is isolated from V1 analytics. No confidence, odds, financial returns or production model access is fabricated for the two additional markets.

## Tests actually executed

Final PHPUnit command/output:

```text
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist
PHPUnit 10.5.66 / PHP 8.2.12
OK (88 tests, 460 assertions)
```

Includes the existing 40 model-health/comparison tests and 48 new API/preference/HTTP tests. HTTP checks cover `/`, `/dashboard`, `/platforms`, `/predictions`, all three platform boards, `/plans`, `/wallet`, `/referrals`, `/reviews`, `/support`, `/settings`, legacy pagination/non-overlap/error strings, additional market empty states, V1 JSON errors, method restrictions, cache headers and refusal of forged user/plan/CSRF headers. Rejecting every protected request with 503 proves no bypass through these routes; it does **not** prove successful authentication or valid-CSRF operation.

Standalone PHP commands, all exit 0:

```text
php tests/catalog.php             Catalog market and identity tests passed
php tests/identity.php            Identity primitive tests passed
php tests/ingestion.php           Ingestion policy and evidence tests passed
php tests/live-source.php         Live source domain tests passed
php tests/platform-details.php    Platform dataset, service and integration-contract tests passed
php tests/predictions-hub.php     Predictions hub tests passed
php tests/auto-trade-risk.php     Auto Trade risk checks passed.
php tests/billing.php             Billing tests passed
php tests/review-policy.php       Review policy tests passed
php tests/migration-checksum.php  Migration checksum regression test passed
```

JavaScript commands, all exit 0:

```text
node tests/history-load-more.cjs     Load-more interaction tests passed
node tests/platform-interactions.cjs Platform interaction tests passed
node tests/mobile-navigation.cjs     Mobile navigation tests passed
node tests/auto-trade-draft.cjs       Auto Trade preview draft tests passed
node tests/overview-rings.cjs         Overview doughnut values and responsive layouts verified.
node tests/recurring-countdown.cjs    Recurring countdown tests passed
node tests/community-chat.cjs        Community chat widget tests passed
```

PSR-12: `php vendor/squizlabs/php_codesniffer/bin/phpcs --standard=PSR12 src/Domain/ModelPreference.php src/Http src/Services/AnalyticsApiService.php tests/ApiSsrTest.php tests/LegacyRoutesTest.php` exited 0 with no errors/warnings. `php -l` passed for all 14 added/modified PHP files. `git diff --check` passed (Git emits existing Windows LF/CRLF conversion notices).

During development the first platform script run failed its assumption that every configured market has 120 synthetic history records. It was corrected to assert empty records for the two added markets while retaining original assertions and fixture reproducibility. Initial PSR-12 formatting/line-length findings were corrected. An intermediate PHPUnit run passed 85 tests/438 assertions; the final additional guarded-route coverage brought it to 88/460. No regression remains in executed checks.

Not run: hosted CI, the separate platforms/settings/wallet/referrals/reviews/support browser scripts, a dedicated eight-tab mobile visual review, CSS build, PHP 8.1 runtime, source/provider calls, MySQL preference concurrency/audit/rollback tests, authenticated browser E2E, model release or settlement/ranking integration, worker/container suites, security/load testing or deployment. Existing browser/other module tests are not represented as rerun unless listed above.

## Blockers, escalation and restart

Phase 7's complete contract cannot be delivered against the current dependencies: secure session issuance/rotation/CSRF and entitlement reads are not integrated; canonical model versions, published predictions, stored rankings, reports, settlement read models and `050` are absent; verified public source DTOs and grants are not connected. An approved model catalog is needed before preference model FKs, support/release validation and the model chooser can operate. Persisted no-selection decisions and per-model/per-market independently ranked results must come from their owning earlier phases. No fake implementation or alternate table has been substituted.

Escalation: these are prerequisite/contract gaps, not permission to silently expand Phase 7. Complete Phase 7 only after the owning persistence/security work is explicitly authorized and verified. Recommended next action is to resolve these prerequisites and resume this same phase; do not move to the next phase automatically.

Rollback: revert only the listed tracked edits and remove only listed new files if desired. The starting tree was clean; preserve unrelated later changes, `.env`, secrets, database volumes, vendor and worker artifacts. No SQL rollback, provider disconnection, model invalidation or queue cleanup is necessary because this change writes no business records, migrates no schema and starts no worker/service. Test-created PHP servers are stopped by teardown. Existing demo caches may be rebuilt automatically from unchanged fixture JSON/configuration.

Restart: inspect Git/ledger again, rerun the verifier and PHPUnit suite, wire approved canonical read repositories and owner/session/CSRF/entitlement middleware, then implement `071` against its real parent FKs and test transactional optimistic updates/audits including concurrent creation/update. Add real stored ranking/history readers and authenticated SSR preference/model selection only after these gates pass. Normal PHP reload/restart is sufficient when deploying code with opcode caching; no deployment was performed here.
