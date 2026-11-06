# Phase 5C: BEVE implementation status

Authority: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 7 (FK09/dependency DAG), 8.3, 8.5-8.6, V07 and phase 5C. This is an implementation/evidence report, not another backend blueprint. The older blueprint path in AGENTS.md does not exist locally; the user's explicit V4 authority governs this phase. No blueprint was created or replaced.

## Status and scope

The offline BEVE numerical implementation and its tests are present. Full production phase completion/release remains **BLOCKED** by the absent model platform, canonical relational lineage, trusted exports, authorized real source data and independent uncertainty/release approval. Operator metadata stays `regularized_ensemble`, coverage remains unvalidated and release eligibility remains false. No production model or financial result is claimed. No subsequent phase has been started.

The implementation uses exactly one frozen SPE and one frozen MLPE version, fitted before a chronological OOF block. A single pooled ensemble posterior is fitted on that block, followed by a separate predeclared untouched holdout. The first adapter supports uncalibrated 5B component artifacts only, not arbitrary refitted cross-fold version combinations, nested ensembles or calibrated artifacts with incomplete lineage. These are explicit current capability limits rather than silently adopted contract changes.

An exact Bayesian estimator expands the mixture score likelihood into latent allocation counts, computes log-space coefficients, and stores a finite mixture of Beta posteriors. The posterior mean supplies nonnegative component weights summing to one. Exact CDF inversion supplies equal-tail weight intervals, which map to each of the eight market probabilities. The score law is a mixture of complete joint distributions with analytical tails; averaging goal rates or normalized component grids would be incorrect and is not used. Interval uncertainty is conditional on fixed components and excludes component parameter/model/source uncertainty.

Frozen records retain version IDs, artifact/training/source digests, partition, fitted fixture/round lineage and fit/freeze clocks. File adapters verify component SHA/code/dependencies and reproduce the training digest, including the MLPE feature matrix. Forecasts require matching clocks, complete paired components, pre-kickoff recording, and whole-round exclusion from component fitting. The fit/holdout cohorts and prior are declared before fit forecasts. Fit labels are selected as of the ensemble fit cutoff; unavailable/void/disputed labels fail closed. Holdout labels cannot change the fit artifact or predictions. Holdout evaluation does not refit or select a favorable subset.

The internal constructors validate trusted assertions but are not a source authorization or cryptographic timestamp boundary. A caller cannot establish historical source rights or prove freezing simply by filling these dataclasses. The absent phase 5A exporter/registry/storage must supply durable authenticated evidence. Original exports and forecast records must be retained with their hashes.

## Changed paths

- Added `workers/models/simora_models/ensemble.py`: frozen component/forecast/plan records, artifact adapters, graph validation, exact posterior, coherent mixture, conditional intervals and matched holdout report.
- Modified `workers/models/simora_models/artifacts.py`: `load_beve` through the existing immutable research artifact envelope; false promotion and posterior/plan mismatches rejected.
- Modified `workers/models/simora_models/__init__.py`: package version `1.0.0` to `1.1.0`.
- Added `workers/models/tests/test_ensemble.py`: 17 tests covering posterior quadrature, prior preservation, weight bounds, extreme likelihoods, known-law interval coverage, cycles, tails/coherence, temporal/source/partition gates, real SPE/MLPE artifact replay, holdout isolation and tamper rejection.
- Modified `workers/models/README.md`: contracts, derivation, numerical limits, use sequence and evidence limits.
- Modified `.github/workflows/model-tests.yml`: workflow name now includes BEVE; existing discovery automatically runs the added tests on Python 3.12/3.14. Hosted CI has not been executed here.
- Added this implementation report.

The Git tree already contained modified/untracked database/bootstrap/live-source/SPE/MLPE work. That work was preserved. No PHP controller, repository, public route or frontend view was changed in phase 5C. The earlier phase 5B report remains historical evidence.

## Migration and FK report

Actual read-only `php bin/verify-database.php` verification on 2026-11-06 returned:

```text
mysql_version: 8.4.13
timezone: +00:00
table_count: 32
foreign_key_count: 39
verified_migrations: 000_schema_ledger, 010_catalog, 020_identity, 030_source_provenance
canonical_markets: 8
```

Checks include physical table/FK column inventories and migration ledger checksums. No migration, table, FK, ledger edit or database write was added/applied by this phase. The unapplied reference schema does not supply the missing model tables.

Canonical `054_ensemble` depends on `053`, while `040` and model-platform migrations `050`-`053` are absent. Consequently no isolated `ensemble_components` or alternate model tables were introduced. FK09 ownership remains with the future model platform/EnsembleService: ensemble and component versions must reference canonical model versions with graph, simplex and scope enforcement. The numerical graph checks are not a replacement for relational ownership or transactional persistence.

## API and compatibility diffs

No HTTP endpoint, server-rendered PHP UI, existing repository return envelope or legacy `GET /api/platforms/{slug}/predictions` contract changed. No Redis/outbox consumer, external worker transport, ranking/publication or Auto Trade execution was connected.

