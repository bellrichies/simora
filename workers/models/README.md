# Internal SPE / MLPE / BEVE numerical package

Implements the numerical portions of V4 phases 5B and 5C. This is an offline research library and CLI, version `1.1.0`; it has no HTTP, database, Redis, publication or wagering access. Phase 5A registry/dataset/fold/forecast orchestration and phase 4 accepted result/odds persistence are absent, so integration and release remain blocked. No demo application history is imported.

Numerical API references: [LightGBM parameters](https://lightgbm.readthedocs.io/en/v4.6.0/Parameters.html) and [SciPy Poisson distribution](https://docs.scipy.org/doc/scipy/reference/generated/scipy.stats.poisson.html). These document the numerical libraries, not predictive validity for any bookmaker's virtual games.

## Setup and tests

Use a complete 64-bit Python installation (tested with 3.14.4). Dependencies are pinned in `requirements.txt`. On Windows prefer a virtual environment outside a OneDrive-synced checkout:

```powershell
$modelVenv = Join-Path $env:LOCALAPPDATA 'Simora\models-v1-venv'
py -3.14 -m venv $modelVenv
$modelPython = Join-Path $modelVenv 'Scripts\python.exe'
& $modelPython -m pip install --no-compile -r workers/models/requirements.txt
$env:PYTHONPATH = Join-Path (Get-Location) 'workers/models'
& $modelPython -m unittest discover -s workers/models/tests -v
```

The local launcher in this environment pointed at an incomplete interpreter layout. For this run an ignored `.tools/model-python314` runtime was assembled from the installed Python executable/DLLs and junctions to its existing standard library, then used to create the isolated environment. This workaround does not modify the system Python installation. With a normal Python installation use the commands above. Local tools, partial install files and virtual environments are excluded from Git.

An isolated Linux package is also provided. The image uses a pinned Python base digest, installs the pinned numerical dependencies and OpenMP runtime, and runs as UID 10001. Run its tests without networking or a writable root filesystem:

```powershell
docker build -t simora-models:phase5b workers/models
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python simora-models:phase5b -m unittest discover -s tests -v
```

The cross-language registry test is skipped inside this Python-only image because PHP is absent; run it in the host suite. `.github/workflows/model-tests.yml` defines CI on Python 3.12 and 3.14. Adding this file does not assert that hosted CI has run and does not deploy anything.

## Numerical contracts

- SPE uses a Gamma-smoothed pooled goal rate and league-conditioned shrinkage of observed home/away rates toward that pooled rate. Shrinkage strength and optional half-life are explicit fixed training parameters, not tuned against test outcomes. Insufficient data fails closed. No dependence adjustment is enabled: no independent held-out evidence exists to justify a Dixon-Coles challenger.
- MLPE fits two actual LightGBM Poisson regression heads, for home and away goals. Their product is one independent joint score law. It does not fit eight incompatible binary models. CPU, seed, histogram direction and thread count are fixed for reproducibility within the pinned platform/dependencies. No unsupported all-zero head fallback is fabricated.
- All eight probabilities are exact analytical events under the joint Poisson law. Threshold events use the Poisson survival function and BTTS uses both positive-goal marginals. DTOs contain a normalized conditional grid plus the full analytic marginal-tail law and an omitted-mass/error bound. The tail is not collapsed into one unknown goal bucket. Rate values outside the declared numerical range fail rather than being clipped.
- Goal-rate calibration scales both Poisson marginals using validation-only goal likelihood, preserving all eight identities. It is not an independently fitted per-market probability calibration or a claim of validated calibration/uncertainty. Insufficient validation is explicitly marked uncalibrated and cannot pass an evidence gate.

## Data and time safety

`Dataset`, `Manifest`, `Fixture` and `Revision` are frozen input records. Use timezone-aware UTC timestamps. Every fixture has a source partition, scoped league/season/round IDs, observed context time and immutable result revisions. Accepted labels must have integer non-negative goals; void/disputed revisions carry no binary loss label.

History uses the latest revision available at the specific decision time, considering both `available_at` and `ingested_at`. A late backfill is not historical knowledge. Entire target rounds are excluded from rolling history. Missing rolling frequencies, round context and source quality are explicit features; median imputation and league encoding are fitted only on the training fold. No target score, current-round result, unverified odds or public demo confidence enters features. Verified odds are currently supported as a comparator only, not a fitted ML feature.

Source manifest digests are lineage identifiers, not source approval. The numerical library is not an authorization boundary: a future trusted phase 5A dataset builder must validate grants, source evidence, permissions and data digests before export. This implementation accepts only research/unit-test purposes and always reports `release_eligible: false`. Synthetic inputs must be `synthetic/demo/unit_test`; outcome-selected data is rejected for all purposes. Real-data research requires an independently authorized export. Do not relabel the application's outcome-selected fixtures to bypass these checks.

## Walk-forward and baselines

`WalkForwardSpec` predeclares expanding train, validation and test blocks, a positive kickoff buffer, time embargo, seed and minimum evidence. Entire virtual rounds are grouped; overlapping rounds from multiple leagues are merged into indivisible temporal blocks. Test blocks do not overlap across folds. Rounds crossing embargo cutoffs are excluded as a whole. Training and calibration labels must be available by their respective fit cutoffs. Earlier test results may become training data in later expanding folds only after observation, as in chronological deployment replay.

Rolling features replay sequentially: an earlier validation/test result can inform a later fixture only when it is already available. This differs from tuning a model or imputer using future holdout rows; fitted model parameters and preprocessing remain fixed within each fold.

SPE, MLPE, league base rates, the pooled empirical joint-score distribution and simple Poisson are scored on identical accepted fixture cohorts. Reports include per-market Brier/log loss, counts and reliability bins, result-manifest hashes and excluded fixture reasons. Binary log loss explicitly clips at `1e-15` for numerical reporting. Evidence gates run independently per model/market; ties or baseline failure do not qualify. The default research minimum of 100 distinct round groups is an unvalidated diagnostic setting, not a production acceptance threshold or a significance test. A count of distinct rounds does not prove statistical independence.

Optional bookmaker-implied comparisons require all eight mapped prices in one source quote frame, complete complementary pairs, verified upstream scope/rights assertions and observation/availability/ingestion before decision, with unexpired odds. Pairwise proportional margin removal is explicit; incoherent totals or incomplete frames fail. Bookmaker comparisons use their own matched fixture cohort and do not change the broader model-cohort denominator. These assertions still require verification by the absent source platform. No odds are invented when quotes are missing. No ROI or profitability is calculated.

## Artifacts and CLI

`write_artifact(path, fitted_model)` exclusively creates a versioned JSON artifact and returns its SHA-256. SPE and MLPE payloads retain source/training digests, cutoff, supported segments and parameters. MLPE also retains model text, feature schema, fold-local imputation and categorical mappings. The envelope records package/code digests and numerical dependency versions. `load_spe` / `load_mlpe` require the expected digest and reject incompatible schemas, code, feature versions or dependencies. No pickle is used. Hashes provide integrity, not an approval signature.

The offline CLI accepts `simora.research-dataset.v1` JSON with `manifest`, `fixtures`, `fold_spec`, `evaluation_as_of`, and optional `quotes` indexed by fixture ID. Fixture and revision field names match the dataclasses; all dates are UTC ISO 8601. Quotes use the fields in `bookmaker_implied`. Unknown dataclass fields fail. It creates a new `simora.evaluation.v1` report and refuses to overwrite an existing report:

```powershell
& $modelPython -m simora_models --input storage/private/approved-research.json --output storage/private/evaluation-v1.json
```

The input path above denotes a future approved export, not an included dataset. No real dataset or production model was trained in this phase. Unit tests create independent, test-only observations and quotes solely to validate algorithms; their scores must not be presented as evidence of model quality. There is no source connector or worker transport activation in this package.

## BEVE: phase 5C internal library

`simora_models.ensemble` adds a two-component, globally pooled mixture of one exact frozen SPE version and one exact frozen MLPE version. It deliberately restricts this first adapter to a single chronological OOF block followed by an untouched holdout. A component is fitted before the OOF block and never refitted while fitting the ensemble. Multiple cross-fit version pairs, nested ensembles, calibrated component artifacts and deployment forecasts outside the declared research holdout need separate lineage adapters and validation; they are rejected rather than silently substituted. The graph validator checks the complete supplied version graph, including disconnected cycles, missing nodes and self-reference.

Internal workflow (all dataset/IDs/paths below must come from a trusted, authorized export):

1. Write existing 5B fitted SPE/MLPE artifacts with `write_artifact`. Preserve their SHA-256 and the complete fitted fixture lists.
2. Call `freeze_artifact(path, sha, version_id, engine, dataset, fitted_ids, frozen_at)` for each. It reloads pinned bytes, verifies code/dependencies, reproduces the training digest (including MLPE feature matrix), and captures immutable fixture/whole-round lineage, source hash, partition and fit/freeze times. This v1 adapter accepts uncalibrated artifacts only. It cannot authenticate historical freeze timestamps by itself.
3. Construct `EnsemblePlan(version_id, components, fit_ids, holdout_ids, declared_at, fit_cutoff, prior, level)`. Declare cohorts, Beta prior and interval level before any ensemble-fit forecast. Fit and holdout fixtures and whole rounds must be disjoint; holdout follows fit cutoff. Default prior is Beta(1,1), with supported shape range `[0.001, 1000000]` and default interval level 0.9.
4. For each cohort fixture and each pinned component, call `forecast_artifact(path, component, dataset, fixture, as_of, recorded_at)`. Predictions must be recorded before kickoff, share a decision clock and come from versions frozen before that clock. No fixture or round used by a component's fitting/preprocessing/calibration may be its OOF target. Missing/changed versions, source hashes, partitions or components fail closed.
5. Fit with `BayesianEnsemble.fit(dataset, plan, fit_forecasts)`, passing an immutable tuple containing exactly both component records for every declared fit fixture. Only accepted result revisions available/ingested by `fit_cutoff` enter likelihoods. Passing holdout records or incomplete/duplicate pairs fails. Preserve the original export and forecasts alongside their artifact/manifests; digests alone are not durable source evidence.
6. `predict(dataset, pair)` returns a coherent joint `MixtureScore` for a declared holdout fixture. `uncertainty(score)` returns conditional weight/market intervals. `evaluate_holdout(dataset, holdout_forecasts, evaluation_as_of)` uses the exact declared common cohort, records partition/version/result manifests and per-market Brier and interval width, and never refits. Unavailable labels fail instead of selecting a favorable subset.
7. `write_artifact(path, ensemble)` / `load_beve(path, sha)` persist/reload the immutable posterior and plan in the existing `simora.model.v1` envelope. They refuse overwrite, incompatible code/dependencies, malformed posterior/plan and false coverage/release flags. Existing 1.0.0 artifacts are incompatible with the new package/code digest; preserve them with their original runtime, or regenerate research artifacts under 1.1.0. Never relabel an old artifact's hash/version.

The internal `ComponentForecast` dataclasses also permit trusted, already verified exports and unit tests. They validate lineage assertions but do not independently authenticate artifact bytes or external source rights; use the file adapters for verified byte loading. None of these Python constructors is an authorization or production release boundary. The phase 5A trusted exporter, DB FKs, approved immutable storage and signed registry must supply that boundary.

### Posterior and mixture mathematics

For observed OOF score `y_i`, define `p_i = P_SPE(y_i)` and `q_i = P_MLPE(y_i)`. With `w ~ Beta(a,b)`, the likelihood is `product_i [w*p_i + (1-w)*q_i]`. Expanding over latent component allocations gives nonnegative coefficients `c_k` for `w^k*(1-w)^(n-k)`. The implemented exact posterior is a mixture of `Beta(a+k,b+n-k)` densities, with masses proportional to `c_k*B(a+k,b+n-k)`. A log-space dynamic program computes the coefficients and normalization. This is an explicit posterior estimator, with deterministic CDF inversion via SciPy Beta CDF and Brent's method, no MCMC, bootstrap surrogate or invented precision. See the [SciPy Beta API](https://docs.scipy.org/doc/scipy/reference/generated/scipy.stats.beta.html). Fitting is bounded at 5,000 research observations (`O(n^2)` work, `O(n)` coefficient storage); it is not benchmarked for production throughput.

The predictive law is `E[w]*P_SPE + (1-E[w])*P_MLPE`, a mixture of entire score laws, not a Poisson law at averaged goal rates. Both weights are nonnegative and sum to one. All eight events derive from that same law. A common adaptive score rectangle is computed with the full analytic component tails retained; conditional grids are not directly averaged. Exact log score uses log-sum-exp. Zero-support observations in both components fail fitting, and a missing component never causes implicit reweighting or fallback.

Equal-tail weight quantiles map through each market's affine mixture probability, with endpoints reversed when needed. These intervals measure **weight uncertainty conditional on fixed component laws**, excluding component parameter, structural, drift and source uncertainty. Interval endpoints across markets are marginal summaries, not a separate joint distribution. Identical components retain the prior weight uncertainty while giving zero market-width, as they should.

The 240-repetition seeded prior-predictive test measures credible interval coverage against known synthetic latent weights and event probabilities. Independent quadrature verifies posterior means/CDFs. These are algorithm tests, not real-world calibration evidence. An observed binary outcome does not reveal its latent probability, so the held-out report correctly returns `interval_coverage: null`; it does not count binary outcomes inside probability intervals. A separately approved real-data uncertainty validation protocol is still required. Every artifact/report stays `operator_label: regularized_ensemble`, `coverage_validated: false`, `release_eligible: false`. No conservative EV, odds, ROI or ranking is produced.

Phase 5C container commands:

```powershell
docker build -t simora-models:phase5c workers/models
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python simora-models:phase5c -m unittest discover -s tests -v
```

Implementation evidence and rollback: `docs/architecture/beve-phase.md`. No worker service is started by this library.
