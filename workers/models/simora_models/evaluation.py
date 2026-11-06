"""Round-grouped expanding walk-forward evaluation on matched fixture cohorts."""

from dataclasses import dataclass
from datetime import timedelta
import math

from .data import digest, utc
from .distribution import MARKETS, PAIRS, outcomes, validate_markets
from .engines import Baselines, GoalRateCalibration, ShrunkPoisson, StructuredLightGBM
from .features import decision_time


@dataclass(frozen=True)
class WalkForwardSpec:
    min_train_blocks: int
    validation_blocks: int
    test_blocks: int
    buffer_seconds: int
    embargo_seconds: int
    minimum_calibration: int = 10
    seed: int = 1729
    minimum_evaluation_rounds: int = 100

    def __post_init__(self):
        if any(type(x) is not int or x < 1 for x in (
            self.min_train_blocks, self.validation_blocks, self.test_blocks,
            self.buffer_seconds, self.minimum_calibration, self.minimum_evaluation_rounds
        )) or type(self.embargo_seconds) is not int or self.embargo_seconds < 0:
            raise ValueError("Invalid fold specification")


def blocks(dataset, buffer_seconds):
    rounds = {}
    for fixture in dataset.fixtures:
        rounds.setdefault(fixture.round_key, []).append(fixture)
    intervals = sorted([(min(decision_time(f, buffer_seconds) for f in group),
                         max(utc(f.kickoff_at) for f in group), group)
                        for group in rounds.values()], key=lambda group: (group[0], group[1], group[2][0].id))
    merged = []
    for start, end, fixtures in intervals:
        # Overlapping rounds across different leagues also cannot straddle folds.
        if merged and start <= merged[-1][1]:
            previous = merged.pop()
            merged.append((previous[0], max(previous[1], end), previous[2] + fixtures))
        else:
            merged.append((start, end, fixtures))
    return merged


def folds(dataset, spec):
    groups = blocks(dataset, spec.buffer_seconds)
    stop = len(groups) - spec.validation_blocks - spec.test_blocks + 1
    for train_stop in range(spec.min_train_blocks, stop, spec.test_blocks):
        validation_stop = train_stop + spec.validation_blocks
        test_stop = validation_stop + spec.test_blocks
        flatten = lambda subset: tuple(sorted((f for group in subset for f in group[2]), key=lambda f: (utc(f.kickoff_at), f.id)))
        train = flatten(groups[:train_stop])
        validation = flatten(groups[train_stop:validation_stop])
        test = flatten(groups[validation_stop:test_stop])
        train_cutoff = groups[train_stop][0] - timedelta(seconds=spec.embargo_seconds)
        calibration_cutoff = groups[validation_stop][0] - timedelta(seconds=spec.embargo_seconds)
        # Whole rounds crossing an embargo boundary are excluded rather than split.
        train_rounds = {f.round_key for f in train if utc(f.kickoff_at) >= train_cutoff}
        train = tuple(f for f in train if f.round_key not in train_rounds)
        validation_rounds = {f.round_key for f in validation if utc(f.kickoff_at) >= calibration_cutoff}
        validation = tuple(f for f in validation if f.round_key not in validation_rounds)
        yield {"train": train, "validation": validation, "test": test,
               "train_cutoff": train_cutoff, "calibration_cutoff": calibration_cutoff,
               "fixture_universe_hash": digest([f.id for f in train + validation + test])}


def metrics(probabilities, targets):
    if len(probabilities) != len(targets) or not targets:
        raise ValueError("Nonempty common cohort required")
    output = {}
    for market in MARKETS:
        p = [row[market] for row in probabilities]
        y = [row[market] for row in targets]
        for row in probabilities:
            validate_markets(row)
        clipped = [min(1 - 1e-15, max(1e-15, value)) for value in p]
        bins = []
        for index in range(10):
            selected = [i for i, value in enumerate(p) if min(int(value * 10), 9) == index]
            if selected:
                bins.append({"bin": index, "count": len(selected),
                             "mean_probability": sum(p[i] for i in selected) / len(selected),
                             "observed_frequency": sum(y[i] for i in selected) / len(selected)})
        output[market] = {"count": len(y), "log_loss_epsilon": 1e-15, "brier": sum((a - b) ** 2 for a, b in zip(p, y)) / len(y),
                          "log_loss": -sum(b * math.log(a) + (1 - b) * math.log1p(-a)
                                           for a, b in zip(clipped, y)) / len(y),
                          "reliability_bins": bins}
    return output


def bookmaker_implied(quote, fixture, as_of):
    """Comparator only: require all pairs at one source timestamp, never invent vig."""
    if (quote["fixture_id"] != fixture.id or quote["partition"] != fixture.partition
        or not quote.get("source_id") or quote.get("mapping_verified") is not True
        or not quote.get("rights_verified") is True):
        raise ValueError("Unverified quote scope or rights")
    if not (utc(quote["observed_at"]) <= utc(quote["available_at"]) <= utc(as_of)
            < utc(quote["expires_at"]) and utc(quote["ingested_at"]) <= utc(as_of)
            and utc(quote["ingested_at"]) >= utc(quote["observed_at"]) and utc(as_of) < utc(fixture.kickoff_at)):
        raise ValueError("Quote not accessible as of decision")
    odds = quote["odds"]
    if set(odds) != set(MARKETS) or any(not math.isfinite(o) or o <= 1 for o in odds.values()):
        raise ValueError("Complete contemporaneous canonical pairs required")
    result = {}
    for a, b in PAIRS:
        margin = 1 / odds[a] + 1 / odds[b]
        result[a], result[b] = (1 / odds[a]) / margin, (1 / odds[b]) / margin
    validate_markets(result)
    return result


