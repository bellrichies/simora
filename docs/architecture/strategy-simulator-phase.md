# Phase 8 server strategy simulator

Date: 2026-11-06 (Africa/Lagos). **Numerical core implemented and tested; operational phase incomplete, blocked.**

Specification: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 4, 10 (returns/exposures), 12, 13, V13 and Phase 8. This is implementation evidence, not another backend blueprint.

## Inspection and plan

Inspected the current Git tree before editing. The previous Phase 7 changes were already present, including untracked API/SSR classes/tests; they were preserved. Inspected V4 simulation/relational contracts, the existing SSR/JavaScript demo simulators, API route guards, model comparison and operator authorization contexts, repository transaction patterns, Composer/PHPUnit configuration and test doubles.

Read-only `php bin/verify-database.php` inspection at `2026-11-06T14:27:03Z` succeeded against MySQL **8.4.13**, UTC, **32 tables / 39 FKs / eight markets**. The checksummed successful ledger remains `000_schema_ledger`, `010_catalog`, `020_identity`, `030_source_provenance`. Canonical publication/settlement and simulation tables are absent. No migration or application record was written.

Plan executed: implement a bounded integer accounting policy and three replay strategies; require a pinned model/version/market publication snapshot; add transaction/idempotency/owner storage interfaces with a production-unavailable adapter; produce chart data and server SVG; exercise golden/edge/security-contract tests and existing route regressions. Do not implement missing earlier phases, substitute demo fixtures or enable wagering.

## Changed paths

Created:

- `src/Domain/Simulation/FixedPoint.php`: integer decimal parsing, checked arithmetic, half-up/ceiling rounding and decimal ratio strings.
- `src/Domain/Simulation/SimulationScope.php`: one model family/version/platform/league/canonical market and eligible partition.
- `src/Domain/Simulation/SimulationConfig.php`: bounded, strictly typed strategy settings and hypothetical next inputs.
- `src/Domain/Simulation/StrategySimulator.php`: authoritative numerical replay, inclusion/accounting ledgers, refusal reasons, caps, summary and next hypothetical stake.
- `src/Contracts/SimulationRepository.php`, `src/Contracts/SimulationStore.php`: transactional snapshot/authorization, immutable owner-key report, selected history, append/audit and owner-read contracts.
- `src/Repositories/UnavailableSimulationRepository.php`: explicit failure while canonical history/persistence are absent; no demo fallback.
- `src/Services/SimulationService.php`: constructor DI, server-side authorization before history access, rechecked model entitlement, immutable/idempotent replay orchestration and owner reads.
- `src/Presenters/SimulationChart.php`: SSR-ready accessible SVG from the same exact bankroll series, without browser financial calculations.
- `tests/StrategySimulatorTest.php`, `tests/Support/SimulationTestRepository.php`: unit golden ledgers and explicitly non-production storage double.
- `docs/architecture/strategy-simulator-phase.md`: this report.

Modified `phpunit.xml.dist` to add the strategy suite, preserving Phase 7 and existing model suites; modified `IMPLEMENTATION_STATUS.md` to record this phase. No other Phase 7 files, PHP views, browser demo logic, workers or secrets changed in this phase. No dependency was added.

## Accounting policy and output

