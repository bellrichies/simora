"""Independent unit-test observations, never the application's outcome-selected demos."""

from dataclasses import asdict, replace
import contextlib
from datetime import datetime, timedelta, timezone
from hashlib import sha256
import json
import math
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import numpy as np

from simora_models.artifacts import load_mlpe, load_spe, write_artifact
from simora_models.data import Dataset, Fixture, Manifest, Revision
from simora_models.distribution import MARKETS, PoissonScore, outcomes, validate_markets
from simora_models.engines import Baselines, GoalRateCalibration, ShrunkPoisson, StructuredLightGBM
from simora_models.evaluation import WalkForwardSpec, bookmaker_implied, folds, walk_forward
from simora_models.features import FoldPreprocessor, decision_time, raw_features
from simora_models.__main__ import main

START = datetime(2026, 1, 1, tzinfo=timezone.utc)
MANIFEST = Manifest("synthetic", "demo", "unit_test", False, "a" * 64)


def dataset(rounds=20, matches=4):
    fixtures = []
    for round_index in range(rounds):
        kickoff = START + timedelta(minutes=5 * round_index)
        for match in range(matches):
            # No fixtures are selected by any desired model outcome or accuracy.
            home, away = (round_index * 7 + match * 3) % 5, (round_index * 3 + match) % 4
            available = kickoff + timedelta(seconds=30)
            revision = Revision(1, "accepted", home, away, available, available, available)
            fixtures.append(Fixture(f"f{round_index}_{match}", "test-platform", "test-league", "season-1",
                                    str(round_index), kickoff, kickoff - timedelta(minutes=2), "synthetic",
                                    (revision,), round_index + 1, None))
    return Dataset(MANIFEST, tuple(fixtures))


class DistributionTests(unittest.TestCase):
    def test_eight_markets_analytical_reference(self):
        score = PoissonScore(1, 1, "test", START)
        p = score.markets()
        self.assertEqual(set(p), set(MARKETS))
        self.assertAlmostEqual(p["over_15"], 1 - 3 * math.exp(-2))
        self.assertAlmostEqual(p["btts_yes"], (1 - math.exp(-1)) ** 2)
        validate_markets(p)

    def test_tail_extremes_and_normalization(self):
        for home, away in [(0, 0), (0, 4), (0.001, 0.01), (8, 12), (100, 100)]:
            score = PoissonScore(home, away, "test", START)
            dto = score.dto()
            self.assertAlmostEqual(np.sum(dto["goal_grid"]), 1, places=12)
            self.assertLess(dto["tail_policy"]["omitted_mass_bound"], 1e-12)
            validate_markets(dto["markets"])
        self.assertEqual(PoissonScore(0, 0, "test", START).markets()["under_35"], 1)

    def test_invalid_rates_and_probabilities(self):
        for rate in [-1, float("nan"), float("inf"), 101]:
            with self.assertRaises(ValueError):
                PoissonScore(rate, 1, "test", START)
        with self.assertRaises(ValueError):
            validate_markets({key: 0.4 for key in MARKETS})
        for tolerance in [0, -1, 1e-30, float("nan"), 1e-3]:
            with self.assertRaises(ValueError):
                PoissonScore(1, 1, "test", START).dto(tolerance)

    def test_market_outcomes_complement_every_score(self):
        for home in range(12):
            for away in range(12):
                validate_markets(outcomes(home, away))