def walk_forward(dataset, spec, evaluation_as_of, quotes=None):
    dataset.manifest.validate()
    reports = []
    for index, fold in enumerate(folds(dataset, spec)):
        if fold["train_cutoff"] >= utc(evaluation_as_of):
            break
        rows = dataset.labels(fold["train_cutoff"], fold["train"])
        if not rows:
            raise ValueError("insufficient_evidence: empty training fold")
        spe = ShrunkPoisson().fit(dataset, rows, fold["train_cutoff"])
        mlpe = StructuredLightGBM(seed=spec.seed).fit(dataset, rows, fold["train_cutoff"], spec.buffer_seconds)
        baseline = Baselines().fit(dataset, rows, fold["train_cutoff"])
        engines = {"spe": lambda f: spe.predict(f, decision_time(f, spec.buffer_seconds)),
                   "mlpe": lambda f: mlpe.predict(dataset, f, decision_time(f, spec.buffer_seconds))}
        validation = dataset.labels(fold["calibration_cutoff"], fold["validation"])
        calibrations = {}
        for code, predict in engines.items():
            if len(validation) >= spec.minimum_calibration:
                calibrations[code] = GoalRateCalibration().fit(
                    [predict(f) for f, _ in validation], [r for _, r in validation],
                    fold["calibration_cutoff"], spec.minimum_calibration)
        test = dataset.labels(evaluation_as_of, fold["test"])
        test_exclusions = {}
        for fixture in fold["test"]:
            known = fixture.result_as_of(evaluation_as_of)
            if known is None:
                test_exclusions[fixture.id] = "no_result_as_of"
            elif known.status != "accepted":
                test_exclusions[fixture.id] = known.status
            elif fixture.segment not in spe.rates or fixture.segment not in mlpe.preprocessor.league_codes:
                test_exclusions[fixture.id] = "unsupported_segment"
        test = [(fixture, result) for fixture, result in test if fixture.id not in test_exclusions]
        if not test:
            reports.append({"fold": index, "status": "insufficient_evidence", "test_count": 0,
                            "test_exclusions": test_exclusions})
            continue
        baseline_codes = ("league_base_rates", "empirical_score_distribution", "simple_poisson")
        predictions = {code: [] for code in (*engines, *baseline_codes)}
        for fixture, _ in test:
            for code, predict in engines.items():
                score = predict(fixture)
                if code in calibrations:
                    score = calibrations[code].apply(score)
                predictions[code].append(score.markets())
            for code, value in baseline.predict(fixture, decision_time(fixture, spec.buffer_seconds)).items():
                predictions[code].append(value)
        targets = [outcomes(r.home, r.away) for _, r in test]
        results = {code: metrics(values, targets) for code, values in predictions.items()}
        round_count = len({f.round_key for f, _ in test})
        gates = {}
        for code in engines:
            gates[code] = {}
            for market in MARKETS:
                beats_baselines = all(
                    results[code][market][metric] < results[baseline_code][market][metric]
                    for metric in ("brier", "log_loss") for baseline_code in baseline_codes)
                gates[code][market] = ("insufficient_evidence" if round_count < spec.minimum_evaluation_rounds
                                       or code not in calibrations else
                                       "research_improvement_only" if beats_baselines else "baseline_not_beaten")
        quote_predictions, quote_indices, quote_rejections = [], [], {}
        for test_index, (fixture, _) in enumerate(test):
            if not quotes or fixture.id not in quotes:
                continue
            try:
                quote_predictions.append(bookmaker_implied(quotes[fixture.id], fixture,
                                                          decision_time(fixture, spec.buffer_seconds)))
                quote_indices.append(test_index)
            except (ValueError, KeyError, TypeError):
                quote_rejections[fixture.id] = "invalid_or_unavailable_quote"
        bookmaker = {"status": "unavailable", "reason": "no_verified_quote_cohort", "rejected": quote_rejections}
        if quote_indices:
            quote_targets = [targets[i] for i in quote_indices]
            quote_models = {code: [values[i] for i in quote_indices] for code, values in predictions.items()}
            quote_models["bookmaker_implied"] = quote_predictions
            bookmaker = {"status": "research_only", "fixture_ids": [test[i][0].id for i in quote_indices],
                         "rejected": quote_rejections,
                         "metrics": {code: metrics(values, quote_targets) for code, values in quote_models.items()}}
        reports.append({"fold": index, "status": "research_only", "test_count": len(test),
                        "training_count": len(rows), "validation_count": len(validation),
                        "test_exclusions": test_exclusions,
                        "test_result_manifest_digest": digest([(f.id, r) for f, r in test]),
                        "fixture_ids": [f.id for f, _ in test], "fixture_universe_hash": fold["fixture_universe_hash"],
                        "training_digest": mlpe.training_digest,
                        "train_cutoff": fold["train_cutoff"].isoformat(),
                        "calibration_cutoff": fold["calibration_cutoff"].isoformat(),
                        "calibration": {code: {"method": "validation_goal_rate_scaling", "scales": list(cal.scales),
                                               "label_digest": cal.digest} for code, cal in calibrations.items()},
                        "uncalibrated_models": sorted(set(engines) - set(calibrations)),
                        "metrics": results,
                        "round_group_count": round_count, "market_evidence_gates": gates,
                        "bookmaker_baseline": bookmaker})
    return {"schema_version": "simora.evaluation.v1", "status": "research_only" if any(r["test_count"] for r in reports) else "insufficient_evidence",
            "release_eligible": False, "promotion": "blocked_missing_model_platform_and_source_approval",
            "partition": dataset.manifest.partition, "manifest_digest": digest(dataset.manifest),
            "evaluation_as_of": utc(evaluation_as_of).isoformat(),
            "fold_spec": {**spec.__dict__}, "folds": reports}
