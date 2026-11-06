"""Matched forecast skill and separately recorded selected returns, never training."""

from decimal import Decimal, localcontext, ROUND_HALF_EVEN
from hashlib import sha256
from importlib.metadata import version as dependency_version
from pathlib import Path

import numpy as np

from simora_models.data import digest, utc
from simora_models.distribution import MARKETS, outcomes
from simora_models.evaluation import metrics

from . import __version__
from .intervals import bootstrap, interval


def _money_ratio(numerator, denominator):
    if denominator == 0:
        return None
    with localcontext() as ctx:
        ctx.prec, ctx.rounding = 28, ROUND_HALF_EVEN
        return str(Decimal(numerator) / Decimal(denominator))


def _forecast_index(dataset, plan, forecasts, fixtures):
    if not isinstance(forecasts, tuple):
        raise ValueError("Immutable forecast tuple required")
    all_fixtures = {f.id: f for f in dataset.fixtures}
    indexed, versions = {}, {}
    for row in forecasts:
        key = (row.fixture_id, row.model_version)
        if row.fixture_id not in fixtures or row.model_version not in plan.versions or key in indexed:
            raise ValueError("Unexpected or duplicate forecast")
        f = fixtures[row.fixture_id]
        if (row.partition != plan.partition or utc(row.score.as_of) != plan.feature_at(f)
            or utc(row.available_at) > plan.decision_at(f)
            or utc(f.context_available_at) > utc(row.score.as_of)
            or utc(plan.declared_at) > utc(row.score.as_of)):
            raise ValueError("Forecast partition or decision clock violates declared protocol")
        lineage = (row.artifact_sha256, row.fitted_through, row.frozen_at,
                   row.lineage_sha256, tuple(sorted(row.fitted_fixture_ids)))
        if row.model_version in versions and versions[row.model_version] != lineage:
            raise ValueError("A frozen model version cannot change bytes or fitted lineage")
        versions[row.model_version] = lineage
        for identity in row.fitted_fixture_ids:
            fitted = all_fixtures.get(identity)
            if (fitted is None or fitted.round_key == f.round_key
                or utc(fitted.kickoff_at) >= utc(row.fitted_through)
                or fitted.result_as_of(row.fitted_through) is None
                or fitted.result_as_of(row.fitted_through).status != "accepted"):
                raise ValueError("Component/model fit includes a target round, future or unavailable label")
        row.score.markets()
        indexed[key] = row
    return indexed


def _skill(dataset, plan, fixtures, forecasts):
    matched, exclusions = [], {}
    for identity in plan.fixture_ids:
        fixture = fixtures[identity]
        label = fixture.result_as_of(plan.evaluation_as_of)
        reasons = []
        if label is None:
            reasons.append("no_result_as_of")
        elif label.status != "accepted":
            reasons.append(label.status)
        for version in plan.versions:
            if (identity, version) not in forecasts:
                reasons.append("missing_forecast:" + version)
        if reasons:
            exclusions[identity] = reasons
        else:
            matched.append((fixture, label))
    if not matched:
        return {"status": "insufficient_evidence", "count": 0, "round_clusters": 0,
                "fixture_ids": [], "excluded": exclusions, "models": {}, "paired_deltas": {}}
    probabilities = {v: [forecasts[(f.id, v)].score.markets() for f, _ in matched] for v in plan.versions}
    targets = [outcomes(r.home, r.away) for _, r in matched]
    summaries = {v: metrics(probabilities[v], targets) for v in plan.versions}
    columns, names = [], []
    for version in plan.versions:
        for market in MARKETS:
            p = np.asarray([row[market] for row in probabilities[version]])
            y = np.asarray([row[market] for row in targets])
            clipped = np.clip(p, 1e-15, 1 - 1e-15)
            for name, values in (("brier", (p - y) ** 2),
                                 ("log_loss", -(y * np.log(clipped) + (1 - y) * np.log1p(-clipped)))):
                names.append((version, market, name))
                columns.append(values)
    samples = bootstrap([f.round_key for f, _ in matched], np.column_stack(columns), plan)
    positions = {key: i for i, key in enumerate(names)}
    for (version, market, metric), position in positions.items():
        summaries[version][market][metric + "_ci"] = interval(
            None if samples is None else samples[:, position], plan.confidence_level)
    deltas = {}
    for version in plan.model_versions:
        deltas[version] = {}
        for comparator in plan.versions:
            if comparator == version:
                continue
            deltas[version][comparator] = {}
            for market in MARKETS:
                deltas[version][comparator][market] = {}
                for metric in ("brier", "log_loss"):
                    a, b = positions[(version, market, metric)], positions[(comparator, market, metric)]
                    deltas[version][comparator][market][metric] = {
                        "estimate": summaries[version][market][metric] - summaries[comparator][market][metric],
                        "ci": interval(None if samples is None else samples[:, a] - samples[:, b],
                                       plan.confidence_level)}
    return {"status": "research_only", "count": len(matched),
            "round_clusters": len({f.round_key for f, _ in matched}),
            "opportunity_count": len(plan.fixture_ids), "coverage": len(matched) / len(plan.fixture_ids),
            "fixture_ids": [f.id for f, _ in matched], "excluded": exclusions,
            "result_manifest_sha256": digest([(f.id, r) for f, r in matched]),
            "models": summaries, "paired_deltas": deltas}


