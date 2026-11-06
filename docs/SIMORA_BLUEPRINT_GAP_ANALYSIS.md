# Simora Blueprint Comparison and Gap-Closure Report

**Compared sources:**

- `SIMORA_BACKEND_BLUEPRINT_V3(1).md` — comprehensive full-application backend architecture with eight markets and 102-table register.
- `SIMORA_MULTI_MODEL_BACKEND_BLUEPRINT(1).md` — standalone model-focused architecture with three engine families, but an outdated six-market and competing schema design.

## Executive result

**Decision:** Preserve v3 as the canonical product-domain and storage foundation, integrate the standalone quantitative design, and issue one implementation-ready master. The reconciliation adds three logical tables for missing audit lineage, bringing the total to **105**. This is an architectural register, not a claim all tables are deployed.

## Source-by-source strengths and gaps

| Criterion | V3 full backend | Standalone multi-model | Reconciled master |
| --- | --- | --- | --- |
| Market coverage | Eight canonical markets | Six (omits Under 1.5 and Over 3.5) | Eight everywhere |
| Product domains | Identity, data, billing, wallet, social/support, gated trading | Mostly prediction domain | Full V3 scope retained |
| Ingestion/provenance | Comprehensive | Point-in-time emphasis | Unified authorized-source lifecycle |
| Model mathematics | Three engines in later amendment | Detailed Python/quant worker guidance | Versioned SPE, MLPE, BEVE with coherent joint distribution |
| Schema | 102 entities, with older superseded SQL still embedded | Competing names and additional tables | One canonical 105-entry register, no duplicate names |
| Migration sequence | Several generations of instructions; later supersession notes | Separate M001–M009 | One explicit prerequisite DAG |
| APIs | Product-wide routes plus new model APIs | Conflicting preferences/simulation aliases | Canonical names, legacy adapter preserved |
| Simulator | Whole-platform financial/permissions context | Detailed risk formulas | Model-specific, bounded, read-only and exact decimal |
| Model comparison | Skill/ROI/coverage mentioned | Common fixture cohort and uncertainty details | Matched skill, selected ROI, simulation kept separate |
| Audit trail | General model lineage and report snapshots | Forecast-run suggested | Adds forecast run, fold and champion decision audit |
| Execution safety | Explicit provider and auto-trade gates | Same, narrower | Remains disabled pending independent approval |

## Detailed gap register

All 38 items below have been carried into Section 2 of the unified master. A “resolution” is a documentation-level contract with test ownership, not software implementation.

