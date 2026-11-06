"""Deterministic whole-round percentile bootstrap; paired draws for skill deltas."""

import numpy as np


def cluster_totals(keys, values):
    groups = sorted(set(keys))
    totals = np.zeros((len(groups), values.shape[1]), dtype=float)
    counts = np.zeros(len(groups), dtype=float)
    lookup = {key: index for index, key in enumerate(groups)}
    for key, row in zip(keys, values):
        index = lookup[key]
        totals[index] += row
        counts[index] += 1
    return totals, counts


def bootstrap(keys, values, plan):
    values = np.asarray(values, dtype=float)
    if values.ndim != 2 or len(keys) != len(values) or not np.all(np.isfinite(values)):
        raise ValueError("Finite clustered observation matrix required")
    totals, counts = cluster_totals(keys, values)
    if len(counts) < plan.minimum_clusters:
        return None
    if len(counts) * plan.resamples > 5_000_000:
        raise ValueError("Research bootstrap memory bound exceeded")
    rng = np.random.default_rng(plan.seed)
    # Multinomial counts are equivalent to drawing whole rounds with replacement.
    draws = rng.multinomial(len(counts), np.full(len(counts), 1 / len(counts)), size=plan.resamples)
    return (draws @ totals) / (draws @ counts)[:, None]


def interval(samples, level):
    if samples is None:
        return {"lower": None, "upper": None, "reason": "insufficient_round_clusters"}
    if not np.all(np.isfinite(samples)):
        return {"lower": None, "upper": None, "reason": "undefined_bootstrap_denominator"}
    low, high = np.quantile(samples, [(1 - level) / 2, (1 + level) / 2], method="linear")
    return {"lower": float(low), "upper": float(high), "reason": None}
