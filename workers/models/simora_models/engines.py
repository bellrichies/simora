"""SPE and two structured goal-rate LightGBM heads with a coherent joint law."""

from datetime import datetime
import math

import lightgbm as lgb
import numpy as np

from .data import digest, utc
from .distribution import MARKETS, PoissonScore, outcomes, validate_markets
from .features import FEATURE_NAMES, FEATURE_VERSION, FoldPreprocessor, decision_time


class ShrunkPoisson:
    code = "spe"

    def __init__(self, strength=20.0, prior_shape=1.0, prior_exposure=1.0, half_life_days=None):
        if any(not math.isfinite(x) or x <= 0 for x in (strength, prior_shape, prior_exposure)):
            raise ValueError("Positive finite shrinkage hyperparameters required")
        if half_life_days is not None and (not math.isfinite(half_life_days) or half_life_days <= 0):
            raise ValueError("Invalid time decay")
        self.strength, self.prior_shape, self.prior_exposure = strength, prior_shape, prior_exposure
        self.half_life_days = half_life_days

    def fit(self, dataset, rows, cutoff):
        dataset.validate_training_rows(rows, cutoff)
        rows = sorted(rows, key=lambda row: (utc(row[0].kickoff_at), row[0].id))
        self.source_manifest_sha256 = dataset.manifest.source_manifest_sha256
        self.partition = dataset.manifest.partition
        if not rows:
            raise ValueError("insufficient_evidence: no training results")
        self.cutoff = utc(cutoff)
        totals = np.zeros(2)
        weight_sum = 0.0
        grouped = {}
        for fixture, result in rows:
            if result.usable_at > self.cutoff or utc(fixture.kickoff_at) >= self.cutoff or result.status != "accepted":
                raise ValueError("Training label leaks beyond cutoff")
            age = (self.cutoff - utc(fixture.kickoff_at)).total_seconds() / 86400
            weight = 1.0 if self.half_life_days is None else math.exp(-math.log(2) * age / self.half_life_days)
            goals = np.array([result.home, result.away], dtype=float)
            totals += weight * goals
            weight_sum += weight
            sums, count = grouped.get(fixture.segment, (np.zeros(2), 0.0))
            grouped[fixture.segment] = (sums + weight * goals, count + weight)
        global_rates = (totals + self.prior_shape) / (weight_sum + self.prior_exposure)
        if weight_sum <= 0:
            raise ValueError("insufficient_evidence: time decay removed all observations")
        self.rates = {key: tuple((sums + self.strength * global_rates) / (count + self.strength))
                      for key, (sums, count) in grouped.items()}
        self.training_digest = digest({"labels": [(f.id, r) for f, r in rows], "manifest": dataset.manifest})
        return self

    def artifact(self):
        return {"type": "shrunk_poisson", "rates": [[*key, *value] for key, value in sorted(self.rates.items())],
                "parameters": {"strength": self.strength, "prior_shape": self.prior_shape,
                               "prior_exposure": self.prior_exposure, "half_life_days": self.half_life_days},
                "training_cutoff": self.cutoff.isoformat(), "training_digest": self.training_digest,
                "source_manifest_sha256": self.source_manifest_sha256,
                "partition": self.partition,
                "supported_segments": [list(key) for key in sorted(self.rates)], "seed": None,
                "feature_version": "shrunk-league-goals-v1", "release_eligible": False}

    def predict(self, fixture, as_of):
        if (utc(as_of) < self.cutoff or fixture.segment not in self.rates or fixture.partition != self.partition
            or not utc(fixture.context_available_at) <= utc(as_of) < utc(fixture.kickoff_at)):
            raise ValueError("Unsupported segment or forecast before training cutoff")
        return PoissonScore(*self.rates[fixture.segment], self.code, as_of, "shrunk-league-goals-v1")


