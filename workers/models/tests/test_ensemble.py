"""BEVE algorithm/lineage tests; synthetic data is never release evidence."""

from dataclasses import replace
from datetime import timedelta
from hashlib import sha256
import json
import math
from pathlib import Path
import tempfile
import unittest

import numpy as np
from scipy.integrate import quad
from scipy.stats import beta, poisson

from simora_models.artifacts import load_beve, write_artifact
from simora_models.data import Dataset
from simora_models.distribution import MARKETS, PoissonScore, validate_markets
from simora_models.engines import ShrunkPoisson, StructuredLightGBM
from simora_models.ensemble import (BayesianEnsemble, ComponentForecast, EnsemblePlan,
                                    FrozenComponent, MixtureScore, WeightPosterior,
                                    forecast_artifact, freeze_artifact, validate_graph)
from test_models import START, dataset


def scenario():
    data = dataset(rounds=12, matches=2)
    training = data.fixtures[:8]
    cutoff = START + timedelta(minutes=16)
    components = tuple(FrozenComponent(
        engine + "-frozen-v1", engine, code * 64, "d" * 64,
        tuple(f.id for f in training), tuple(sorted({f.round_key for f in training})),
        cutoff, cutoff, "synthetic", data.manifest.source_manifest_sha256)
        for engine, code in (("spe", "b"), ("mlpe", "c")))
    plan = EnsemblePlan("ensemble-v1", components, tuple(f.id for f in data.fixtures[8:16]),
                        tuple(f.id for f in data.fixtures[16:]), cutoff,
                        START + timedelta(minutes=36))

    def forecasts(ids):
        return tuple(ComponentForecast(c, f.id, f.round_key, f.partition,
                     PoissonScore(1 + k * 2, 1 + k, c.engine, f.kickoff_at - timedelta(seconds=10)),
                     f.kickoff_at - timedelta(seconds=5))
                     for f in data.fixtures if f.id in ids for k, c in enumerate(components))

    return data, plan, forecasts(plan.fit_ids), forecasts(plan.holdout_ids)


class PosteriorTests(unittest.TestCase):
    def test_exact_posterior_against_independent_quadrature(self):
        probabilities = np.array([[0.2, 0.1], [0.03, 0.3], [0.4, 0.2]])
        prior = (2.0, 3.0)
        posterior = WeightPosterior.fit(np.log(probabilities), prior)

        def density(w):
            return beta.pdf(w, *prior) * np.prod(w * probabilities[:, 0] + (1 - w) * probabilities[:, 1])

        normalizer = quad(density, 0, 1)[0]
        self.assertAlmostEqual(posterior.mean, quad(lambda w: w * density(w), 0, 1)[0] / normalizer, places=11)
        for x in (0.1, 0.4, 0.9):
            self.assertAlmostEqual(posterior.cdf(x), quad(density, 0, x)[0] / normalizer, places=11)
        for q in (0.01, 0.5, 0.99):
            self.assertAlmostEqual(posterior.cdf(posterior.quantile(q)), q, places=10)

    def test_identical_components_preserve_prior_uncertainty(self):
        posterior = WeightPosterior.fit(np.log([[0.01, 0.01]] * 300), (2.0, 4.0))
        self.assertAlmostEqual(posterior.mean, 1 / 3, places=12)
        self.assertAlmostEqual(posterior.quantile(0.05), beta.ppf(0.05, 2, 4), places=11)

    def test_zero_support_and_extreme_likelihoods(self):
        posterior = WeightPosterior.fit([[0.0, -math.inf]] * 50)
        self.assertAlmostEqual(posterior.mean, 51 / 52)
        self.assertEqual(posterior.masses[-1], 1)
        extreme = WeightPosterior.fit([[-10000.0, -10001.0]] * 100)
        self.assertTrue(math.isfinite(extreme.mean))
        self.assertAlmostEqual(sum(extreme.masses), 1)

    def test_invalid_weights_and_likelihoods_fail(self):
        for values in ([], [[0, 0, 0]], [[float("nan"), 0]], [[1, 0]], [[-math.inf, -math.inf]]):
            with self.assertRaises(ValueError):
                WeightPosterior.fit(values)
        for prior in ((0, 1), (-1, 2), (float("inf"), 1), (1e-10, 1), (1e308, 1)):
            with self.assertRaises(ValueError):
                WeightPosterior.fit([[-1, -2]], prior)
        for masses in ((0.2, 0.2), (-0.1, 1.1), (float("nan"), 0.0)):
            with self.assertRaises(ValueError):
                WeightPosterior((1.0, 1.0), 1, masses)

    def test_prior_predictive_interval_coverage_known_generative_law(self):
        # Repeated prior-predictive experiment: truth is known only in this
        # test, so credible probability coverage is measurable here.
        rng = np.random.default_rng(5301)
        covered, market_covered, widths = 0, 0, []
        p = PoissonScore(0.4, 0.7, "spe", START).markets()["over_25"]
        q = PoissonScore(3.0, 2.0, "mlpe", START).markets()["over_25"]
        for _ in range(240):
            truth = rng.beta(2, 2)
            first = rng.random(60) < truth
            home = rng.poisson(np.where(first, 0.4, 3.0))
            away = rng.poisson(np.where(first, 0.7, 2.0))
            logs = np.column_stack((poisson.logpmf(home, 0.4) + poisson.logpmf(away, 0.7),
                                    poisson.logpmf(home, 3.0) + poisson.logpmf(away, 2.0)))
            posterior = WeightPosterior.fit(logs, (2.0, 2.0))
            lo, hi = posterior.interval(0.9)
            covered += lo <= truth <= hi
            interval = sorted((lo * p + (1 - lo) * q, hi * p + (1 - hi) * q))
            market_covered += interval[0] <= truth * p + (1 - truth) * q <= interval[1]
            widths.append(hi - lo)
        self.assertEqual(covered, market_covered)
        self.assertTrue(0.82 <= covered / 240 <= 0.97, covered)
        self.assertTrue(0.05 < np.mean(widths) < 0.4)
        print(f"BEVE unit-only prior-predictive coverage: {covered}/240; mean weight width={np.mean(widths):.6f}")


