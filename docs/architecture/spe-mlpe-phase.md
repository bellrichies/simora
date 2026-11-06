# Phase 5B: SPE and MLPE implementation status

Authority: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 8.1, 8.2, 8.4-8.6 and phase 5B. The numerical research implementation is present; production integration and model release remain blocked by absent phase 5A and live-source prerequisites. This is an implementation report, not another backend blueprint.

## Scope and changed paths

- `.gitignore`: isolate numerical environments, local runtime files and Python bytecode.
- `workers/models/requirements.txt`, `Dockerfile`, `.dockerignore`: pinned LightGBM/NumPy/SciPy and an internal, non-root Python container.
- `workers/models/simora_models/__init__.py`, `__main__.py`: implementation version and offline research CLI.
- `workers/models/simora_models/data.py`: immutable scoped records, research/test partition gates and as-of result revision selection.
- `workers/models/simora_models/distribution.py`: one joint Poisson score law, eight coherent events, adaptive grid and analytic tails with numerical bounds.
- `workers/models/simora_models/features.py`: pre-decision rolling frequencies, counts/missingness, round/source context and fold-local preprocessing.
- `workers/models/simora_models/engines.py`: shrunk league SPE with optional decay, structured LightGBM goal-rate MLPE, validation-only goal-rate calibration and baselines.
- `workers/models/simora_models/evaluation.py`: whole-round walk-forward splits, embargoes, matched cohort metrics, quote comparators and per-model/per-market research evidence gates.
- `workers/models/simora_models/artifacts.py`: immutable JSON artifacts, content/code/dependency/feature compatibility checks and SPE/MLPE reload.
- `workers/models/tests/test_models.py`, `test_php_contract.py`: mathematical, temporal, training, artifact, CLI and canonical-market tests.
- `workers/models/README.md`, this report: input/output contracts, operation, limitations and restart/rollback.
- `.github/workflows/model-tests.yml`: numerical CI matrix; hosted runs remain unverified until pushed/run.

Earlier working-tree changes from database setup, migration checksum repair and live-source primitives were preserved. No frontend or controller/repository source was modified in this phase.

## Models and evidence boundaries

SPE shrinks league goal rates toward a Gamma-smoothed pooled prior with explicit parameters and optional time decay. MLPE trains actual deterministic CPU LightGBM Poisson home/away goal heads and combines them into one independent joint score distribution. Dixon-Coles/dependence is not enabled because no authorized held-out evidence exists. There are no independent incompatible binary market heads.

All six totals selections and both BTTS selections derive from the same law. Grid residuals retain analytical home/away Poisson marginals; they are not collapsed into an unknown tail score. Complementarity, monotone totals, bounded rates, finite probabilities and normalized grid checks are enforced/tested. Validation-only scaling calibrates the joint goal rates; reliability bins are diagnostics, not a claim of established calibration or interval coverage. No numerical uncertainty interval is invented.

Research evidence gates compare each model and market independently against league base rates, the empirical score law and simple Poisson on the same fixtures. Incomplete, stale, unmapped or unverified quote frames do not produce a bookmaker baseline. Valid quote comparisons use an explicitly separate matched cohort. No stake, ROI, profitability, production confidence or ranking output is calculated.

Synthetic input is restricted to demo/unit-test scope and outcome-selected observations are rejected. Test observations and odds are isolated handwritten/deterministic unit fixtures, independent of the application's synthetic demonstration history; they are never imported into MySQL or used as production/effectiveness evidence. No real dataset, trained release model or authorized provider feed is included. Source-manifest assertions in this internal library are not a substitute for external rights verification.

## Database, FK and API report

No migrations, tables, FK changes or database writes are introduced by this phase. The inspected local ledger remains `000_schema_ledger`, `010_catalog`, `020_identity`, `030_source_provenance`; baseline verification passed on MySQL 8.4.13 with 32 tables, 39 FKs and eight canonical markets. The absence of `040` and phase 5A model tables is a blocker, not permission to create alternate prediction tables.

No HTTP endpoint or legacy history envelope changed. New contracts are explicitly offline/internal: `simora.research-dataset.v1`, `simora.evaluation.v1`, and `simora.model.v1`. They do not replace the unimplemented V4 authenticated worker/forecast-run protocol. Full input/API/transport authorization belongs to the absent model platform. Every report and artifact is research-only, with release eligibility false.

## Tests and verification

Initial host verification passed 24 numerical tests. After adding the CLI test, host Python 3.14.4 passed 25 tests including PHP registry parity, actual LightGBM fitting/reloading and correction/backfill/holdout isolation. `pip check` reported `No broken requirements found.` Python AST syntax checks passed for all ten source/test files. `php tests/catalog.php` and `node tests/history-load-more.cjs` passed. The first premature numerical attempt had import errors while SciPy/LightGBM installation was incomplete; dependency installation and subsequent full runs succeeded.

Final host recheck after the numerical tolerance guard: `Ran 25 tests in 5.825s`, `OK`, no skips. Final pinned-base Linux Python 3.12 image build succeeded; `docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python simora-models:phase5b -m unittest discover -s tests` reported `Ran 25 tests in 3.150s`, `OK (skipped=1)`: 24 passed and only the PHP-dependent contract check skipped because PHP is not installed in that numerical image. That check passed on the host. Host and image dependency checks reported no broken requirements. `git diff --check` passed with only existing line-ending notices. No model service/container was left running by tests. Hosted CI has not run.

These tests verify implementation properties, not predictive skill on real provider data. No claim of baseline superiority, validated calibration, financial return or release readiness follows from them. The remaining verification gaps are approved real-data evaluation, phase 5A integration, independently approved release criteria, scale/performance and distributed execution tests. Numerical implementation is verified; the full integrated/released phase is not complete.

The Windows launcher initially resolved an incomplete Python layout. Testing used an isolated runtime and environment outside the synchronized checkout after the first in-checkout install proved slow. Temporary failed-bootstrap files were preserved in ignored local tools rather than deleting unrelated files; no system Python installation or application secret was changed.

## Blockers, restart and rollback

Phase 5A still needs canonical model/version/dataset/fold/feature/forecast lineage, authenticated versioned worker transport, trusted exports, immutable storage and transaction/idempotency integration. Source authorization, accepted result/odds revision persistence and source-quality enforcement also remain incomplete. Production approval, independent untouched-holdout criteria, calibration/uncertainty acceptance and source-specific support require subsequent approved work. The 100-round research diagnostic setting is not an independently approved acceptance criterion or proof of sample independence. Large-scale feature performance and distributed reproducibility have not been benchmarked.

There is no running numerical service to restart. Recreate the isolated environment with the pinned requirements and run the documented CLI/tests; or rebuild the pinned-base container. Keep real exports and model artifacts under private storage with independently verified rights. The CLI refuses to overwrite report files, and artifact loaders reject incompatible content/code/dependencies. It does not provide production signing or approval.

Rollback removes only the new model package, its CI workflow and the corresponding new ignore entries. Preserve earlier `.env`, MySQL volume, migration files and unrelated working-tree changes. No database rollback or web-server restart is required. Do not automatically proceed to BEVE, deployment, ranking or publication. The next dependency to resolve is the explicitly approved model-platform/live-source integration, not automatic activation of these engines.