New Python-only operations are documented in `workers/models/README.md`. BEVE uses the existing `simora.model.v1` envelope with engine `beve`, payload type `exact_beta_mixture_v1`, and implementation version `1.1.0`. The holdout report is explicitly internal `simora.ensemble-evaluation.v1`, including ensemble/component IDs, partition, source and result/forecast digest, evaluation clock and all eight markets. The existing SPE/MLPE CLI input/output schemas remain unchanged.

**Artifact compatibility:** existing 1.0.0 artifacts cannot load under 1.1.0 because the existing loader pins package and whole-package code digests. Preserve previous artifacts with their original runtime. Regenerate only approved research artifacts under 1.1.0; never rewrite version/hash metadata to force acceptance. This limitation is explicit and does not change a deployed HTTP contract. No deployment was performed.

## Tests actually run

Host environment: isolated existing Python 3.14.4 at `%LOCALAPPDATA%/Simora/models-v1-venv/Scripts/python.exe`; `PYTHONPATH=workers/models`.

```text
python -m unittest discover -s workers/models/tests -v
Ran 42 tests in 10.702s
OK
BEVE unit-only prior-predictive coverage: 222/240; mean weight width=0.218973
```

All 42 host tests passed, with no skips, including the PHP eight-market registry parity test and actual LightGBM fitting/reloading. The added coverage experiment uses 240 seeded prior-predictive repetitions and checks 90% conditional intervals against known latent weights/event probabilities. Its 92.5% result is algorithm evidence only. Independent numerical quadrature checks posterior CDF/mean. Identical components preserve prior weight uncertainty; extreme/zero likelihoods and malformed weights fail as expected. Cycle tests include self, indirect and disconnected cycles and missing nodes.

One initial test run had 40 passes and one error because the integration test supplied eight training fixtures to MLPE, whose existing minimum is ten. The test fixture cohort was enlarged; no engine minimum was weakened. The subsequent 41-test run passed, followed by the final 42-test run including false-promotion/tamper checks above.

The pinned Python 3.12 container was rebuilt as `simora-models:phase5c`. Container verification uses a non-root user, no network, read-only filesystem and `/tmp` tmpfs:

```text
docker build -t simora-models:phase5c workers/models
exit 0
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python simora-models:phase5c -m unittest discover -s tests -v
Ran 42 tests in 17.452s
OK (skipped=1)
BEVE unit-only prior-predictive coverage: 222/240; mean weight width=0.218973
```

That is 41 passed and one skipped: only the cross-language registry test was skipped because the numerical image lacks PHP. It passed in the host run. The container and host return the same seeded coverage diagnostic.

Other executed checks:

```text
php tests/catalog.php
Catalog market and identity tests passed
php tests/predictions-hub.php
Predictions hub tests passed
node tests/history-load-more.cjs
Load-more interaction tests passed
python -m pip check                         # host and image
No broken requirements found.
Python AST parse of workers/models/**/*.py
AST syntax checks passed: 12 files
git diff --check
exit 0; existing LF/CRLF notices only
```

The image dependency check also emitted a nonfatal pip cache-permission warning under its read-only non-root user. Tests do not contact a source/provider, run migrations, place orders or leave a worker service running. No hosted CI, PHPUnit, browser visual review, production load benchmark or real-source calibration/effectiveness evaluation was run. The existing script checks verify the affected market/history contracts; they do not certify every page in the application.

## Blockers, rollback and restart

- Production integration requires the explicitly approved model-platform phase: canonical model/version/dataset/fold/forecast/component lineage, authenticated versioned transport, immutable signed artifact/export ownership, MySQL outbox/idempotency and audit transactions. Creating those prerequisites is outside this requested phase.
- Source rights, point-in-time accepted observations, retained odds where applicable, approved real-data cohorts and external quality gates remain unresolved. No values, confidence, odds or ROI are invented to bypass them.
- Real-data interval validation and independent release approval are absent. Binary outcomes do not reveal true event probabilities; `interval_coverage` is `null` in holdout reports with that reason. The code never upgrades Bayesian operator labeling or release eligibility from synthetic coverage. Conditional weight intervals alone are not validated conservative EV bounds.
- The exact two-component algorithm is bounded to 5,000 fit observations, with quadratic work. Multiple component version pairs, hierarchical segment pooling, calibrated-component adapters, posterior model misspecification and production throughput require separately scoped validation/work.

There is no BEVE service to restart. To reproduce, use the README host setup or rebuild/run `simora-models:phase5c`; keep approved exports/artifacts in private immutable storage. No web or MySQL restart is required. For rollback, remove only the two newly added Python/test files and this report, revert the BEVE loader addition, restore package version 1.0.0 and the previous README/workflow name, and use the original phase5b runtime/image with its matching artifacts. Preserve all earlier working-tree work, private artifacts, database volumes, secrets and migration ledger. No database rollback is necessary. Do not delete all of `workers/` or `.github/`, which contained pre-existing work.

Recommended next action, only when explicitly requested: resolve the model-platform/live-source prerequisite boundary and agree the real-data uncertainty acceptance protocol. This report does not authorize another implementation phase or BEVE activation.
