"""Research-only two-component Bayesian mixture with frozen forward OOF inputs.

For w ~ Beta(a,b), the score likelihood is product_i(w*p_i+(1-w)*q_i).
Expand latent allocation counts in log space, then integrate each term exactly.
The posterior is a finite mixture of Beta(a+k,b+n-k) laws, not a fitted
Dirichlet on fractional responsibilities or a point-estimate weighted average.
"""

from dataclasses import dataclass
from datetime import datetime
import math

import numpy as np
from scipy.optimize import brentq
from scipy.special import betaln, logsumexp
from scipy.stats import beta

from .data import digest, utc
from .distribution import MARKETS, PoissonScore, validate_markets


def _hash(value):
    if not isinstance(value, str) or len(value) != 64 or any(c not in "0123456789abcdef" for c in value):
        raise ValueError("SHA-256 required")


def _valid_prior(prior):
    # Explicit research numerical range avoids singular/overflowing shapes.
    return (isinstance(prior, tuple) and len(prior) == 2
            and all(math.isfinite(x) and 1e-3 <= x <= 1e6 for x in prior))


def validate_graph(edges):
    """Validate the complete version graph, including unreachable cycles."""
    if any(not isinstance(key, str) or not key or not isinstance(children, tuple)
           or len(set(children)) != len(children) for key, children in edges.items()):
        raise ValueError("Immutable unique version edges required")
    if any(child not in edges for children in edges.values() for child in children):
        raise ValueError("Missing component version")
    active, visited = set(), set()

    def visit(node):
        if node in active:
            raise ValueError("Ensemble component cycle")
        if node in visited:
            return
        active.add(node)
        for child in edges[node]:
            visit(child)
        active.remove(node)
        visited.add(node)

    for node in edges:
        visit(node)


@dataclass(frozen=True)
class FrozenComponent:
    version_id: str
    engine: str
    artifact_sha256: str
    training_digest: str
    fitted_fixture_ids: tuple[str, ...]
    fitted_round_keys: tuple[tuple[str, ...], ...]
    fit_cutoff: datetime
    frozen_at: datetime
    partition: str
    source_manifest_sha256: str

    def __post_init__(self):
        if not self.version_id or self.engine not in {"spe", "mlpe"}:
            raise ValueError("Frozen SPE/MLPE version required")
        for value in (self.artifact_sha256, self.training_digest, self.source_manifest_sha256):
            _hash(value)
        if (not isinstance(self.fitted_fixture_ids, tuple) or not self.fitted_fixture_ids
            or any(not isinstance(x, str) or not x for x in self.fitted_fixture_ids)
            or len(set(self.fitted_fixture_ids)) != len(self.fitted_fixture_ids)
            or not isinstance(self.fitted_round_keys, tuple) or not self.fitted_round_keys
            or any(not isinstance(x, tuple) or len(x) != 4 for x in self.fitted_round_keys)
            or len(set(self.fitted_round_keys)) != len(self.fitted_round_keys)
            or utc(self.frozen_at) < utc(self.fit_cutoff)):
            raise ValueError("Invalid immutable fitted lineage (include preprocessing/calibration)")


@dataclass(frozen=True)
class ComponentForecast:
    component: FrozenComponent
    fixture_id: str
    round_key: tuple[str, ...]
    partition: str
    score: PoissonScore
    recorded_at: datetime

    def __post_init__(self):
        if (not isinstance(self.score, PoissonScore) or not isinstance(self.round_key, tuple)
            or self.fixture_id in self.component.fitted_fixture_ids
            or self.round_key in self.component.fitted_round_keys
            or self.partition != self.component.partition
            or utc(self.component.frozen_at) > utc(self.score.as_of)
            or utc(self.recorded_at) < utc(self.score.as_of)
            or self.score.method.split(":")[0] != self.component.engine):
            raise ValueError("Component forecast is not frozen forward out-of-fold")


