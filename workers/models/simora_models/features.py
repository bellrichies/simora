"""No target columns or fitted whole-dataset transforms enter features."""

from datetime import timedelta
import math

import numpy as np

from .data import Dataset, Fixture, utc
from .distribution import outcomes

FEATURE_NAMES = ("home_mean", "away_mean", "over_15_frequency", "over_25_frequency",
                 "over_35_frequency", "btts_frequency", "log_history_count",
                 "history_missing", "round_number", "round_missing", "source_quality",
                 "quality_missing", "league_code")
FEATURE_VERSION = "rolling-v1"


def decision_time(fixture: Fixture, buffer_seconds: int):
    if type(buffer_seconds) is not int or buffer_seconds < 1:
        raise ValueError("Positive explicit pre-kickoff buffer required")
    decision = utc(fixture.kickoff_at) - timedelta(seconds=buffer_seconds)
    if utc(fixture.context_available_at) > decision:
        raise ValueError("Fixture context was not available before decision")
    return decision


def raw_features(dataset: Dataset, fixture: Fixture, buffer_seconds: int, league_codes: dict, window: int = 200):
    if type(window) is not int or window < 1:
        raise ValueError("Invalid rolling history window")
    as_of = decision_time(fixture, buffer_seconds)
    history = [(f, r) for f, r in dataset.labels(as_of) if f.segment == fixture.segment
               and f.id != fixture.id and f.round_key != fixture.round_key][-window:]
    n = len(history)
    if n:
        events = [outcomes(r.home, r.away) for _, r in history]
        values = [sum(r.home for _, r in history) / n, sum(r.away for _, r in history) / n]
        values.extend(sum(event[key] for event in events) / n
                      for key in ("over_15", "over_25", "over_35", "btts_yes"))
    else:
        values = [math.nan] * 6
    values.extend([math.log1p(n), float(n == 0), fixture.round_number if fixture.round_number is not None else math.nan,
                   float(fixture.round_number is None), fixture.source_quality if fixture.source_quality is not None else math.nan,
                   float(fixture.source_quality is None), league_codes.get(fixture.segment, -1)])
    return np.asarray(values, dtype=float)


class FoldPreprocessor:
    """Fit imputation and categorical identity on training fixtures only."""

    def fit(self, dataset, fixtures, buffer_seconds):
        self.league_codes = {key: index for index, key in enumerate(sorted({f.segment for f in fixtures}))}
        matrix = np.vstack([raw_features(dataset, f, buffer_seconds, self.league_codes) for f in fixtures])
        self.medians = np.asarray([float(np.median(col[np.isfinite(col)])) if np.any(np.isfinite(col)) else 0.0
                                   for col in matrix.T])
        return self

    def transform(self, dataset, fixtures, buffer_seconds):
        if any(f.segment not in self.league_codes for f in fixtures):
            raise ValueError("Unsupported model segment")
        matrix = np.vstack([raw_features(dataset, f, buffer_seconds, self.league_codes) for f in fixtures])
        return np.where(np.isfinite(matrix), matrix, self.medians)