Version: **`simora.strategy.v1`**, 64-bit PHP only. Monetary configuration is integer minor units, 1..1,000,000,000 per field (10,000,000 NGN at the project's default minor-unit scale). `extra_target_minor` may be zero. Odds are decimal **strings**, up to six places, strictly above one and no more than 1000. Invalid/missing recorded prices stop replay. This is a bounded simulator price policy, not a change to the database's DECIMAL contract. Last N is 1..100; there is no entitlement that expands it in this release.

All accounting uses checked integer rational arithmetic. Multiplication checks overflow before PHP can promote a product to float. Win profit is `round_half_up(stake * (odds - 1))`; verified fees are `round_half_up(gross_profit * fee_rate)`; loss net is negative stake. Gross payout includes principal and is reported separately from fees. Void refunds stake only under an explicit `refund_stake_no_fee` contract and neither advances nor resets the loss streak. Supported fee contract is `positive_profit` with a named verified policy and decimal rate in [0,1]. Unknown contracts or fees stop replay; zero fees are never silently assumed for recorded history.

- Flat: base stake, rounded upward to the declared minor-unit increment.
- Martingale: base times integer multiplier to current consecutive losses, rounded to increment **once**, not recursively rounded. Multiplier is an integer 2..1000; maximum allowed consecutive-loss step is bounded 1..100. A win resets its streak; a void preserves it.
- Recovery: target is outstanding cycle loss plus explicit extra target. With zero fees, this is exactly `ceil_to_increment(target / (odds - 1))`. With verified profit fees, the conservative extension first ceilings `target / (1 - fee_rate)` to a gross-profit minor unit, then ceilings stake for the recorded odds and increment. This can require a slightly larger stake than a continuous after-fee formula. Reset the cycle only after realized cycle net **after fees** reaches the declared extra target. When target is zero, use base stake. A 100% fee contract with a positive target refuses as no after-fee return. Unrepresentable proposed arithmetic refuses with null attempted amount and an explicit overflow reason.

These versioned rounding, fee-extension, multiplier and risk defaults require product/risk acceptance before activation; they are not claims of provider-approved policies. Fractional multipliers and fee bases other than verified positive-profit fees are unsupported. No money or odds fields are calculated with PHP float or JavaScript.

For every attempted position, check maximum stake, available bankroll, finite Martingale steps and the maximum net-loss floor relative to starting bankroll, including the entire proposed losing stake. Stop on breach, retaining the attempted/refused stake and unchanged bankroll; never clip a stake to imply recovery. Maximum loss here is a floor relative to initial bankroll, not a drawdown-from-peak cap. Peak/trough, maximum drawdown, maximum stake, ruin and loss streak are reported separately.

The next hypothetical stake is computed after the last replayed selection, even after a last loss, using the same caps. Flat/Martingale sizing does not require hypothetical odds; recovery requires explicit `next_odds_decimal` **and** `next_fee_rate_decimal`. Neither is invented, and neither changes historical prices or fees. A stopped replay cannot yield an allowed next position. An allowed verdict means only that this hypothetical sizing passes the numerical policy; it is not source/model authorization or an order instruction.

Result includes policy version, full pinned scope, as-of clock, normalized config, history SHA-256, chronological inclusion snapshot including settlement revision IDs, per-entry exact stake/payout/fee/net/bankroll, refused attempts, total staked/payouts/fees, net result, ROI ratio or null, ending/peak/trough bankroll, drawdown, max stake/loss streak, ruin, termination, next hypothetical verdict and risk warnings. Exact amount fields and six-place ROI ratios are strings. ROI denominator is the sum of all hypothetical stakes replayed, including refunded void stakes; no stakes means null ROI. The report does not assert any real return or profitability.

Chart points include starting bankroll and one point per accepted replay entry. Refusals add no fabricated movement. The SVG scales those same amounts to integer pixel coordinates and handles a single starting point for empty history. It labels the chart hypothetical; it is ready for SSR integration but is not yet placed into the public demo UI.

## Published-history, point-in-time and storage boundaries

The application service accepts scope/config, not arbitrary client histories. A future PDO adapter must select the last N actual immutable published selections in the pinned scope, without selecting by win/loss, return oldest kickoff first, and pin the latest accepted settlement revision **visible by as_of**. It must validate source rights, release/publication eligibility, retained access and entitlement using canonical records in the same snapshot. Never select raw match outcomes, mutable current odds or the demo fixture repository as a replacement.

The engine verifies every input row's exact scope, unique publication/settlement identity, sorted kickoff/publication order, and explicit UTC clocks. Publication must be visible before kickoff; observed/available quotes must precede publication; settlement cannot predate kickoff or become available after as-of. Backfill does not bypass those clocks. Future service as-of clocks are rejected. No synthetic partition is accepted. Revisions remain in stored inclusion/accounting ledgers and change the input hash of a new replay; an old immutable run is not rewritten.

This version uses a conservative single-position exposure policy. It stops on a repeated fixture or when the prior settlement was not yet available at the **next publication visibility/decision time**, even if that next kickoff is later. Thus a settled result cannot fund or size an earlier decision. It does not model simultaneous portfolio positions. All eight markets are supported independently in a pinned scope; cross-model/cross-market rows are rejected rather than mixed into a financially misleading path.

`SimulationService` requires a trusted server `OperatorContext` with HTTPS, unexpired session, RBAC and valid CSRF for creation; the store rechecks owner/model entitlement before reading or replaying history. Current internal owner reads also require that operator context; no public GET authentication contract has been changed or activated. A future web adapter must implement the existing owner GET contract without inventing identity from query/header/preview fields. Scope is checked again for stored reports. Idempotency key uniqueness is owner-scoped; policy/scope/config/as-of fingerprint mismatches fail. A repeat returns the original stored report without reading corrected later history. The transaction contract requires run, entries and audit to commit atomically.

**There is no PDO simulation adapter or working persistence in this change.** The test double's rollback/ownership/idempotency results prove orchestration expectations only, not MySQL durability, row locks or concurrency. `UnavailableSimulationRepository` explicitly rejects production use. No guest demo route or fixture-to-publication adapter is introduced.

## Migration/FK and API diff

Migrations added/applied: **none**. Tables/FKs/ledger checksums changed: **none**. Existing physical inventory remains the verified 32 tables and 39 FKs.

V4 `070_simulation` depends on `060_prediction_selection` and `020_identity`. `060` and its upstream canonical model/publication/settlement parents do not exist locally. Required ownership is `simulation_runs` -> owning user, and `simulation_entries` -> run and pinned publication/settlement evidence. These relationships must use the real parent schema; this phase does not create FK-less substitutes or guess parent columns/types. A migration is therefore blocked instead of pretending those relationships can already be installed.

API paths and success/error contracts changed: **none**. Existing canonical `POST /api/v1/simulations/strategy` and `GET /api/v1/simulations/{id}` retain Phase 7's explicit **503 access_unavailable** guards. The server numerical service is not bound into either route while authentication, entitlement and persisted history/run readers are missing. Existing PHP SSR/browser demos remain clearly separate; do not describe them as server-backed or claim JS/server parity. There are no wager, bookmaker connection, wallet mutation or order execution paths.

## Tests actually run

```text
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist
PHPUnit 10.5.66 / PHP 8.2.12
OK (147 tests, 643 assertions)
```

This includes **59 simulator cases / 183 assertions**, the existing 88 tests, and actual HTTP old-route regressions. Numerical cases cover the three strategies, exact half-up/ceiling/increment behavior, void/no-fee/verified-fee rules, recorded-vs-next odds, next stakes after losses, all caps/refusals, last-win reset, empty history/null ROI, same cohort across strategies with early termination declared, overflow, ruin, disputed/missing quotes, changed settlement revisions, eight scoped markets, wrong model/version/partition, late quote/publication/result clocks, duplicate/reordered rows, correlation/overlap at publication decision time, SVG coordinates/input safety, strict config types/bounds, authorization before reads, owner reads, immutable idempotency and storage-double rollback.

Earlier targeted runs passed 40 tests/150 assertions and 57 tests/174 assertions. An expanded test initially used bankroll 250 while asserting that three flat stakes of 100 could complete after two losses; the engine correctly refused the third stake at bankroll 50. Corrected the test scenario to bankroll 350, where flat completes and Martingale stops; no cap was relaxed. Initial PSR-12 formatting/line-length findings were corrected before final checks.

Additional commands actually executed, all final exit 0:

```text
php tests/catalog.php             Catalog market and identity tests passed
php tests/platform-details.php    Platform dataset, service and integration-contract tests passed
php tests/predictions-hub.php      Predictions hub tests passed
php tests/auto-trade-risk.php      Auto Trade risk checks passed.
php tests/migration-checksum.php   Migration checksum regression test passed
node tests/platform-interactions.cjs Platform interaction tests passed
node tests/history-load-more.cjs     Load-more interaction tests passed
node tests/auto-trade-draft.cjs       Auto Trade preview draft tests passed
```

PSR-12 command: `php vendor/squizlabs/php_codesniffer/bin/phpcs --standard=PSR12 src/Domain/Simulation src/Contracts/SimulationRepository.php src/Contracts/SimulationStore.php src/Repositories/UnavailableSimulationRepository.php src/Services/SimulationService.php src/Presenters/SimulationChart.php tests/StrategySimulatorTest.php tests/Support/SimulationTestRepository.php` passed with no warnings/errors. PHP lint passed for all 11 new PHP files. Diff whitespace check passed with Windows LF/CRLF notices for existing tracked files. No regression was observed in executed checks.

Not run: real published-data replay, PDO simulation migrations/FKs/transactions/concurrency/restore, source-contract verification, provider or wager calls, authenticated web/entitlement E2E, public server chart browser integration, visual chart review, JS/server parity, load/security tests, hosted CI or worker/container suites. Arithmetic unit ledgers are deliberately hypothetical and never enter production datasets or model evaluation.

## Blockers, rollback and restart

Operational completion remains blocked on the real canonical publication/settlement/model schema and point-in-time exporter; authenticated web/CSRF/RBAC/entitlement integration; migration `070` with real parent FKs; transactional immutable owner run/entry/audit persistence, idempotency locking and quota enforcement; provider-verified fee/void contracts; product/risk acceptance of the versioned simulator policy; and API/SSR chart integration with E2E verification. These require explicit owning-phase work, not silent expansion of this request. No trading approval is implied by simulation work.

Rollback only the 11 newly listed PHP files and this report; remove only the strategy-simulator test-suite block and Phase 8 status entry if rolling back. Preserve the pre-existing Phase 7 XML entries/status, all earlier changes, secrets, fixtures, database volumes and worker/model artifacts. No SQL rollback, queue cleanup, business-record reversal or service restart is required; no service was activated. HTTP test servers are terminated by the existing test teardown.

Restart by reinspecting Git/ledger and rerunning the PHPUnit suite. Once owning dependencies are explicitly authorized and implemented, build the PDO store against those actual FKs, verify snapshot/as-of queries and retained rights, exercise concurrent idempotency/rollback/audit tests, then bind the canonical routes and server SVG into SSR with owner security and E2E tests. Normal PHP/opcache reload is sufficient for eventual code deployment; none was performed. **Do not proceed to the next phase automatically.**