def freeze_artifact(path, expected_sha256, version_id, engine, dataset, fitted_ids, frozen_at):
    """Verify existing component bytes and reproduce their fitted data digest.

    This v1 adapter supports uncalibrated 5B artifacts only. A calibrated or
    retuned component needs its own complete, versioned lineage adapter.
    """
    model = _load_component(path, expected_sha256, engine)
    if not isinstance(fitted_ids, tuple) or len(set(fitted_ids)) != len(fitted_ids):
        raise ValueError("Unique immutable fitted fixture IDs required")
    owned = {f.id: f for f in dataset.fixtures}
    if not set(fitted_ids) <= owned.keys():
        raise ValueError("Missing fitted fixture")
    rows = dataset.labels(model.cutoff, [owned[i] for i in fitted_ids])
    if len(rows) != len(fitted_ids):
        raise ValueError("Missing point-in-time fitted label")
    payload = {"labels": [(f.id, r) for f, r in rows], "manifest": dataset.manifest}
    if engine == "mlpe":
        payload["matrix"] = model.preprocessor.transform(
            dataset, [f for f, _ in rows], model.buffer_seconds).tolist()
    if (digest(payload) != model.training_digest or model.partition != dataset.manifest.partition
        or model.source_manifest_sha256 != dataset.manifest.source_manifest_sha256):
        raise ValueError("Fitted data digest or source partition mismatch")
    return FrozenComponent(version_id, engine, expected_sha256, model.training_digest, fitted_ids,
                           tuple(sorted({f.round_key for f, _ in rows})), model.cutoff, frozen_at,
                           model.partition, model.source_manifest_sha256)


def _load_component(path, expected_sha256, engine):
    from .artifacts import load_mlpe, load_spe

    if engine not in {"spe", "mlpe"}:
        raise ValueError("Unsupported component engine")
    return (load_spe if engine == "spe" else load_mlpe)(path, expected_sha256)


def forecast_artifact(path, component, dataset, fixture, as_of, recorded_at):
    """Inference from exact pinned bytes; never fit or silently choose a version."""
    model = _load_component(path, component.artifact_sha256, component.engine)
    if (model.training_digest != component.training_digest or model.cutoff != component.fit_cutoff
        or model.partition != component.partition
        or model.source_manifest_sha256 != component.source_manifest_sha256
        or fixture not in dataset.fixtures
        or dataset.manifest.source_manifest_sha256 != component.source_manifest_sha256):
        raise ValueError("Frozen component lineage mismatch")
    score = (model.predict(fixture, as_of) if component.engine == "spe"
             else model.predict(dataset, fixture, as_of))
    return ComponentForecast(component, fixture.id, fixture.round_key, fixture.partition, score, recorded_at)


@dataclass(frozen=True)
class EnsemblePlan:
    version_id: str
    components: tuple[FrozenComponent, FrozenComponent]
    fit_ids: tuple[str, ...]
    holdout_ids: tuple[str, ...]
    declared_at: datetime
    fit_cutoff: datetime
    prior: tuple[float, float] = (1.0, 1.0)
    level: float = 0.9

    def __post_init__(self):
        if (not isinstance(self.components, tuple) or len(self.components) != 2
            or {c.engine for c in self.components} != {"spe", "mlpe"}
            or len({c.version_id for c in self.components}) != 2
            or self.version_id in {c.version_id for c in self.components}
            or not self.version_id):
            raise ValueError("Exactly two distinct frozen SPE/MLPE versions required")
        for ids in (self.fit_ids, self.holdout_ids):
            if (not isinstance(ids, tuple) or not ids or len(set(ids)) != len(ids)
                or any(not isinstance(x, str) or not x for x in ids)):
                raise ValueError("Predeclared unique fit and untouched holdout cohorts required")
        if (set(self.fit_ids) & set(self.holdout_ids)
            or not _valid_prior(self.prior)
            or not math.isfinite(self.level) or not 0 < self.level < 1
            or utc(self.declared_at) >= utc(self.fit_cutoff)):
            raise ValueError("Invalid split, prior, interval level or clock")
        validate_graph({self.version_id: tuple(c.version_id for c in self.components),
                        **{c.version_id: () for c in self.components}})