class StructuredLightGBM:
    code = "mlpe"

    def __init__(self, seed=1729, rounds=50, min_data_in_leaf=10):
        if any(type(x) is not int for x in (seed, rounds, min_data_in_leaf)) or rounds < 1 or min_data_in_leaf < 2:
            raise ValueError("Invalid LightGBM configuration")
        self.seed, self.rounds, self.min_data_in_leaf = seed, rounds, min_data_in_leaf

    def fit(self, dataset, rows, cutoff, buffer_seconds):
        dataset.validate_training_rows(rows, cutoff)
        rows = sorted(rows, key=lambda row: (utc(row[0].kickoff_at), row[0].id))
        self.source_manifest_sha256 = dataset.manifest.source_manifest_sha256
        self.partition = dataset.manifest.partition
        if len(rows) < 10:
            raise ValueError("insufficient_evidence: at least ten training fixtures required")
        self.cutoff, self.buffer_seconds = utc(cutoff), buffer_seconds
        fixtures = [fixture for fixture, _ in rows]
        if any(result.status != "accepted" or result.usable_at > self.cutoff
               or utc(fixture.kickoff_at) >= self.cutoff for fixture, result in rows):
            raise ValueError("Training label leaks beyond cutoff")
        self.preprocessor = FoldPreprocessor().fit(dataset, fixtures, buffer_seconds)
        matrix = self.preprocessor.transform(dataset, fixtures, buffer_seconds)
        self.params = {"objective": "poisson", "metric": "poisson", "verbosity": -1,
                       "deterministic": True, "force_col_wise": True, "num_threads": 1,
                       "seed": self.seed, "data_random_seed": self.seed, "feature_fraction_seed": self.seed,
                       "bagging_seed": self.seed, "num_leaves": 7, "learning_rate": 0.05,
                       "min_data_in_leaf": self.min_data_in_leaf, "feature_pre_filter": False}
        self.heads = []
        for side in ("home", "away"):
            labels = np.array([getattr(result, side) for _, result in rows], dtype=float)
            if labels.sum() == 0:
                raise ValueError("insufficient_evidence: all-zero goal head")
            training = lgb.Dataset(matrix, label=labels, feature_name=list(FEATURE_NAMES),
                                   categorical_feature=["league_code"])
            self.heads.append(lgb.train(self.params, training, num_boost_round=self.rounds))
        self.training_digest = digest({"labels": [(f.id, r) for f, r in rows],
                                       "matrix": matrix.tolist(), "manifest": dataset.manifest})
        return self

    def predict(self, dataset, fixture, as_of):
        if (dataset.manifest.partition != self.partition or fixture.partition != self.partition
            or utc(as_of) != decision_time(fixture, self.buffer_seconds) or utc(as_of) < self.cutoff):
            raise ValueError("Inference time does not match frozen feature cutoff")
        matrix = self.preprocessor.transform(dataset, [fixture], self.buffer_seconds)
        rates = [float(head.predict(matrix, num_threads=1)[0]) for head in self.heads]
        return PoissonScore(*rates, self.code, as_of)

    def artifact(self):
        return {"type": "lightgbm_poisson_goal_heads", "models": [head.model_to_string() for head in self.heads],
                "parameters": self.params, "rounds": self.rounds,
                "imputation_medians": self.preprocessor.medians.tolist(),
                "league_codes": [[*key, value] for key, value in sorted(self.preprocessor.league_codes.items())],
                "feature_names": list(FEATURE_NAMES), "feature_version": FEATURE_VERSION,
                "training_cutoff": self.cutoff.isoformat(), "training_digest": self.training_digest,
                "source_manifest_sha256": self.source_manifest_sha256,
                "partition": self.partition,
                "buffer_seconds": self.buffer_seconds,
                "supported_segments": [list(key) for key in sorted(self.preprocessor.league_codes)],
                "seed": self.seed, "lightgbm_version": lgb.__version__, "release_eligible": False}


class GoalRateCalibration:
    """Poisson rate scaling fitted exclusively on a completed validation cohort."""

    def fit(self, predictions, labels, cutoff, minimum=10):
        if len(predictions) != len(labels) or len(labels) < minimum:
            raise ValueError("insufficient_evidence: calibration cohort")
        if any(result.usable_at > utc(cutoff) or result.status != "accepted" for result in labels):
            raise ValueError("Calibration label not yet available")
        if any(utc(prediction.as_of) >= utc(cutoff) for prediction in predictions):
            raise ValueError("Validation must precede calibration fit cutoff")
        denominator = np.sum([[p.home_rate, p.away_rate] for p in predictions], axis=0)
        numerator = np.sum([[r.home, r.away] for r in labels], axis=0)
        if np.any(denominator <= 0):
            raise ValueError("Cannot calibrate zero expected goals")
        self.scales = tuple(numerator / denominator)
        self.cutoff = utc(cutoff)
        self.digest = digest({"labels": labels, "rates": [[p.home_rate, p.away_rate] for p in predictions]})
        return self

    def apply(self, score):
        if utc(score.as_of) < self.cutoff:
            raise ValueError("Calibration from future data")
        return PoissonScore(score.home_rate * self.scales[0], score.away_rate * self.scales[1],
                            score.method + ":validation_rate_scaling", score.as_of, score.feature_version)


class Baselines:
    def fit(self, dataset, rows, cutoff):
        dataset.validate_training_rows(rows, cutoff)
        rows = sorted(rows, key=lambda row: (utc(row[0].kickoff_at), row[0].id))
        self.partition = dataset.manifest.partition
        if not rows or any(r.usable_at > utc(cutoff) or r.status != "accepted" or utc(f.kickoff_at) >= utc(cutoff)
                           for f, r in rows):
            raise ValueError("Invalid baseline training population")
        self.cutoff = utc(cutoff)
        self.global_rates = tuple(np.mean([[r.home, r.away] for _, r in rows], axis=0))
        self.global_scores = [(r.home, r.away) for _, r in rows]
        self.league_rows = {key: [(r.home, r.away) for f, r in rows if f.segment == key]
                            for key in {f.segment for f, _ in rows}}
        return self

    def predict(self, fixture, as_of):
        if (utc(as_of) < self.cutoff or fixture.segment not in self.league_rows or fixture.partition != self.partition
            or not utc(fixture.context_available_at) <= utc(as_of) < utc(fixture.kickoff_at)):
            raise ValueError("Unsupported baseline segment or cutoff")
        scores = self.league_rows[fixture.segment]
        # Empirical joint-score law gives the league event base rates exactly.
        empirical = {market: sum(outcomes(h, a)[market] for h, a in scores) / len(scores) for market in MARKETS}
        validate_markets(empirical)
        pooled = {market: sum(outcomes(h, a)[market] for h, a in self.global_scores) / len(self.global_scores)
                  for market in MARKETS}
        validate_markets(pooled)
        return {"league_base_rates": empirical, "empirical_score_distribution": pooled,
                "simple_poisson": PoissonScore(*self.global_rates, "simple_poisson", as_of, "global-goal-means-v1").markets()}