class CoherenceTests(unittest.TestCase):
    def test_joint_mixture_not_average_goal_rates(self):
        a, b = PoissonScore(0.1, 0.2, "spe", START), PoissonScore(4, 3, "mlpe", START)
        score = MixtureScore((a, b), (0.3, 0.7))
        validate_markets(score.markets())
        for market in MARKETS:
            self.assertAlmostEqual(score.markets()[market], 0.3 * a.markets()[market] + 0.7 * b.markets()[market])
        self.assertNotAlmostEqual(score.markets()["over_25"], PoissonScore(2.83, 2.16, "wrong", START).markets()["over_25"])
        self.assertAlmostEqual(math.exp(score.log_score(1, 2)),
                               0.3 * math.exp(a.log_score(1, 2)) + 0.7 * math.exp(b.log_score(1, 2)))

    def test_mixture_tail_grid_and_edge_weights(self):
        a, b = PoissonScore(0, 0, "spe", START), PoissonScore(100, 100, "mlpe", START)
        for weights in ((0.0, 1.0), (1.0, 0.0), (0.2, 0.8)):
            score = MixtureScore((a, b), weights)
            dto = score.dto()
            self.assertAlmostEqual(np.sum(dto["goal_grid"]), 1)
            self.assertLess(dto["numerical_error_bound"], 2e-12)
            validate_markets(dto["markets"])
        for weights in ((-0.1, 1.1), (0.3, 0.3), (float("nan"), 1)):
            with self.assertRaises(ValueError):
                MixtureScore((a, b), weights)
        with self.assertRaises(ValueError):
            MixtureScore((a, replace(b, as_of=START + timedelta(seconds=1))), (0.5, 0.5))

    def test_graph_rejects_self_indirect_disconnected_cycles_and_missing(self):
        validate_graph({"e": ("spe", "mlpe"), "spe": (), "mlpe": ()})
        for graph in ({"e": ("e",)}, {"a": ("b",), "b": ("c",), "c": ("a",)},
                      {"e": (), "x": ("y",), "y": ("x",)}, {"e": ("missing",)},
                      {"e": ("spe", "spe"), "spe": ()}):
            with self.assertRaises(ValueError):
                validate_graph(graph)