@dataclass(frozen=True)
class WeightPosterior:
    prior: tuple[float, float]
    count: int
    masses: tuple[float, ...]

    def __post_init__(self):
        if (not _valid_prior(self.prior)
            or type(self.count) is not int or not 0 <= self.count <= 5000
            or not isinstance(self.masses, tuple) or len(self.masses) != self.count + 1
            or any(not math.isfinite(x) or not 0 <= x <= 1 for x in self.masses)
            or abs(sum(self.masses) - 1) > 1e-10):
            raise ValueError("Invalid normalized weight posterior")

    @classmethod
    def fit(cls, log_likelihoods, prior=(1.0, 1.0)):
        values = np.asarray(log_likelihoods, dtype=float)
        if (values.ndim != 2 or values.shape[1] != 2 or not 1 <= len(values) <= 5000
            or np.any(np.isnan(values)) or np.any(values > 0)
            or np.any(np.all(np.isneginf(values), axis=1))):
            raise ValueError("Invalid component score likelihoods or research size bound")
        cls(prior, 0, (1.0,))
        coefficients = np.array([0.0])
        for first, second in values:
            updated = np.full(len(coefficients) + 1, -np.inf)
            updated[:-1] = coefficients + second
            updated[1:] = np.logaddexp(updated[1:], coefficients + first)
            coefficients = updated - logsumexp(updated)
        k = np.arange(len(coefficients))
        masses = coefficients + betaln(prior[0] + k, prior[1] + len(values) - k)
        masses = np.exp(masses - logsumexp(masses))
        return cls(prior, len(values), tuple(float(x) for x in masses))

    @property
    def shapes(self):
        k = np.arange(self.count + 1)
        return self.prior[0] + k, self.prior[1] + self.count - k

    @property
    def mean(self):
        a, b = self.shapes
        return float(np.dot(self.masses, a / (a + b)))

    def cdf(self, value):
        if not math.isfinite(value) or not 0 <= value <= 1:
            raise ValueError("Weight outside simplex")
        return float(np.dot(self.masses, beta.cdf(value, *self.shapes)))

    def quantile(self, probability):
        if not math.isfinite(probability) or not 0 <= probability <= 1:
            raise ValueError("Invalid posterior quantile")
        if probability in (0, 1):
            return float(probability)
        return float(brentq(lambda x: self.cdf(x) - probability, 0, 1, xtol=1e-13))

    def interval(self, level=0.9):
        if not math.isfinite(level) or not 0 < level < 1:
            raise ValueError("Invalid credible level")
        return self.quantile((1 - level) / 2), self.quantile((1 + level) / 2)


@dataclass(frozen=True)
class MixtureScore:
    components: tuple[PoissonScore, PoissonScore]
    weights: tuple[float, float]
    method: str = "regularized_ensemble"
    feature_version: str = "frozen-joint-mixture-v1"

    def __post_init__(self):
        if (not isinstance(self.components, tuple) or len(self.components) != 2
            or any(not isinstance(x, PoissonScore) for x in self.components)
            or not isinstance(self.weights, tuple) or len(self.weights) != 2
            or any(not math.isfinite(w) or not 0 <= w <= 1 for w in self.weights)
            or abs(sum(self.weights) - 1) > 1e-12
            or utc(self.components[0].as_of) != utc(self.components[1].as_of)):
            raise ValueError("Missing component, invalid simplex or mismatched decision time")

    @property
    def as_of(self):
        return self.components[0].as_of

    def markets(self):
        probabilities = [score.markets() for score in self.components]
        values = {m: sum(w * p[m] for w, p in zip(self.weights, probabilities)) for m in MARKETS}
        validate_markets(values)
        return values

    def log_score(self, home, away):
        terms = [math.log(w) + score.log_score(home, away) if w else -math.inf
                 for w, score in zip(self.weights, self.components)]
        return float(logsumexp(terms))

    def dto(self, tolerance=1e-12):
        dtos = [score.dto(tolerance) for score in self.components]
        height = max(len(dto["goal_grid"]) for dto in dtos)
        width = max(len(dto["goal_grid"][0]) for dto in dtos)
        grid = np.zeros((height, width))
        for weight, score in zip(self.weights, self.components):
            from scipy.stats import poisson

            grid += weight * np.outer(poisson.pmf(np.arange(height), score.home_rate),
                                      poisson.pmf(np.arange(width), score.away_rate))
        bound = sum(w * dto["tail_policy"]["omitted_mass_bound"] for w, dto in zip(self.weights, dtos))
        return {"goal_grid": (grid / grid.sum()).tolist(), "tail_policy": {
            "type": "analytic_poisson_mixture", "grid_conditioning": "retained_rectangle",
            "components": [{"weight": w, "home_rate": s.home_rate, "away_rate": s.away_rate}
                           for w, s in zip(self.weights, self.components)], "omitted_mass_bound": bound},
            "numerical_error_bound": bound + 1e-14, "method": self.method,
            "feature_version": self.feature_version, "as_of": utc(self.as_of).isoformat(),
            "markets": self.markets()}