class TimeAndDataTests(unittest.TestCase):
    def test_naive_clocks_rejected(self):
        with self.assertRaises(ValueError):
            replace(dataset().fixtures[0], kickoff_at=datetime(2026, 1, 1))

    def test_partition_and_outcome_selection_gates(self):
        data = dataset()
        with self.assertRaises(ValueError):
            Dataset(replace(MANIFEST, outcome_selected=True), data.fixtures)
        with self.assertRaises(ValueError):
            Dataset(replace(MANIFEST, purpose="research"), data.fixtures)
        with self.assertRaises(ValueError):
            Dataset(replace(MANIFEST, validation_use="release_eligible"), data.fixtures)
        with self.assertRaises(ValueError):
            Dataset(MANIFEST, (replace(data.fixtures[0], partition="authorized_live"),))

    def test_late_backfill_not_known_at_historical_cutoff(self):
        fixture = dataset().fixtures[0]
        late = replace(fixture.revisions[0], ingested_at=START + timedelta(days=1))
        changed = replace(fixture, revisions=(late,))
        data = Dataset(MANIFEST, (changed,))
        self.assertEqual(data.labels(START + timedelta(hours=1)), [])
        self.assertEqual(len(data.labels(START + timedelta(days=2))), 1)

    def test_correction_and_void_are_as_of_revisions(self):
        fixture = dataset().fixtures[0]
        correction_time = START + timedelta(hours=1)
        correction = Revision(2, "accepted", 8, 9, correction_time, correction_time, correction_time)
        void_time = START + timedelta(hours=2)
        void = Revision(3, "void", None, None, void_time, void_time, void_time)
        fixture = replace(fixture, revisions=(*fixture.revisions, correction, void))
        self.assertEqual(fixture.result_as_of(START + timedelta(minutes=1)).number, 1)
        self.assertEqual(fixture.result_as_of(correction_time).home, 8)
        self.assertEqual(Dataset(MANIFEST, (fixture,)).labels(void_time), [])

    def test_future_and_same_round_results_do_not_enter_features(self):
        data = dataset()
        target = data.fixtures[20]
        original = raw_features(data, target, 30, {target.segment: 0})
        changed = []
        for fixture in data.fixtures:
            if fixture.round_key == target.round_key or fixture.kickoff_at > target.kickoff_at:
                fixture = replace(fixture, revisions=(replace(fixture.revisions[0], home=99, away=99),))
            changed.append(fixture)
        altered = raw_features(Dataset(MANIFEST, tuple(changed)), target, 30, {target.segment: 0})
        np.testing.assert_equal(original, altered)

    def test_future_fixture_context_rejected(self):
        fixture = dataset().fixtures[-1]
        with self.assertRaises(ValueError):
            decision_time(replace(fixture, context_available_at=fixture.kickoff_at), 30)

    def test_result_revision_chronology_rejected(self):
        fixture = dataset().fixtures[0]
        with self.assertRaises(ValueError):
            replace(fixture, revisions=(fixture.revisions[0], fixture.revisions[0]))
        with self.assertRaises(ValueError):
            replace(fixture.revisions[0], available_at=START - timedelta(days=1))


class EngineTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = dataset()
        cls.cutoff = START + timedelta(minutes=50) - timedelta(seconds=30)
        cls.rows = cls.data.labels(cls.cutoff)
        cls.target = cls.data.fixtures[48]

    def test_spe_reproducible_shrinkage_and_coherence(self):
        first = ShrunkPoisson().fit(self.data, self.rows, self.cutoff)
        second = ShrunkPoisson().fit(self.data, self.rows, self.cutoff)
        self.assertEqual(first.artifact(), second.artifact())
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "spe.json"
            checksum = write_artifact(path, first)
            self.assertEqual(first.artifact(), load_spe(path, checksum).artifact())
        self.assertEqual(len(first.predict(self.target, decision_time(self.target, 30)).markets()), 8)
        sparse = replace(self.data.fixtures[0], id="sparse", league="sparse",
                         revisions=(replace(self.data.fixtures[0].revisions[0], home=10, away=10),))
        data = Dataset(MANIFEST, self.data.fixtures + (sparse,))
        fitted = ShrunkPoisson().fit(data, data.labels(self.cutoff), self.cutoff)
        self.assertLess(fitted.rates[sparse.segment][0], 10)
        self.assertGreater(fitted.rates[sparse.segment][0], first.rates[self.target.segment][0])

    def test_spe_time_decay_changes_rates(self):
        plain = ShrunkPoisson().fit(self.data, self.rows, self.cutoff)
        decayed = ShrunkPoisson(half_life_days=0.001).fit(self.data, self.rows, self.cutoff)
        self.assertNotEqual(plain.rates, decayed.rates)

    def test_baseline_cohort_and_calibration(self):
        baseline = Baselines().fit(self.data, self.rows, self.cutoff)
        for probabilities in baseline.predict(self.target, decision_time(self.target, 30)).values():
            validate_markets(probabilities)
        spe = ShrunkPoisson().fit(self.data, self.rows, self.cutoff)
        validation = self.data.labels(START + timedelta(minutes=65), self.data.fixtures[40:52])
        scores = [spe.predict(f, decision_time(f, 30)) for f, _ in validation]
        calibration = GoalRateCalibration().fit(scores, [r for _, r in validation], START + timedelta(minutes=65))
        future = spe.predict(self.data.fixtures[56], decision_time(self.data.fixtures[56], 30))
        validate_markets(calibration.apply(future).markets())
        with self.assertRaises(ValueError):
            calibration.apply(scores[0])
        with self.assertRaises(ValueError):
            GoalRateCalibration().fit(scores, [replace(r, ingested_at=START + timedelta(days=1)) for _, r in validation],
                                      START + timedelta(minutes=65))

    def test_training_rejects_future_detached_or_stale_labels(self):
        with self.assertRaises(ValueError):
            ShrunkPoisson().fit(self.data, self.data.labels(START + timedelta(days=1)), self.cutoff)
        fixture, revision = self.rows[0]
        with self.assertRaises(ValueError):
            StructuredLightGBM().fit(self.data, [(fixture, replace(revision, home=99)), *self.rows[1:]], self.cutoff, 30)

    def test_spe_inference_partition_and_kickoff_gates(self):
        model = ShrunkPoisson().fit(self.data, self.rows, self.cutoff)
        with self.assertRaises(ValueError):
            model.predict(self.target, self.target.kickoff_at)
        with self.assertRaises(ValueError):
            model.predict(replace(self.target, partition="authorized_live"), decision_time(self.target, 30))

    def test_mlpe_sparse_or_all_zero_goal_heads_fail_closed(self):
        with self.assertRaises(ValueError):
            StructuredLightGBM().fit(self.data, self.rows[:3], self.cutoff, 30)
        fixtures = tuple(replace(f, revisions=(replace(f.revisions[0], home=0),)) for f in self.data.fixtures)
        zero_data = Dataset(MANIFEST, fixtures)
        with self.assertRaisesRegex(ValueError, "all-zero"):
            StructuredLightGBM().fit(zero_data, zero_data.labels(self.cutoff), self.cutoff, 30)

    def test_preprocessing_fit_excludes_holdout(self):
        train = [f for f, _ in self.rows]
        first = FoldPreprocessor().fit(self.data, train, 30)
        poisoned = Dataset(MANIFEST, tuple(replace(f, round_number=10 ** 9) if f.kickoff_at > self.cutoff else f
                                           for f in self.data.fixtures))
        second = FoldPreprocessor().fit(poisoned, train, 30)
        np.testing.assert_equal(first.medians, second.medians)

    def test_lightgbm_determinism_target_isolation_and_artifact_roundtrip(self):
        first = StructuredLightGBM(rounds=12).fit(self.data, self.rows, self.cutoff, 30)
        second = StructuredLightGBM(rounds=12).fit(self.data, self.rows, self.cutoff, 30)
        as_of = decision_time(self.target, 30)
        p = first.predict(self.data, self.target, as_of).markets()
        self.assertEqual(p, second.predict(self.data, self.target, as_of).markets())
        poisoned_target = replace(self.target, revisions=(replace(self.target.revisions[0], home=88, away=77),))
        poisoned = Dataset(MANIFEST, tuple(poisoned_target if f.id == self.target.id else f for f in self.data.fixtures))
        self.assertEqual(p, first.predict(poisoned, poisoned_target, as_of).markets())
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "model.json"
            checksum = write_artifact(path, first)
            restored = load_mlpe(path, checksum)
            self.assertEqual(p, restored.predict(self.data, self.target, as_of).markets())
            with self.assertRaises(FileExistsError):
                write_artifact(path, first)
            with self.assertRaises(ValueError):
                load_mlpe(path, "0" * 64)
            content = json.loads(path.read_bytes())
            content["payload"]["feature_names"][0] = "future_score"
            path.write_text(json.dumps(content), encoding="utf-8")
            with self.assertRaises(ValueError):
                load_mlpe(path, sha256(path.read_bytes()).hexdigest())


