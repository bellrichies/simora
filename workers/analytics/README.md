# Internal comparison mathematics, version 1.0.0

V4 phase 6B supplies an offline comparison library and PHP champion policy/service. No HTTP, database writer, scheduler, Redis consumer or provider connector is activated. Canonical migrations through `060`, then `061_model_comparisons` and `062_champion_decisions`, are absent. The PHP production repository fails unavailable. The existing PHP UI and legacy history contract are unchanged.

This package is separate from `simora_models` so adding comparison code does not change that package's whole-code artifact digest or invalidate existing 1.1.0 model artifacts. It reuses pinned numerical dependencies in `workers/models/requirements.txt` and the immutable model/data classes; no new training algorithm or model release is introduced.

## Run checks

Using the existing Windows numerical environment:

```powershell
$modelPython = Join-Path $env:LOCALAPPDATA 'Simora/models-v1-venv/Scripts/python.exe'
$env:PYTHONPATH = (Join-Path (Get-Location) 'workers/models') + ';' + (Join-Path (Get-Location) 'workers/analytics')
& $modelPython -m unittest discover -s workers/analytics/tests -v
composer test:model-comparison
composer lint:model-comparison
```

The Linux container uses a pinned Python 3.12 base, pinned dependencies and UID 10001:

```powershell
docker build -f workers/analytics/Dockerfile -t simora-analytics:phase6b workers
docker run --rm --network none --read-only --tmpfs /tmp simora-analytics:phase6b -m unittest discover -s tests -v
```

The Python-only container skips the PHP snapshot interoperability test; run that on the host/CI with Composer autoloading available. CI definitions are not evidence of hosted execution.

## Trusted input and populations

`ComparisonPlan`, `Forecast`, `Opportunity`, `Publication`, `Settlement` in `contracts.py` are **trusted-export DTOs**, not request bodies or proof of source authorization. A future authenticated exporter must read canonical FK-backed datasets, folds, forecasts, publications, actual exposures and settlement revisions under a single point-in-time contract, independently verify source rights and retain the immutable export/plan. Hashes and booleans supplied by an arbitrary caller are not authorization. The library accepts only the existing research/unit-test `Dataset` manifest; outcome-selected data is rejected, and synthetic input remains unit-test/demo-only.

The plan fixes platform, league, partition, time window, exact fixture IDs, candidate and baseline version sets, policy/experiment, evaluation clock, cutoff/decision buffers, seed, bootstrap settings and currency before the first forecast cutoff. Model versions and artifact/fitted lineage must remain frozen. Each candidate and baseline supplies a coherent `PoissonScore` or `MixtureScore` per supported fixture; fitting/preprocessing/calibration fixture lineage may not include the target whole round or unavailable results. This first adapter compares fixed model versions, not a family silently refitted across folds; any cross-fold version-series adapter needs an explicit future contract.

Forecast skill uses the same accepted fixture cohort and evaluation clock for every candidate, baseline and all eight markets. Missing any compared forecast, pending/void/disputed results exclude that fixture from **every** skill denominator with explicit reasons and common coverage. Forecast and context availability must satisfy the plan's feature/decision clocks. Report Brier, log loss and reliability bins retain the existing `1e-15` log-loss clipping convention. No new probability calibration or model fitting occurs here.

Selected returns are a separate population. The exporter must supply every candidate/fixture/market opportunity, including `no_selection`, `insufficient_evidence` and `source_unavailable`; dropping no-picks fails validation. Qualified opportunities must reconcile exactly with immutable publications and prematch observed, available, ingested, unexpired source-mapped quotes. No baseline publications are assumed. Different candidates can select different fixture cohorts; their selected returns must never be used as the common forecast-skill cohort.

Publication exposure fields are a **joined projection** of externally recorded stake evidence, not a new mutable publication contract. No unit stake is assumed. The adapter must provide actual placement and availability clocks and a durable exposure reference. Missing stake/fee/payout evidence, unverified money, pending settlement or disputed results withhold the **entire** per-model/market ROI/net/stake summary rather than dropping unfavorable rows or creating partial denominators. No stakes gives ROI `null`, not 0. Zero fees must be explicitly recorded.

Net is `recorded returned_minor - actual stake_minor - recorded fee_minor`, with exact integer aggregation. Returned amounts must have been verified under the referenced settlement contract; this library never assumes a payout rounding rule or void-refund amount from a quote. Decimal odds are provenance, never reconstructed or fabricated. Ratios use Decimal precision 28 and `ROUND_HALF_EVEN`; intervals use double-precision resampling only after exact totals are bounded to `2^53-1`. No cash movement or wagering is performed.

Selected summaries include counts, no-pick coverage, source odds, profit factor (null without losses), win rate, fixture/round exposures and cumulative-net drawdown. The drawdown is explicitly a **reconstructed as-of settlement curve by fixture-round kickoff**, not chronological cashflow or bankroll-strategy P&L. No cross-market/model financial totals are computed, since repeated fixtures and correlated positions require separate exposure accounting. Strategy simulation remains another phase.

## Confidence intervals and corrections