def _selection_index(plan, fixtures, forecasts, opportunities, publications, settlements):
    if any(not isinstance(rows, tuple) for rows in (opportunities, publications, settlements)):
        raise ValueError("Immutable opportunity, publication and settlement tuples required")
    opportunities_by_key, publications_by_id = {}, {}
    for row in opportunities:
        key = (row.fixture_id, row.model_version, row.market)
        if (key in opportunities_by_key or row.fixture_id not in fixtures
            or row.model_version not in plan.model_versions or row.partition != plan.partition
            or row.policy_version != plan.policy_version
            or utc(row.decided_at) != plan.decision_at(fixtures[row.fixture_id])
            or utc(row.recorded_at) >= utc(fixtures[row.fixture_id].kickoff_at)):
            raise ValueError("Duplicate, late or out-of-scope opportunity")
        opportunities_by_key[key] = row
    expected = {(f, v, m) for f in plan.fixture_ids for v in plan.model_versions for m in MARKETS}
    if set(opportunities_by_key) != expected:
        raise ValueError("Full predeclared model/market opportunity denominator is required")
    for row in publications:
        key = (row.fixture_id, row.model_version, row.market)
        opportunity = opportunities_by_key.get(key)
        fixture = fixtures.get(row.fixture_id)
        if (row.id in publications_by_id or opportunity is None or opportunity.publication_id != row.id
            or row.partition != plan.partition or row.policy_version != plan.policy_version
            or row.currency != plan.currency or row.decided_at != opportunity.decided_at
            or utc(row.published_at) >= utc(fixture.kickoff_at) or key[:2] not in forecasts):
            raise ValueError("Publication lacks its immutable opportunity, forecast, scope or clock")
        if row.stake_minor is not None and (utc(row.stake_placed_at) >= utc(fixture.kickoff_at)
                                            or utc(row.stake_available_at) > utc(plan.evaluation_as_of)):
            raise ValueError("Exposure not actually placed prematch or unavailable at evaluation clock")
        publications_by_id[row.id] = row
    qualified = [o.publication_id for o in opportunities if o.status == "qualified"]
    if len(qualified) != len(set(qualified)) or set(qualified) != set(publications_by_id):
        raise ValueError("Qualified opportunities and immutable publications do not reconcile")
    known = [s for s in settlements if s.usable_at <= utc(plan.evaluation_as_of)]
    if len({s.id for s in known}) != len(known):
        raise ValueError("Duplicate settlement revision ID")
    chains = {}
    for row in sorted(known, key=lambda s: (s.publication_id, s.revision)):
        publication = publications_by_id.get(row.publication_id)
        previous = chains.get(row.publication_id)
        if (publication is None or row.observed_at < fixtures[publication.fixture_id].kickoff_at
            or (previous is None and row.revision != 1)
            or (previous is not None and (row.revision != previous.revision + 1
                                         or row.supersedes_id != previous.id or row.usable_at < previous.usable_at))):
            raise ValueError("Broken settlement ownership or append-only correction chain")
        label = fixtures[publication.fixture_id].result_as_of(row.observed_at)
        if label is None or label.number != row.result_revision:
            raise ValueError("Settlement uses unavailable or superseded result evidence")
        chains[row.publication_id] = row
    return opportunities_by_key, publications_by_id, chains, tuple(known)