class EnsembleLineageTests(unittest.TestCase):
    def test_fit_holdout_artifact_roundtrip_and_no_refit(self):
        data, plan, fit, test = scenario()
        model = BayesianEnsemble.fit(data, plan, fit)
        before = model.artifact()
        report = model.evaluate_holdout(data, test, START + timedelta(hours=2))
        self.assertEqual(model.artifact(), before)
        self.assertEqual(set(report["markets"]), set(MARKETS))
        self.assertTrue(all(x["count"] == len(plan.holdout_ids) for x in report["markets"].values()))
        self.assertIsNone(report["interval_coverage"])
        score = model.predict(data, test[:2])
        intervals = model.uncertainty(score)
        for market in MARKETS:
            self.assertLessEqual(intervals["market_intervals"][market][0], score.markets()[market])
            self.assertGreaterEqual(intervals["market_intervals"][market][1], score.markets()[market])
        self.assertFalse(intervals["coverage_validated"])
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "beve.json"
            sha = write_artifact(path, model)
            restored = load_beve(path, sha)
            self.assertEqual(restored.artifact(), model.artifact())
            self.assertEqual(restored.predict(data, test[:2]).dto(), score.dto())
            with self.assertRaises(FileExistsError):
                write_artifact(path, model)
            path.write_text(path.read_text() + " ")
            with self.assertRaisesRegex(ValueError, "SHA mismatch"):
                load_beve(path, sha)

    def test_changed_test_outcomes_do_not_change_fit_or_prediction(self):
        data, plan, fit, test = scenario()
        changed = Dataset(data.manifest, tuple(replace(f, revisions=(replace(f.revisions[0], home=77, away=55),))
                        if f.id in plan.holdout_ids else f for f in data.fixtures))
        a, b = BayesianEnsemble.fit(data, plan, fit), BayesianEnsemble.fit(changed, plan, fit)
        self.assertEqual(a.artifact(), b.artifact())
        self.assertEqual(a.predict(data, test[:2]).markets(), b.predict(changed, test[:2]).markets())

    def test_fit_rejects_test_input_missing_or_duplicate_components(self):
        data, plan, fit, test = scenario()
        for forecasts in (test, fit[:-1], fit[:-1] + (fit[0],)):
            with self.assertRaises(ValueError):
                BayesianEnsemble.fit(data, plan, forecasts)
        model = BayesianEnsemble.fit(data, plan, fit)
        with self.assertRaises(ValueError):
            model.predict(data, test[:1])
        with self.assertRaises(ValueError):
            model.predict(data, fit[:2])

    def test_late_label_and_forecast_rejected(self):
        data, plan, fit, _ = scenario()
        first = data.fixtures[8]
        changed = Dataset(data.manifest, tuple(replace(f, revisions=(replace(f.revisions[0],
                          ingested_at=START + timedelta(days=1)),)) if f == first else f for f in data.fixtures))
        with self.assertRaisesRegex(ValueError, "unavailable"):
            BayesianEnsemble.fit(changed, plan, fit)
        late = replace(fit[0], recorded_at=first.kickoff_at)
        with self.assertRaises(ValueError):
            BayesianEnsemble.fit(data, plan, (late,) + fit[1:])

    def test_frozen_version_partition_round_and_clock_checks(self):
        data, plan, fit, _ = scenario()
        f = fit[0]
        for changes in ({"partition": "authorized_live"}, {"fixture_id": plan.components[0].fitted_fixture_ids[0]},
                        {"round_key": plan.components[0].fitted_round_keys[0]},
                        {"component": replace(f.component, frozen_at=f.score.as_of + timedelta(seconds=1))}):
            with self.assertRaises(ValueError):
                replace(f, **changes)
        foreign = replace(f, component=replace(f.component, artifact_sha256="e" * 64))
        with self.assertRaises(ValueError):
            BayesianEnsemble.fit(data, plan, (foreign,) + fit[1:])
        clock = replace(fit[1], score=replace(fit[1].score, as_of=f.score.as_of + timedelta(seconds=1)))
        with self.assertRaises(ValueError):
            BayesianEnsemble.fit(data, plan, (f, clock) + fit[2:])

    def test_round_overlap_and_retroactive_plan_rejected(self):
        data, plan, fit, _ = scenario()
        with self.assertRaises(ValueError):
            replace(plan, holdout_ids=(plan.fit_ids[0],))
        same_round = replace(data.fixtures[16], round_id=data.fixtures[8].round_id)
        changed = Dataset(data.manifest, tuple(same_round if f.id == same_round.id else f for f in data.fixtures))
        with self.assertRaisesRegex(ValueError, "share a virtual round"):
            BayesianEnsemble.fit(changed, plan, fit)
        with self.assertRaises(ValueError):
            BayesianEnsemble.fit(data, replace(plan, declared_at=plan.declared_at + timedelta(minutes=10)), fit)

    def test_synthetic_and_source_gates(self):
        data, plan, fit, _ = scenario()
        with self.assertRaises(ValueError):
            Dataset(replace(data.manifest, outcome_selected=True), data.fixtures)
        changed = Dataset(replace(data.manifest, source_manifest_sha256="e" * 64), data.fixtures)
        with self.assertRaises(ValueError):
            BayesianEnsemble.fit(changed, plan, fit)

    def test_artifact_refuses_promotion_and_posterior_plan_mismatch(self):
        data, plan, fit, _ = scenario()
        model = BayesianEnsemble.fit(data, plan, fit)
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "beve.json"
            write_artifact(path, model)
            original = json.loads(path.read_text())
            for key, value in (("coverage_validated", True), ("release_eligible", True),
                               ("operator_label", "beve")):
                changed = json.loads(json.dumps(original))
                changed["payload"][key] = value
                encoded = json.dumps(changed).encode()
                path.write_bytes(encoded)
                with self.assertRaises(ValueError):
                    load_beve(path, sha256(encoded).hexdigest())
            changed = json.loads(json.dumps(original))
            changed["payload"]["plan"]["prior"] = [2.0, 2.0]
            encoded = json.dumps(changed).encode()
            path.write_bytes(encoded)
            with self.assertRaisesRegex(ValueError, "does not match"):
                load_beve(path, sha256(encoded).hexdigest())

    def test_real_component_artifacts_frozen_and_replayed(self):
        data = dataset(12, 4)
        cutoff = START + timedelta(minutes=16)
        rows = data.labels(cutoff)
        spe = ShrunkPoisson().fit(data, rows, cutoff)
        mlpe = StructuredLightGBM(rounds=2, min_data_in_leaf=2).fit(data, rows, cutoff, 10)
        with tempfile.TemporaryDirectory() as folder:
            components, paths = [], []
            ids = tuple(f.id for f, _ in rows)
            for model in (spe, mlpe):
                path = Path(folder) / (model.code + ".json")
                sha = write_artifact(path, model)
                c = freeze_artifact(path, sha, model.code + "-1", model.code, data, ids, cutoff)
                components.append(c)
                paths.append(path)
                with self.assertRaises(ValueError):
                    freeze_artifact(path, sha, "bad", model.code, data, ids[1:], cutoff)
            plan = EnsemblePlan("e-1", tuple(components), tuple(f.id for f in data.fixtures[16:32]),
                                tuple(f.id for f in data.fixtures[32:]), cutoff, START + timedelta(minutes=36))
            forecasts = tuple(forecast_artifact(path, c, data, f, f.kickoff_at - timedelta(seconds=10),
                              f.kickoff_at - timedelta(seconds=5))
                              for f in data.fixtures[16:] for path, c in zip(paths, components))
            ensemble = BayesianEnsemble.fit(data, plan, forecasts[:32])
            score = ensemble.predict(data, forecasts[32:34])
            validate_markets(score.markets())
            self.assertEqual(ensemble.evaluate_holdout(data, forecasts[32:], START + timedelta(hours=2))
                             ["markets"]["under_15"]["count"], 16)
            paths[0].write_text("{}")
            with self.assertRaisesRegex(ValueError, "SHA mismatch"):
                forecast_artifact(paths[0], components[0], data, data.fixtures[32],
                                  data.fixtures[32].kickoff_at - timedelta(seconds=10),
                                  data.fixtures[32].kickoff_at - timedelta(seconds=5))


if __name__ == "__main__":
    unittest.main()