def _cohort(dataset, plan, forecasts, ids):
    dataset.manifest.validate()
    fixtures = {f.id: f for f in dataset.fixtures}
    if not set(plan.fit_ids + plan.holdout_ids) <= fixtures.keys():
        raise ValueError("Missing predeclared fixture")
    fit_rounds = {fixtures[i].round_key for i in plan.fit_ids}
    test_rounds = {fixtures[i].round_key for i in plan.holdout_ids}
    if fit_rounds & test_rounds:
        raise ValueError("Fit and holdout share a virtual round")
    if (max(utc(fixtures[i].kickoff_at) for i in plan.fit_ids) >= utc(plan.fit_cutoff)
        or min(utc(fixtures[i].kickoff_at) for i in plan.holdout_ids) <= utc(plan.fit_cutoff)):
        raise ValueError("Holdout must follow ensemble fit cutoff")
    if not isinstance(forecasts, tuple) or len(forecasts) != 2 * len(ids):
        raise ValueError("Complete immutable matched component cohort required")
    rows = {}
    for forecast in forecasts:
        key = (forecast.fixture_id, forecast.component.version_id)
        if forecast.fixture_id not in ids or key in rows:
            raise ValueError("Unexpected or duplicate component forecast")
        f = fixtures[forecast.fixture_id]
        c = forecast.component
        if (c not in plan.components or forecast.round_key != f.round_key
            or forecast.partition != dataset.manifest.partition
            or c.source_manifest_sha256 != dataset.manifest.source_manifest_sha256
            or not utc(plan.declared_at) <= utc(forecast.score.as_of)
            or not utc(f.context_available_at) <= utc(forecast.score.as_of) <= utc(forecast.recorded_at) < utc(f.kickoff_at)):
            raise ValueError("Component scope, provenance or pre-event clock mismatch")
        # The trusted exporter must list every row used by fitting, preprocessing,
        # hyperparameter selection and calibration, not just final goal-head rows.
        if not set(c.fitted_fixture_ids) <= fixtures.keys():
            raise ValueError("Missing component fitted lineage")
        if {fixtures[i].round_key for i in c.fitted_fixture_ids} != set(c.fitted_round_keys):
            raise ValueError("Component fitted round lineage mismatch")
        if any(utc(fixtures[i].kickoff_at) >= utc(c.fit_cutoff)
               or fixtures[i].result_as_of(c.fit_cutoff) is None
               or fixtures[i].result_as_of(c.fit_cutoff).status != "accepted" for i in c.fitted_fixture_ids):
            raise ValueError("Component training is not point-in-time valid")
        rows[key] = forecast
    paired = []
    for identity in ids:
        pair = tuple(rows[(identity, c.version_id)] for c in plan.components)
        if utc(pair[0].score.as_of) != utc(pair[1].score.as_of):
            raise ValueError("Component decision clocks differ")
        paired.append((fixtures[identity], pair))
    return paired


