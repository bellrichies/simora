"""Independent algorithm fixtures only; no real provider odds, stakes or ROI claims."""

from dataclasses import replace
from datetime import datetime, timedelta, timezone
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

import numpy as np

from simora_models.data import Dataset, Fixture, Manifest, Revision
from simora_models.distribution import MARKETS, PoissonScore, outcomes
from simora_analytics.comparison import compare
from simora_analytics.contracts import ComparisonPlan, Forecast, Opportunity, Publication, Settlement
from simora_analytics.intervals import bootstrap
from simora_analytics.snapshots import read_snapshot, write_snapshot

START = datetime(2026, 1, 1, tzinfo=timezone.utc)
KEY = b"unit-test-report-key-never-production"


def scenario(rounds=8):
    fixtures = []
    for i in range(-1, rounds):
        kickoff = START - timedelta(days=1) if i == -1 else START + timedelta(minutes=5 * i)
        for j in range(2):
            at = kickoff + timedelta(seconds=30)
            revision = Revision(1, "accepted", (i + j) % 4, (i * 2 + j) % 3, at, at, at)
            fixtures.append(Fixture(f"f{i}:{j}", "unit-platform", "unit-league", "season", str(i), kickoff,
                                    kickoff - timedelta(minutes=2), "synthetic", (revision,)))
    data = Dataset(Manifest("synthetic", "demo", "unit_test", False, "a" * 64), tuple(fixtures))
    ids = tuple(f.id for f in fixtures[2:])
    plan = ComparisonPlan("unit-experiment", "unit-policy", "unit-platform", "unit-league", "synthetic", ids,
                          ("alpha-v1", "beta-v1"), ("base-v1",), START - timedelta(hours=1), START,
                          START + timedelta(minutes=5 * rounds), START + timedelta(hours=2),
                          60, 30, 6106, 300, 0.9, 3, "NGN")
    forecasts, opportunities, publications, settlements = [], [], [], []
    for i, f in enumerate(fixtures[2:]):
        for index, model in enumerate(plan.versions):
            score = PoissonScore(1.1 + index * 0.2, 1.4 + index * 0.1, model, plan.feature_at(f))
            fit_at = START - timedelta(hours=2)
            forecasts.append(Forecast(f.id, model, "synthetic", score, fit_at, fit_at,
                                      plan.feature_at(f) + timedelta(seconds=10), "bcde"[index] * 64,
                                      "f" * 64, "fold-1", tuple(x.id for x in fixtures[:2])))
            if model not in plan.model_versions:
                continue
            for market in MARKETS:
                qualifies = market == "over_15" and (model == "alpha-v1" or i % 2 == 0)
                identity = f"pub:{f.id}:{model}:{market}" if qualifies else None
                decision = plan.decision_at(f)
                opportunities.append(Opportunity(f.id, model, market, "synthetic", plan.policy_version,
                                                 decision, decision + timedelta(seconds=1),
                                                 "qualified" if qualifies else "no_selection", identity))
                if not qualifies:
                    continue
                published = decision + timedelta(seconds=2)
                stake = 100 if model == "alpha-v1" else 200
                pub = Publication(identity, f.id, model, market, "synthetic", plan.policy_version,
                                  decision, published, "quote:" + identity,
                                  decision - timedelta(seconds=20), decision - timedelta(seconds=10),
                                  decision - timedelta(seconds=10), f.kickoff_at,
                                  "2.50", True, True, stake, "recorded-test-exposure:" + identity, "NGN", "e" * 64,
                                  published + timedelta(seconds=1), published + timedelta(seconds=2))
                publications.append(pub)
                truth = outcomes(f.revisions[0].home, f.revisions[0].away)[market]
                at = f.kickoff_at + timedelta(seconds=40)
                settlements.append(Settlement("s:" + identity, identity, 1, None, 1, "won" if truth else "lost",
                                              at, at, at, stake * 5 // 2 if truth else 0, 2, "d" * 64, True))
    return data, plan, tuple(forecasts), tuple(opportunities), tuple(publications), tuple(settlements)


def report(parts):
    return compare(*parts, parts[1].evaluation_as_of + timedelta(seconds=1))


class ComparisonTests(unittest.TestCase):
    def test_common_denominators_separate_selected_coverage_and_exact_recorded_roi(self):
        parts = scenario()
        result = report(parts)
        self.assertEqual(result["skill"]["count"], 16)
        self.assertEqual(result["skill"]["round_clusters"], 8)
        for model in parts[1].versions:
            self.assertEqual(set(result["skill"]["models"][model]), set(MARKETS))
            self.assertTrue(all(m["count"] == 16 for m in result["skill"]["models"][model].values()))
        a = result["selected"]["alpha-v1"]["over_15"]
        b = result["selected"]["beta-v1"]["over_15"]
        self.assertEqual((a["selection_coverage"], b["selection_coverage"]), (1, 0.5))
        self.assertEqual(a["total_stake_minor"], 1600)
        net = sum(s.returned_minor - 100 - s.fee_minor for s in parts[5]
                  if "alpha-v1" in s.publication_id)
        self.assertEqual(a["net_minor"], net)
        self.assertAlmostEqual(float(a["roi"]), net / 1600)
        self.assertIsNotNone(a["roi_ci"]["lower"])
        self.assertIsNone(result["cross_market_totals"])
        self.assertFalse(result["release_eligible"])

    def test_missing_model_forecast_excludes_same_fixture_from_all_skill_models_only(self):
        parts = list(scenario())
        parts[2] = tuple(f for f in parts[2] if not (f.model_version == "base-v1" and f.fixture_id == "f0:0"))
        result = report(parts)
        self.assertEqual(result["skill"]["count"], 15)
        self.assertEqual(result["skill"]["excluded"]["f0:0"], ["missing_forecast:base-v1"])
        self.assertEqual(result["selected"]["alpha-v1"]["over_15"]["selected_count"], 16)

    def test_paired_identical_predictions_have_zero_delta_interval(self):
        parts = list(scenario())
        lookup = {f.fixture_id: f.score for f in parts[2] if f.model_version == "alpha-v1"}
        parts[2] = tuple(replace(f, score=lookup[f.fixture_id]) if f.model_version == "beta-v1" else f for f in parts[2])
        delta = report(parts)["skill"]["paired_deltas"]["alpha-v1"]["beta-v1"]
        for market in MARKETS:
            self.assertEqual(delta[market]["brier"]["ci"], {"lower": 0.0, "upper": 0.0, "reason": None})

    def test_bootstrap_clusters_keep_duplicated_fixture_exposures_together(self):
        plan = scenario()[1]
        keys = [(str(i),) for i in range(8)]
        values = np.arange(8, dtype=float).reshape(-1, 1)
        a = bootstrap(keys, values, plan)
        b = bootstrap([k for k in keys for _ in range(4)], np.repeat(values, 4, axis=0), plan)
        np.testing.assert_allclose(a, b)
        self.assertEqual(a.shape, (300, 1))

    def test_known_law_paired_interval_coverage_unit_diagnostic(self):
        plan = scenario()[1]
        rng = np.random.default_rng(61061)
        truth = -0.08  # Difference in Brier risk at pA=.6, pB=.4, P(Y=1)=.7.
        covered = 0
        from simora_analytics.intervals import interval

        for _ in range(160):
            # Ten fully correlated fixtures in each round must remain one block.
            y = np.repeat(rng.binomial(1, 0.7, size=60), 10)
            keys = [(str(i),) for i in range(60) for _ in range(10)]
            values = np.column_stack(((0.6 - y) ** 2, (0.4 - y) ** 2))
            samples = bootstrap(keys, values, plan)
            ci = interval(samples[:, 0] - samples[:, 1], 0.9)
            covered += ci["lower"] <= truth <= ci["upper"]
        self.assertTrue(0.79 <= covered / 160 <= 0.98, covered)
        print(f"UNIT ONLY paired-round interval coverage: {covered}/160 at nominal 90%")

    def test_void_return_is_consumed_from_verified_contract_not_assumed(self):
        parts = list(scenario())
        f = parts[0].fixtures[2]
        void = replace(f.revisions[0], status="void", home=None, away=None)
        parts[0] = Dataset(parts[0].manifest, tuple(replace(x, revisions=(void,)) if x.id == f.id else x
                                                 for x in parts[0].fixtures))
        ids = {p.id for p in parts[4] if p.fixture_id == f.id}
        # A test-specific verified refund amount; the evaluator does not create one.
        parts[5] = tuple(replace(s, outcome="void", returned_minor=90) if s.publication_id in ids else s
                         for s in parts[5])
        result = report(parts)
        self.assertEqual(result["selected"]["alpha-v1"]["over_15"]["counts"]["void"], 1)
        self.assertTrue(result["selected"]["alpha-v1"]["over_15"]["complete_money_cohort"])
        self.assertEqual(result["skill"]["count"], 15)

    def test_reproducible_reports_and_no_stake_is_null_not_zero_roi(self):
        parts = scenario()
        self.assertEqual(report(parts), report(parts))
        empty = report(parts)["selected"]["alpha-v1"]["under_35"]
        self.assertIsNone(empty["roi"])
        self.assertIsNone(empty["profit_factor"])
        self.assertEqual(empty["roi_ci"]["reason"], "no_recorded_stakes")

    def test_missing_exposure_unknown_fee_and_pending_settlement_never_invent_roi(self):
        original = scenario()
        for mode in ("stake", "fee", "settlement", "ledger"):
            parts = list(original)
            if mode == "stake":
                p = parts[4][0]
                parts[4] = (replace(p, stake_minor=None, stake_reference=None, stake_placed_at=None,
                                    stake_available_at=None),) + parts[4][1:]
            elif mode == "fee":
                parts[5] = (replace(parts[5][0], fee_minor=None),) + parts[5][1:]
            elif mode == "ledger":
                parts[5] = (replace(parts[5][0], ledger_verified=False),) + parts[5][1:]
            else:
                parts[5] = parts[5][1:]
            row = report(parts)["selected"]["alpha-v1"]["over_15"]
            self.assertIsNone(row["roi"], mode)
            self.assertIsNone(row["net_minor"], mode)
            self.assertFalse(row["complete_money_cohort"], mode)

    def test_no_pick_denominators_cannot_be_dropped_or_duplicated(self):
        for action in (lambda ops: ops[:-1], lambda ops: ops + (ops[0],)):
            parts = list(scenario())
            parts[3] = action(parts[3])
            with self.assertRaises(ValueError):
                report(parts)

    def test_late_forecast_publication_quote_and_duplicate_publication_fail(self):
        original = scenario()
        parts = list(original)
        parts[2] = (replace(parts[2][0], available_at=START),) + parts[2][1:]
        with self.assertRaises(ValueError):
            report(parts)
        parts = list(original)
        parts[4] = parts[4] + (parts[4][0],)
        with self.assertRaises(ValueError):
            report(parts)
        p = original[4][0]
        for change in ({"rights_verified": False}, {"odds_decimal": "NaN"},
                       {"quote_ingested_at": p.decided_at + timedelta(seconds=1)},
                       {"quote_expires_at": p.published_at}):
            with self.assertRaises(ValueError):
                replace(p, **change)

    def test_frozen_version_round_and_future_training_gates(self):
        original = scenario()
        for change in ({"artifact_sha256": "a" * 64}, {"fitted_fixture_ids": ("f0:1",)}):
            parts = list(original)
            parts[2] = (replace(parts[2][0], **change),) + parts[2][1:]
            with self.assertRaises(ValueError):
                report(parts)

    def test_void_disputed_and_unknown_results_use_common_exclusions(self):
        parts = list(scenario())
        fixtures = list(parts[0].fixtures)
        for index, status in ((2, "void"), (3, "disputed")):
            fixtures[index] = replace(fixtures[index], revisions=(replace(fixtures[index].revisions[0],
                                      status=status, home=None, away=None),))
        fixtures[4] = replace(fixtures[4], revisions=())
        parts[0] = Dataset(parts[0].manifest, tuple(fixtures))
        # No settlements are asserted against the changed source state.
        parts[5] = ()
        result = report(parts)
        self.assertEqual(result["skill"]["count"], 13)
        self.assertEqual(result["skill"]["excluded"]["f0:0"], ["void"])
        self.assertEqual(result["skill"]["excluded"]["f0:1"], ["disputed"])
        self.assertEqual(result["skill"]["excluded"]["f1:0"], ["no_result_as_of"])

    def test_correction_creates_new_snapshot_without_changing_historical_asof_report(self):
        parts = list(scenario())
        old = report(parts)
        f = parts[0].fixtures[2]
        at = START + timedelta(days=1)
        correction = Revision(2, "accepted", 9, 8, at, at, at)
        revised = replace(f, revisions=f.revisions + (correction,))
        parts[0] = Dataset(parts[0].manifest, tuple(revised if x.id == f.id else x for x in parts[0].fixtures))
        corrections = []
        for s in parts[5]:
            pub = next(p for p in parts[4] if p.id == s.publication_id)
            if pub.fixture_id == f.id:
                corrections.append(replace(s, id=s.id + ":r2", revision=2, supersedes_id=s.id,
                    result_revision=2, outcome="won", observed_at=at + timedelta(seconds=1),
                    available_at=at + timedelta(seconds=1), ingested_at=at + timedelta(seconds=1),
                    returned_minor=pub.stake_minor * 5 // 2))
        parts[5] += tuple(corrections)
        self.assertEqual(old, report(parts))
        parts[1] = replace(parts[1], evaluation_as_of=START + timedelta(days=2), supersedes_report_sha256="a" * 64)
        new = report(parts)
        self.assertNotEqual(old["skill"]["result_manifest_sha256"], new["skill"]["result_manifest_sha256"])
        self.assertEqual(new["supersedes_report_sha256"], "a" * 64)

    def test_broken_correction_chain_and_outcome_inconsistency_fail(self):
        original = scenario()
        for change in ({"outcome": "void"}, {"result_revision": 2}):
            parts = list(original)
            parts[5] = (replace(parts[5][0], **change),) + parts[5][1:]
            with self.assertRaises(ValueError):
                report(parts)
        parts = list(original)
        parts[5] = parts[5] + (parts[5][0],)
        with self.assertRaises(ValueError):
            report(parts)

    def test_insufficient_rounds_withhold_intervals(self):
        result = report(scenario(2))
        self.assertEqual(result["skill"]["models"]["alpha-v1"]["over_15"]["brier_ci"]["reason"],
                         "insufficient_round_clusters")
        self.assertIsNone(result["selected"]["alpha-v1"]["over_15"]["roi_ci"]["lower"])

    def test_synthetic_outcome_selected_partition_and_prematch_plan_gates(self):
        parts = list(scenario())
        with self.assertRaises(ValueError):
            Dataset(replace(parts[0].manifest, outcome_selected=True), parts[0].fixtures)
        parts[1] = replace(parts[1], partition="authorized_live")
        with self.assertRaises(ValueError):
            report(parts)
        with self.assertRaises(ValueError):
            replace(scenario()[1], declared_at=START)

    def test_snapshot_exclusive_signature_tamper_and_research_only(self):
        data = report(scenario())
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "report.json"
            digest = write_snapshot(path, data, "unit-key", KEY)
            self.assertEqual(read_snapshot(path, digest, {"unit-key": KEY}), data)
            with self.assertRaises(FileExistsError):
                write_snapshot(path, data, "unit-key", KEY)
            with self.assertRaises(ValueError):
                read_snapshot(path, digest, {"unit-key": b"other-unit-key-that-is-long-enough"})
            envelope = json.loads(path.read_text(encoding="utf-8"))
            envelope["payload_json"] += " "
            path.write_text(json.dumps(envelope))
            with self.assertRaises(ValueError):
                read_snapshot(path, digest, {"unit-key": KEY})
            with self.assertRaises(ValueError):
                write_snapshot(Path(folder) / "approved.json", {**data, "release_eligible": True}, "unit-key", KEY)

    def test_php_verifies_exact_python_snapshot_signature(self):
        import shutil

        if not shutil.which("php"):
            self.skipTest("PHP CLI required for cross-language report contract")
        root = Path(__file__).resolve().parents[3]
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "snapshot.json"
            digest = write_snapshot(path, report(scenario()), "unit-key", KEY)
            script = ("require $argv[1].'/vendor/autoload.php'; "
                      "$v=new App\\Domain\\ModelComparison\\ComparisonSnapshot(['unit-key'=>$argv[4]]); "
                      "$r=$v->verify(file_get_contents($argv[2]),$argv[3]); "
                      "echo $r['schema_version'];")
            process = subprocess.run(["php", "-r", script, str(root), str(path), digest, KEY.decode()],
                                     capture_output=True, text=True, check=True)
            self.assertEqual(process.stdout, "simora.comparison-report.v1")


if __name__ == "__main__":
    unittest.main()