def _selected(plan, fixtures, opportunities, publications, settlements):
    result = {}
    for version in plan.model_versions:
        result[version] = {}
        for market in MARKETS:
            candidates = [o for o in opportunities.values() if o.model_version == version and o.market == market]
            selected = [publications[o.publication_id] for o in candidates if o.publication_id is not None]
            selected.sort(key=lambda p: (utc(fixtures[p.fixture_id].kickoff_at), p.fixture_id, p.id))
            counts = {name: 0 for name in ("won", "lost", "void", "disputed", "pending", "unrecorded_money")}
            totals, keys, money_rows, manifest = [], [], [], []
            for publication in selected:
                fixture = fixtures[publication.fixture_id]
                label = fixture.result_as_of(plan.evaluation_as_of)
                settlement = settlements.get(publication.id)
                if label is None or settlement is None or settlement.result_revision != label.number:
                    counts["pending"] += 1
                    continue
                expected = ("void" if label.status == "void" else "disputed" if label.status == "disputed"
                            else "won" if outcomes(label.home, label.away)[market] else "lost")
                if settlement.outcome != expected:
                    raise ValueError("Settlement outcome disagrees with its as-of canonical result revision")
                counts[expected] += 1
                manifest.append((publication.id, label, settlement))
                if (expected == "disputed" or publication.stake_minor is None
                    or settlement.returned_minor is None or settlement.fee_minor is None
                    or not settlement.ledger_verified):
                    counts["unrecorded_money"] += 1
                    continue
                stake = publication.stake_minor
                net = settlement.returned_minor - stake - settlement.fee_minor
                totals.append((stake, net))
                keys.append(fixture.round_key)
                money_rows.append((net, stake))
            complete = counts["pending"] == counts["disputed"] == counts["unrecorded_money"] == 0
            stake = sum(s for s, _ in totals)
            net = sum(n for _, n in totals)
            ci = {"lower": None, "upper": None, "reason": "incomplete_recorded_settlement_cohort"}
            if complete and stake:
                if max(stake, sum(abs(n) for _, n in totals)) > 2 ** 53 - 1:
                    raise ValueError("Recorded-money cohort exceeds exact bootstrap conversion bound")
                samples = bootstrap(keys, money_rows, plan)
                ci = interval(None if samples is None else samples[:, 0] / samples[:, 1], plan.confidence_level)
            elif complete:
                ci["reason"] = "no_recorded_stakes"
            # Curve is cumulative settled net by whole virtual round, not a bankroll
            # strategy, and is withheld when the financial cohort is incomplete.
            cumulative, peak, drawdown = 0, 0, 0
            round_nets = {}
            for key, (_, amount) in zip(keys, totals):
                round_nets[key] = round_nets.get(key, 0) + amount
            round_first = {}
            for p in selected:
                f = fixtures[p.fixture_id]
                round_first[f.round_key] = min(round_first.get(f.round_key, utc(f.kickoff_at)), utc(f.kickoff_at))
            round_order = sorted(round_nets, key=lambda k: round_first[k])
            for key in round_order:
                cumulative += round_nets[key]
                peak = max(peak, cumulative)
                drawdown = max(drawdown, peak - cumulative)
            gross_positive = sum(max(n, 0) for _, n in totals)
            gross_negative = sum(max(-n, 0) for _, n in totals)
            result[version][market] = {
                "population": "recorded_published_selection_cohort", "currency": plan.currency,
                "opportunity_count": len(candidates), "selected_count": len(selected),
                "selection_coverage": len(selected) / len(candidates),
                "opportunity_states": {status: sum(o.status == status for o in candidates) for status in (
                    "qualified", "no_selection", "insufficient_evidence", "source_unavailable")},
                "counts": counts, "complete_money_cohort": complete,
                "total_stake_minor": stake if complete else None, "net_minor": net if complete else None,
                "roi": _money_ratio(net, stake) if complete else None, "roi_ci": ci,
                "roi_ci_scope": "conditional_on_recorded_selected_rounds",
                "profit_factor": _money_ratio(gross_positive, gross_negative) if complete else None,
                "win_rate": _money_ratio(counts["won"], counts["won"] + counts["lost"]),
                "max_cumulative_net_drawdown_minor": drawdown if complete else None,
                "drawdown_clock": "fixture_round_kickoff; reconstructed_asof_settlements_not_cashflow",
                "round_clusters": len(set(keys)), "fixture_count": len({p.fixture_id for p in selected}),
                "odds_decimal": [p.odds_decimal for p in selected],
                "settlement_manifest_sha256": digest(manifest),
                "reason": None if complete and stake else ci["reason"],
            }
    return result