@dataclass(frozen=True)
class BayesianEnsemble:
    plan: EnsemblePlan
    posterior: WeightPosterior
    training_digest: str

    code = "beve"

    def __post_init__(self):
        _hash(self.training_digest)
        if self.posterior.count != len(self.plan.fit_ids) or self.posterior.prior != self.plan.prior:
            raise ValueError("Posterior does not match fitted plan")

    @classmethod
    def fit(cls, dataset, plan, forecasts):
        rows = _cohort(dataset, plan, forecasts, plan.fit_ids)
        likelihoods, labels = [], []
        for fixture, pair in rows:
            label = fixture.result_as_of(plan.fit_cutoff)
            if label is None or label.status != "accepted":
                raise ValueError("Ensemble fit label unavailable at cutoff")
            likelihoods.append([p.score.log_score(label.home, label.away) for p in pair])
            labels.append((fixture.id, label))
        posterior = WeightPosterior.fit(likelihoods, plan.prior)
        return cls(plan, posterior, digest({"plan": plan, "forecasts": forecasts, "labels": labels,
                                          "manifest": dataset.manifest}))

    def predict(self, dataset, forecasts):
        if not isinstance(forecasts, tuple) or len(forecasts) != 2:
            raise ValueError("Both pinned components required; no implicit fallback")
        identity = forecasts[0].fixture_id
        # In this research boundary inference is restricted to the declared holdout.
        if identity not in self.plan.holdout_ids:
            raise ValueError("Inference outside predeclared holdout")
        rows = _cohort(dataset, self.plan, forecasts, (identity,))
        pair = rows[0][1]
        if utc(pair[0].score.as_of) < utc(self.plan.fit_cutoff):
            raise ValueError("Future posterior")
        w = self.posterior.mean
        return MixtureScore(tuple(p.score for p in pair), (w, 1 - w))

    def uncertainty(self, score):
        low, high = self.posterior.interval(self.plan.level)
        p, q = [s.markets() for s in score.components]
        intervals = {m: sorted((low * p[m] + (1 - low) * q[m],
                                high * p[m] + (1 - high) * q[m])) for m in MARKETS}
        return {"method": "exact_beta_mixture_equal_tail", "level": self.plan.level,
                "weight_interval": [low, high], "market_intervals": intervals,
                "scope": "weight_uncertainty_conditional_on_frozen_components",
                "component_parameter_uncertainty_included": False,
                "coverage_validated": False, "release_eligible": False}

    def artifact(self):
        from dataclasses import asdict
        import json

        plan = json.loads(json.dumps(asdict(self.plan), default=lambda x: utc(x).isoformat()))
        return {"type": "exact_beta_mixture_v1", "plan": plan,
                "posterior": asdict(self.posterior), "training_digest": self.training_digest,
                "operator_label": "regularized_ensemble", "coverage_validated": False,
                "release_eligible": False}

    def evaluate_holdout(self, dataset, forecasts, evaluation_as_of):
        rows = _cohort(dataset, self.plan, forecasts, self.plan.holdout_ids)
        if utc(evaluation_as_of) <= utc(self.plan.fit_cutoff):
            raise ValueError("Holdout evaluation before fit")
        from .distribution import outcomes

        metrics = {m: {"count": 0, "brier_sum": 0.0, "interval_width_sum": 0.0} for m in MARKETS}
        for fixture, pair in rows:
            label = fixture.result_as_of(evaluation_as_of)
            if label is None or label.status != "accepted":
                raise ValueError("Incomplete matched holdout labels")
            score = self.predict(dataset, pair)
            intervals = self.uncertainty(score)["market_intervals"]
            truth, probabilities = outcomes(label.home, label.away), score.markets()
            for m in MARKETS:
                metrics[m]["count"] += 1
                metrics[m]["brier_sum"] += (probabilities[m] - truth[m]) ** 2
                metrics[m]["interval_width_sum"] += intervals[m][1] - intervals[m][0]
        return {"schema_version": "simora.ensemble-evaluation.v1", "fit_digest": self.training_digest,
                "partition": dataset.manifest.partition,
                "source_manifest_sha256": dataset.manifest.source_manifest_sha256,
                "ensemble_version": self.plan.version_id,
                "component_versions": [c.version_id for c in self.plan.components],
                "evaluation_as_of": utc(evaluation_as_of).isoformat(),
                "holdout_manifest_sha256": digest({"forecasts": forecasts, "labels": [
                    (f.id, f.result_as_of(evaluation_as_of)) for f, _ in rows]}),
                "markets": {m: {"count": v["count"], "brier": v["brier_sum"] / v["count"],
                                  "mean_interval_width": v["interval_width_sum"] / v["count"]}
                            for m, v in metrics.items()},
                "interval_coverage": None,
                "coverage_reason": "A binary outcome does not reveal its latent event probability",
                "operator_label": "regularized_ensemble", "release_eligible": False}