| ID | Detected issue | Binding resolution | Action | Proof / owner |
| --- | --- | --- | --- | --- |
| R01 | Standalone model document assumes **six markets**; v3 requires **eight** | Eight registry and forecast outputs incl. `under_15`, `over_35`; preserve six existing IDs | REPLACE | Eight-score boundary and complement tests; MarketCatalog |
| R02 | `leagues`/`league_weeks` versus `virtual_leagues`/`virtual_rounds` | **Canonical v4** `virtual_leagues`/`virtual_rounds` with adapter translating legacy IDs; inspect actual deployed schema first | RENAME | schema introspection, legacy route tests |
| R03 | `odds_quotes` versus `market_odds_snapshots` | Only `market_odds_snapshots`, source market mapping and immutable quote provenance | RENAME | unique quote fingerprint + temporal tests |
| R04 | `model_feature_snapshots` versus `feature_snapshots` | `feature_snapshots` with `model_feature_definitions` and hash manifest | RENAME | point-in-time replay |
| R05 | `model_training_runs` versus `training_runs` | `training_runs` + `training_datasets` + experiments; no second training ledger | RENAME | model artifact lineage |
| R06 | `model_calibrations` versus `calibration_artifacts` | `calibration_artifacts`, coherent joint recalibration | RENAME | calibration holdout + complement tests |
| R07 | `model_forecasts` + `model_market_probabilities` versus `score_distributions` + `market_forecasts` | Use **one score distribution per fixture/model/run** plus **eight market forecasts** | RENAME | 8 FKs to one distribution |
| R08 | `prediction_candidates` vs `market_ranking_candidates` | Keep `market_ranking_runs`, `market_ranking_candidates`, `market_ranking_results` | RENAME | per-market no-selection and ranking |
| R09 | `prediction_publications` vs `published_predictions` | Only `published_predictions` as immutable decision ledger | RENAME | pre-kickoff publication test |
| R10 | `model_backtest_runs/results` vs `backtest_runs/entries` | Keep latter + immutable `model_comparison_reports`, add `evaluation_folds` | RENAME/ADD | common-cohort metrics parity |
| R11 | `model_performance_metrics` vs `model_market_metrics` | `model_market_metrics` and `performance_daily` with explicit population/scope | RENAME | row reconciliation to settlement ledger |
| R12 | Duplicate simulator URLs / account preference URLs | One canonical `POST /api/v1/simulations/strategy`; `GET,PUT /api/v1/me/model-preferences` | REPLACE | OpenAPI + route collision tests |
| R13 | Intro v3 SQL has `BIGINT match_id` while v3 authoritative ID is opaque string | Match canonical IDs `VARCHAR(128) ascii_bin`, with identical FK type/collation; bigints only internal records | REPLACE | MySQL `SHOW CREATE TABLE` |
| R14 | v3 has multiple precedence notes and obsolete migration order `021` plus `071` | This v4 register and dependency DAG alone is binding; no obsolete SQL or precedence puzzle | REPLACE | topo-sort migration checker |
| R15 | Forecast records need a stable batch/run identity, not just `(match,model,time)` | Add `forecast_runs` #103, link score distribution and worker job once | ADD | repeat jobs immutable/idempotent |
| R16 | Evaluation fold data hidden in JSON / lacks FK and leakage evidence | Add `evaluation_folds` #104 per experiment; explicit train/val/test availability windows and fixture universe hash | ADD | walk-forward split and embargo tests |
| R17 | Champion selection implicit and subject to hindsight | Add `model_champion_decisions` #105 with as-of decision, segment, evidence report and abstention | ADD | time-travel champion test |
| R18 | Ensemble weighted average called Bayesian without posterior | Genuine posterior/credible model or label regularized ensemble; cannot claim BEVE validated before implemented | REPLACE | inference/interval calibration |
| R19 | Source-time and ingest-time confused; collected-later old matches could leak | `source_published_at`, `observed_at`, `available_at`, `ingested_at` all distinct; no later input in historical replay | ADOPT/CLARIFY | late-backfill leakage test |
| R20 | Joint tail mass ambiguous | Adaptive/analytic tail preserving all eight event probabilities within tolerance | CLARIFY | sum/tail upper error tests |
| R21 | Missing quote and `best_value` pick inconsistently allowed | Probability-only forecast allowed; **no best-value selection** without verified quote | CLARIFY | 200 no_selection + cause |
| R22 | Outcome correction mutates retrospective winner or aggregate | Append source/result/settlement revisions; never mutate published candidate/odds | ADOPT | correction replay |
| R23 | “Most profitable” can be selected from different fixture samples | Forecast skill on common cohort, published ROI on selected cohort, strategy P&L separate | ADOPT/CLARIFY | denominators, coverage, CI |
| R24 | Staking progression can quietly exceed bankroll | Fixed-point bounded policy; stop on breach, don't cap and imply recovery | ADOPT | golden ledgers and limits |
| R25 | Payment/webhook/ledger business tracks omitted from standalone model blueprint | All v3 business modules remain, with separate authorization/approval gates | ADOPT | 105-table traceability |
| R26 | Auto Trade preview conflated with execution | Draft-only routes; **no live order connector** in analytics release | ADOPT | execution disabled integration test |
| R27 | Current repo state and provider rights not verified by source files | Local code audit, schema introspection and written rights are explicit release blockers | GATE | Phase 0 and source approval |
| R28 | Worker timeout, artifact corruption and queue replay can change model | Versioned protocol, checksum and atomic idempotency; no worker in web request | ADD/CLARIFY | retry/crash tests |
| R29 | Production and demo can share cache/report keys | Every observation, forecast, rank, metric and cache includes immutable partition | ADOPT/CLARIFY | synthetic quarantine tests |
| R30 | Calibration/market identity can be inconsistent for binary ML | All 8 derived from calibrated coherent joint score distribution, no independent incompatible binary heads | ADOPT/CLARIFY | market probability invariants |
| R31 | Auto best fails when evidence sparse or model suspended | `insufficient_evidence`, never silently fall back to unapproved version | CLARIFY | eligibility matrix |
| R32 | Published recommendations could be silently withdrawn with odds changes | Immutable published record; separately timestamp visibility/withdrawal status; settlement remains tied to original quote | CLARIFY | immutable publication tests |
| R33 | Deferred FKs and migration order can be lexically confusing | One monotonic dependency-ledger plan with explicit deferred circular-FK patch | REPLACE | create + upgrade test |
| R34 | User preferences unique nullable scope broken in MySQL | normalized non-null `scope_key` with unique `(user_id,scope_key)` | ADOPT | concurrent global + override test |
| R35 | Source rights can lapse after ingestion | Access rights checked for ingestion, retention, model training and public release, not only fetch | ADD | revoke/expiry/quarantine test |
| R36 | Trend/equity P&L may count duplicate overlapping fixtures | Include fixture-cluster exposures and model-policy provenance, warn on correlated selections | ADD | cluster/dedup test |
| R37 | API `mode` is ambiguous: manual/compare vs value/probability | Separate `model_mode` and `ranking_mode`; require scope and server entitlement | CLARIFY | request validation tests |
| R38 | Runtime assumptions conflict: repo PHP 8.1 vs design PHP 8.2+ | Verify host and tests before raising platform requirement; pin PHP/Python versions in CI | GATE | environment matrix |

## Highest-risk issues to address in Phase 0

1. **Existing database state is unverified.** `database/schema.sql` may be reference-only while local development has additional uncommitted migrations. Do not assume either is applied.
2. **Data rights are unresolved.** Provider catalog presence is not authorization to scrape, train, commercially display or place bets.
3. **Temporal provenance governs any profitability claim.** Historical results alone cannot prove that pre-event quotes or inputs existed at prediction time.
4. **Probability outputs must cover eight coherent markets.** The standalone six-market design cannot be implemented without changing the agreed product.
5. **A champion cannot be selected with future performance.** Store model champion decision, evidence report and as-of time.
6. **The original v3 contained obsolete SQL examples.** Only V4 canonical table register and migration DAG should govern new changes.

## Recommended handoff

Use `SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md` as the single source of truth, `SIMORA_CANONICAL_TABLE_REGISTER.csv` for mechanical table auditing and this report for architectural review. Phase 0 must inspect the actual checkout and execute baseline tests.

## Verification actually performed

- Parsed and compared the two uploaded Markdown files.
- Extracted **102** uniquely numbered V3 tables (IDs 1–102).
- Added **three** new unique entities (IDs 103–105).
- Verified that the master contains all **105 unique names**.
- Extracted **38** reconciliation decisions.
- **Not performed:** database execution, code test suite, migrations, real provider-access verification or production model training.
