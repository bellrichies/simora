"""One joint score law; exact analytical Poisson events with bounded grid tails."""

from dataclasses import dataclass
from datetime import datetime
import math

import numpy as np
from scipy.stats import poisson

from .data import utc

MARKETS = ("over_15", "under_15", "over_25", "under_25", "over_35", "under_35", "btts_yes", "btts_no")
PAIRS = tuple(zip(MARKETS[::2], MARKETS[1::2]))


def outcomes(home, away):
    if any(type(x) is not int or x < 0 for x in (home, away)):
        raise ValueError("Non-negative integer scores required")
    total = home + away
    events = (total >= 2, total >= 3, total >= 4, home > 0 and away > 0)
    return {market: int(event if index % 2 == 0 else not event)
            for index, market in enumerate(MARKETS) for event in [events[index // 2]]}


def validate_markets(values):
    if set(values) != set(MARKETS) or any(not math.isfinite(p) or not 0 <= p <= 1 for p in values.values()):
        raise ValueError("Invalid market probabilities")
    if any(abs(values[a] + values[b] - 1) > 1e-12 for a, b in PAIRS):
        raise ValueError("Complement mismatch")
    if not values["over_15"] + 1e-12 >= values["over_25"] >= values["over_35"] - 1e-12:
        raise ValueError("Incoherent totals")


@dataclass(frozen=True)
class PoissonScore:
    home_rate: float
    away_rate: float
    method: str
    as_of: datetime
    feature_version: str = "rolling-v1"

    def __post_init__(self):
        utc(self.as_of)
        if any(not math.isfinite(x) or x < 0 or x > 100 for x in (self.home_rate, self.away_rate)):
            raise ValueError("Goal rate outside numerical contract [0,100]")

    def markets(self):
        total = self.home_rate + self.away_rate
        events = [float(poisson.sf(k, total)) for k in (1, 2, 3)]
        events.append(float(-math.expm1(-self.home_rate) * -math.expm1(-self.away_rate)))
        values = {}
        for (positive, negative), probability in zip(PAIRS, events):
            values[positive], values[negative] = probability, 1 - probability
        validate_markets(values)
        return values

    def log_score(self, home, away):
        return float(poisson.logpmf(home, self.home_rate) + poisson.logpmf(away, self.away_rate))

    def dto(self, tolerance=1e-12):
        if not 1e-12 <= tolerance <= 1e-6:
            raise ValueError("Invalid tail tolerance")
        # The grid is conditional on its retained rectangle; the residual law is
        # the two named Poisson marginals, not an undifferentiated goal bucket.
        h = max(4, int(poisson.isf(tolerance / 4, self.home_rate)))
        a = max(4, int(poisson.isf(tolerance / 4, self.away_rate)))
        grid = np.outer(poisson.pmf(np.arange(h + 1), self.home_rate),
                        poisson.pmf(np.arange(a + 1), self.away_rate))
        retained = float(grid.sum())
        omitted_bound = float(poisson.sf(h, self.home_rate) + poisson.sf(a, self.away_rate))
        if retained <= 0 or omitted_bound > tolerance:
            raise ValueError("Unbounded score tail")
        return {"goal_grid": (grid / retained).tolist(), "tail_policy": {
            "type": "analytic_independent_poisson", "home_rate": self.home_rate,
            "away_rate": self.away_rate, "grid_conditioning": "retained_rectangle",
            "omitted_mass_bound": omitted_bound,
        }, "numerical_error_bound": omitted_bound + 1e-14,
            "method": self.method, "feature_version": self.feature_version,
            "as_of": utc(self.as_of).isoformat(), "markets": self.markets()}