class EvaluationTests(unittest.TestCase):
    def test_overlapping_rounds_from_multiple_leagues_stay_together(self):
        data = dataset()
        other = tuple(replace(f, id="other_" + f.id, league="other-league") for f in data.fixtures)
        mixed = Dataset(MANIFEST, data.fixtures + other)
        for fold in folds(mixed, WalkForwardSpec(8, 3, 3, 30, 30)):
            for key in ("train", "validation", "test"):
                ids = {f.id for f in fold[key]}
                for fixture in data.fixtures:
                    self.assertEqual(fixture.id in ids, "other_" + fixture.id in ids)

    def test_grouped_folds_and_embargo(self):
        data = dataset()
        spec = WalkForwardSpec(10, 3, 3, 30, 60)
        seen = set()
        for fold in folds(data, spec):
            sets = [{f.round_key for f in fold[key]} for key in ("train", "validation", "test")]
            self.assertFalse(sets[0] & sets[1] or sets[0] & sets[2] or sets[1] & sets[2])
            self.assertFalse(seen & sets[2])
            seen |= sets[2]
            self.assertTrue(all(f.kickoff_at < fold["train_cutoff"] for f in fold["train"]))

    def test_walk_forward_common_cohort_and_no_release(self):
        data = dataset(rounds=16)
        fixture = data.fixtures[44]
        as_of = decision_time(fixture, 30)
        # Explicitly synthetic unit-test prices; never a source observation.
        quote = {"fixture_id": fixture.id, "partition": "synthetic", "source_id": "unit-test-only",
                 "mapping_verified": True, "rights_verified": True, "observed_at": as_of,
                 "available_at": as_of, "ingested_at": as_of, "expires_at": fixture.kickoff_at,
                 "odds": {key: 2.0 for key in MARKETS}}
        report = walk_forward(data, WalkForwardSpec(8, 3, 3, 30, 30), START + timedelta(days=1), {fixture.id: quote})
        self.assertFalse(report["release_eligible"])
        self.assertTrue(report["folds"])
        for fold in report["folds"]:
            for model in fold["metrics"].values():
                self.assertEqual(set(model), set(MARKETS))
                self.assertTrue(all(values["count"] == fold["test_count"] for values in model.values()))
            for model in fold["market_evidence_gates"].values():
                self.assertEqual(set(model.values()), {"insufficient_evidence"})
            self.assertEqual(fold["bookmaker_baseline"]["fixture_ids"], [fixture.id])
            for model in fold["bookmaker_baseline"]["metrics"].values():
                self.assertTrue(all(values["count"] == 1 for values in model.values()))

    def test_bookmaker_baseline_requires_complete_accessible_quotes(self):
        fixture = dataset().fixtures[4]
        as_of = decision_time(fixture, 30)
        quote = {"fixture_id": fixture.id, "partition": fixture.partition, "source_id": "unit-test-only",
                 "mapping_verified": True, "rights_verified": True,
                 "observed_at": as_of, "available_at": as_of, "ingested_at": as_of,
                 "expires_at": fixture.kickoff_at, "odds": {key: 2.0 for key in MARKETS}}
        validate_markets(bookmaker_implied(quote, fixture, as_of))
        for invalid in [{**quote, "odds": {"over_15": 2.0}}, {**quote, "expires_at": as_of},
                        {**quote, "ingested_at": fixture.kickoff_at}, {**quote, "mapping_verified": False}]:
            with self.assertRaises(ValueError):
                bookmaker_implied(invalid, fixture, as_of)


class CliTests(unittest.TestCase):
    def test_offline_report_schema_and_immutable_output(self):
        data = dataset(rounds=16)
        payload = {"schema_version": "simora.research-dataset.v1", "manifest": asdict(data.manifest),
                   "fixtures": [asdict(fixture) for fixture in data.fixtures],
                   "fold_spec": asdict(WalkForwardSpec(8, 3, 3, 30, 30)),
                   "evaluation_as_of": (START + timedelta(days=1)).isoformat()}
        with tempfile.TemporaryDirectory() as directory:
            source, output = Path(directory) / "input.json", Path(directory) / "report.json"
            source.write_text(json.dumps(payload, default=lambda value: value.isoformat()), encoding="utf-8")
            argv = ["simora_models", "--input", str(source), "--output", str(output)]
            with patch("sys.argv", argv):
                self.assertEqual(main(), 0)
            report = json.loads(output.read_bytes())
            self.assertEqual(report["schema_version"], "simora.evaluation.v1")
            self.assertFalse(report["release_eligible"])
            with patch("sys.argv", argv), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(main(), 1)


if __name__ == "__main__":
    unittest.main()