The bootstrap draws complete virtual rounds with replacement. All candidate/baseline skill columns use the **same draws**, so model differences are paired on identical fixtures. Percentile intervals use the declared seed, level, resample count and NumPy linear quantiles. Multiple fixtures in a round stay together; duplicating correlated fixtures does not falsely add independent rounds. Selected ROI intervals resample recorded selected rounds per model/market and are conditional on that selected cohort; they do not estimate policy-selection uncertainty or prove future return. Too few round clusters yields null bounds with a reason. The numerical report does not automatically adjust for multiple testing or choose a champion.

Resampling is equivalent to multinomial round multiplicities; see [NumPy's Generator.multinomial API](https://numpy.org/doc/stable/reference/random/generated/numpy.random.Generator.multinomial.html) and [quantile API](https://numpy.org/doc/stable/reference/generated/numpy.quantile.html). These APIs do not establish real-data interval calibration. The known-law unit coverage experiment is an algorithm diagnostic only. Latent event probabilities are not observed in real binary outcomes, so probability interval coverage remains null.

Settlement revisions form a contiguous, uniquely owned, monotonic append-only supersession chain. The latest available/ingested revision at the declared evaluation clock is used; a result correction with no corresponding settlement correction is pending, not a fabricated new return. Future revisions cannot change the historical report body. Recalculation uses a **new** plan/evaluation clock/snapshot with `supersedes_report_sha256`; old reports and champion decisions are retained. Canonical experiment storage must authenticate the original precommit and explicit correction/re-evaluation clock.

## Calling the internal library

`compare(dataset, plan, forecasts, opportunities, publications, settlements, created_at)` consumes immutable tuples and returns `simora.comparison-report.v1`. Retain all original inputs. It emits plan, version-set, fixture-universe, source, input and result/settlement hashes, package/code/dependency versions, scope/window, bootstrap settings, common skill and separate selected summaries. Current limits: 10,000 cohort fixtures, eight compared versions, 200–10,000 resamples, and at most 5,000,000 bootstrap round/draw cells. These are research safety bounds, not production scale benchmarks.

`write_snapshot(path, report, key_id, key_bytes)` exclusively creates and fsyncs a private signed `simora.comparison-snapshot.v1` JSON envelope; it refuses overwrite, nonfinite JSON and release-eligible output. `read_snapshot(path, expected_payload_sha256, keyring)` checks the exact payload hash and HMAC-SHA256 with a 32 MiB input bound. A crash can leave a partial file, which verification rejects; exclusive creation never silently repairs or overwrites it. Use a new path after preserving failed-write evidence. No production report-signing key is generated by this phase.

The signed message bytes are `simora.comparison-snapshot.v1 + LF + key_id + LF + payload_sha256`. The envelope stores the original UTF-8 `payload_json` string so PHP verifies identical bytes without JSON float/key re-encoding. `App\Domain\ModelComparison\ComparisonSnapshot` implements the reader, including key rotation by explicit keyring. Signatures establish trusted-key integrity, not independent scientific/release approval or historical source rights. All reports generated by this package remain `research_only`, `release_eligible: false`; no legitimate production champion can be derived from them yet.

## Champion decisions

`ChampionDecisionService::commit` requires verified server-session/RBAC/CSRF context, a trusted report signature, exact platform/league/market/partition/policy/experiment, independent canonical report approval, current candidate health/source eligibility and explicit activation/expiry times. The policy has no production defaults. It requires adequate common samples/rounds, matching eight-market denominators, baseline and alternative paired Brier/log-loss dominance, Bonferroni family coverage and sufficient bootstrap tail replicates. The family includes all declared candidate/comparator, market and metric hypotheses. Ties/overlapping bounds/insufficient evidence record an explicit null champion; a health-ineligible winner never becomes the runner-up. Selected ROI is not read by this policy.

The current report builder cannot produce release-eligible evidence. Hypothetical approved PHP report fixtures test a future registry boundary only; they are not model/data release approvals. Canonical persistence and an independently validated released-report producer remain prerequisites.

Signed immutable decisions bind the report, chosen version/abstention, scope, policy, approver and decision/effective/expiry clocks. The repository must separately attach **verified actual durable availability**, which may be later than decision time. Pending visibility is not selectable. A requested effective time is not proof of commit availability. Audit, unique decision result, FK references and outbox are one transaction under a scope/policy exclusion lock; no production implementation exists until the prerequisite schema/outbox ownership is resolved.

`resolve(scope, selection_as_of, server_resolved_viewer)` selects the last visible effective committed decision, never re-evaluates metrics and never falls back behind a newer abstention, expiry, invalid signature or ineligible winner. It verifies the original report snapshot's integrity, time-valid approval and report freshness, plus current model health/source guards. It rejects future selection clocks. Read-time current safety can deny serving an old decision; it never rewrites the archived decision or its publication references. This serving resolver is not an unrestricted historical replay API. Viewer entitlements must come from the server; no public endpoint is wired.

Full changed-path, migration/FK, test evidence, blocker and rollback report: `docs/architecture/model-comparison-phase.md`.