def compare(dataset, plan, forecasts, opportunities, publications, settlements, created_at):
    dataset.manifest.validate()
    if dataset.manifest.partition != plan.partition or utc(created_at) < utc(plan.evaluation_as_of):
        raise ValueError("Mixed partition or report created before its evaluation clock")
    owned = {f.id: f for f in dataset.fixtures}
    if not set(plan.fixture_ids) <= owned.keys() or len(plan.fixture_ids) > 10000 or len(plan.versions) > 8:
        raise ValueError("Missing declared fixture or research comparison size bound exceeded")
    fixtures = {identity: owned[identity] for identity in plan.fixture_ids}
    if any(f.segment != (plan.platform, plan.league) or f.partition != plan.partition
           or not utc(plan.window_start) <= utc(f.kickoff_at) < utc(plan.window_end) for f in fixtures.values()):
        raise ValueError("Fixture outside declared immutable scope/window")
    indexed = _forecast_index(dataset, plan, forecasts, fixtures)
    ops, pubs, chains, known = _selection_index(plan, fixtures, indexed, opportunities, publications, settlements)
    skill = _skill(dataset, plan, fixtures, indexed)
    return {"schema_version": "simora.comparison-report.v1", "implementation_version": __version__,
            "code_sha256": digest([(p.name, sha256(p.read_bytes()).hexdigest())
                                    for p in sorted(Path(__file__).parent.glob("*.py"))]),
            "dependencies": {name: dependency_version(name) for name in ("numpy", "scipy")},
            "created_at": utc(created_at).isoformat(), "evaluation_as_of": utc(plan.evaluation_as_of).isoformat(),
            "partition": plan.partition, "platform": plan.platform, "league": plan.league,
            "policy_version": plan.policy_version, "experiment_id": plan.experiment_id,
            "model_versions": list(plan.model_versions), "baseline_versions": list(plan.baseline_versions),
            "model_version_set_sha256": digest(plan.versions), "fixture_universe_sha256": digest(plan.fixture_ids),
            "plan_sha256": digest(plan), "source_manifest_sha256": dataset.manifest.source_manifest_sha256,
            "input_manifest_sha256": digest({"forecasts": forecasts, "opportunities": opportunities,
                                               "publications": publications, "settlements": known}),
            "supersedes_report_sha256": plan.supersedes_report_sha256,
            "window": [utc(plan.window_start).isoformat(), utc(plan.window_end).isoformat()],
            "bootstrap": {"method": "whole_virtual_round_percentile", "paired_skill_draws": True,
                          "seed": plan.seed, "resamples": plan.resamples, "level": plan.confidence_level,
                          "minimum_clusters": plan.minimum_clusters, "quantile_method": "linear",
                          "multiple_testing": "not_adjusted_no_automatic_champion"},
            "skill": skill, "selected": _selected(plan, fixtures, ops, pubs, chains),
            "interval_coverage": None, "coverage_reason": "latent event probabilities are not observed",
            "accounting": "integer_minor_units; ratios Decimal precision 28 ROUND_HALF_EVEN",
            "cross_market_totals": None, "exposure_warning": "shared fixtures/rounds are correlated; no strategy P&L",
            "release_eligible": False, "status": "research_only", "auto_trade_execution": False}
