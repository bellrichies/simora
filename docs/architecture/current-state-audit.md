# Current state audit

Audit date: 2026-11-06. Scope is the local checkout, not a deployed environment. Source: [V4](../SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md), original README and requested module docs, `database/schema.sql`, contracts, controllers, services, repositories, config and tests. [Conflicts](source-contract-conflicts.md) and [105-entity traceability](../traceability.md) complement this audit.

## Git and infrastructure

`git status --short --branch` initially: `main...origin/main`; deleted `docs/SIMORA_BACKEND_BLUEPRINT.md`, modified `docs/support.md`, and untracked `docs/SIMORA_BLUEPRINT_GAP_ANALYSIS.md`, `docs/SIMORA_CANONICAL_TABLE_REGISTER.csv`, `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`. These pre-existing changes were preserved. `git log -1` returned `83f6f1e` (2026-11-05, backend documentation). No claim is made that this matches any old reference commit.

Confirmed: PHP 8.2.12 CLI, Node 26.13.0, npm 11.20.0; local XAMPP MariaDB 10.4.32 accepts a read-only root connection on 127.0.0.1:3306. `SHOW DATABASES` found no Simora-named DB. No DB environment variables named `DB_*` or `MYSQL*`, DB configuration, migration files or migration runner found in this checkout. A differently named or remote database cannot be ruled out. No actual Simora ledger, tables, constraints or deployed version were inspected. MySQL 8 verification is unavailable. No schema was applied.

`database/schema.sql` defines eight reference tables: `platforms`, `leagues`, `teams`, `prediction_models`, `markets`, `league_weeks`, `football_matches`, `predictions`. Its FKs are leagues→platforms; league_weeks→leagues; football_matches→league_weeks and two teams; predictions→football_matches, markets, prediction_models. The file is not a migration and asserts it is unapplied by the demo. V4 needs additional relations and different ID types; exact equivalence to any physical DB is unverified. No migration or FK changes in this phase.

## Routes, services and data shapes

`public/index.php` wires `JsonFixtureStore`, `DemoPlatformDataRepository`, clock, configuration, `PlatformDetailsService`, `DashboardDataService` and `PredictionsHubService` with a manual PSR-4-style autoloader. `DashboardController` dispatches SSR: `/`, `/dashboard`, `/overview`, `/platforms`, `/predictions`, `/plans`, `/wallet`, `/referrals`, `/reviews`, `/support`, `/settings`, `/notifications`, `/results`, and `/{sportybet|betpawa|betking}`. Unknown paths yield 404 through the layout. The controller explicitly limits eight named content pages to GET/HEAD; other paths do not have a global method guard. Views remain server rendered.

| Entry | Controller → service/repository | Input and output shape |
| --- | --- | --- |
| `GET /api/platforms/{slug}/predictions` | `PredictionsController` → `PlatformDetailsService` → `PlatformDataRepository::find` | `league`, `market` (label), `week`/`round`, `status`, `stake`, `page` and other details query inputs; response `{data: rows, error: null, meta: {page,next_page,total,page_size,demo,source}}`. `show` is removed for API pagination. Each row is whitelisted: IDs for prediction/match/week/league/market/model/teams, names and kits, week, teams, pick, `odds_hundredths`, scores/status, historical rate/sample and kickoff labels. Unknown platform/league 404; non-GET 405; data errors 503, all with `{data:null,error,meta:null}`. |
| Platform SSR | `DashboardController` → `PlatformDetailsService` | Same query plus `show`, `pick`, preview `access`; returns details array, history, quota, demo simulation and picks. Page bookmarks translate `page` to `show`. |
| Overview/platforms/predictions | `DashboardController` → `DashboardDataService`, `PredictionsHubService` | Fixture account, catalog/overview and filtered hub arrays; optional `PlatformLeagueCatalog` and `PlatformOverviewRepository` provide optimized access. |
| Plans | Controller constructs `BillingService` → `DemoBillingRepository` | Fixture billing read model; non-mock billing config returns unavailable. |
| Wallet/referrals/reviews/support/settings | Controller constructs matching `*PreviewService` | PHP fixture arrays + query input → display arrays; no persisted user mutations. |

There are no DTO classes: PHP arrays and the legacy whitelist are the actual data contract. `PlatformDataRepository::find` declares `leagues`, `results`, optional `upcoming`, `picks`, `quota`, `source`; see [data-contract](../data-contract.md). `Clock::now`, `BillingRepository::getData`, `PlatformLeagueCatalog::listLeagues`, `PlatformOverviewRepository::overview` are additional interfaces. `UnavailablePlatformDataRepository` exists but is not wired by the front controller. Preview services and fixtures are constructed inside the controller, so full DI/thin-controller target is not yet met. `MarketSettlement` supports six binary score predicates; it does not implement revision/void workflows. `RiskAssessment` is a preview domain component, not a provider execution engine.

## Migration dependency DAG (proposed, none present or applied)

Edges are prerequisites → migration, from V4 §7. The physical order is 000, 010, 020, 030, 040, 050, 051, 052, 053, 054, 055, 060, 061, 062, 070, 071, 080, 090, 100, 110, 120. Independent entries still retain the prescribed monotonic execution order.

| Migration | Depends on | Scope |
| --- | --- | --- |
| 000_schema_ledger | none | Ledger/bootstrap |
| 010_catalog | 000 | Catalog, eight markets, reference reconciliation |
| 020_identity | 000 | Identity, RBAC, sessions, audit |
| 030_source_provenance | 010 | Rights, observation, mappings, outbox |
| 040_results_odds | 010, 030 | Result revisions, odds; defer accepted-current revision FK |
| 050_model_lab | 040 | Models, datasets, forecasts and backtests; avoid non-null version/training cycle |
| 051_feature_registry | 050 | Feature definitions |
| 052_experiments | 051 | Experiments and folds |
| 053_forecast_runs | 052 | Forecast runs, additive score FK |
| 054_ensemble | 053 | Component lineage |
| 055_model_health | 054, 020 | Health, deployment approvals |
| 060_prediction_selection | 055 | Rankings, publication and settlements |
| 061_model_comparisons | 060 | Comparisons |
| 062_champion_decisions | 061 | As-of decisions |
| 070_simulation | 060, 020 | Simulation ledger |
| 071_model_preferences | 020, 050 | Preferences |
| 080_access_notifications | 020 | Plans, usage, notifications |
| 090_payments_wallet_gated | 080 | Payment and optional wallet |
| 100_experience_gated | 020; 090 for financial rewards | Experience modules |
| 110_chat_optional | 020 | Chat |
| 120_auto_trade_execution_gated | 020, 060, 080 and independent approvals | Execution tables only after gates |

This DAG is a specification, not executable migration history. Existing deployed versions must never be renamed/replayed; reconcile additively after introspection. MySQL DDL can implicitly commit; backup and restore, not assumed transaction rollback, is required.

## Capability evidence

Confirmed: deterministic synthetic preview, local page rendering and history contract in code; mock billing and client-side Auto Trade draft. Proposed: MySQL/PDO, ledger, CI, queue/outbox, immutable lineage, eight-market models, entitlements and v1 APIs. Unavailable/unverified: configured application DB, ledger introspection, deployed runtime, production models and source feeds. Externally blocked: provider access/retention/training/publication rights, payment and trading approvals; live source and execution cannot be claimed. CLI runtime does not establish deployment compatibility. No release or profitability claim follows from passing preview tests.

Baseline command results are in [implementation status](../../IMPLEMENTATION_STATUS.md). No route, API, schema, migration or runtime behavior was changed by this audit.
