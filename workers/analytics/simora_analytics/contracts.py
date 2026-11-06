"""Immutable trusted-export contracts for V4 phase 6B, not a source/auth boundary."""

from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation
import math
import re

from simora_models.data import utc
from simora_models.distribution import MARKETS, PoissonScore
from simora_models.ensemble import MixtureScore


def sha(value):
    if not isinstance(value, str) or len(value) != 64 or any(c not in "0123456789abcdef" for c in value):
        raise ValueError("SHA-256 required")


def identities(values):
    return (isinstance(values, tuple) and bool(values) and len(set(values)) == len(values)
            and all(isinstance(x, str) and x for x in values))


@dataclass(frozen=True)
class ComparisonPlan:
    experiment_id: str
    policy_version: str
    platform: str
    league: str
    partition: str
    fixture_ids: tuple[str, ...]
    model_versions: tuple[str, ...]
    baseline_versions: tuple[str, ...]
    declared_at: datetime
    window_start: datetime
    window_end: datetime
    evaluation_as_of: datetime
    feature_buffer_seconds: int
    decision_buffer_seconds: int
    seed: int
    resamples: int
    confidence_level: float
    minimum_clusters: int
    currency: str
    supersedes_report_sha256: str | None = None

    def __post_init__(self):
        if (any(not isinstance(x, str) or not x for x in (self.experiment_id, self.policy_version,
                                                        self.platform, self.league))
            or not identities(self.fixture_ids) or not identities(self.model_versions)
            or not identities(self.baseline_versions)
            or set(self.model_versions) & set(self.baseline_versions)
            or self.partition not in {"synthetic", "authorized_live", "approved_backfill"}
            or not utc(self.declared_at) < utc(self.window_start) < utc(self.window_end) <= utc(self.evaluation_as_of)
            or any(type(x) is not int for x in (self.feature_buffer_seconds, self.decision_buffer_seconds,
                                              self.seed, self.resamples, self.minimum_clusters))
            or not self.feature_buffer_seconds >= self.decision_buffer_seconds > 0
            or not 200 <= self.resamples <= 10000 or not 2 <= self.minimum_clusters <= 10000
            or not math.isfinite(self.confidence_level) or not 0.5 < self.confidence_level < 1
            or len(self.currency) != 3 or not self.currency.isascii() or not self.currency.isupper()
            or not self.currency.isalpha()):
            raise ValueError("Invalid predeclared comparison scope, cohort, clock or bootstrap plan")
        if self.supersedes_report_sha256 is not None:
            sha(self.supersedes_report_sha256)

    @property
    def versions(self):
        return self.model_versions + self.baseline_versions

    def feature_at(self, fixture):
        return utc(fixture.kickoff_at) - timedelta(seconds=self.feature_buffer_seconds)

    def decision_at(self, fixture):
        return utc(fixture.kickoff_at) - timedelta(seconds=self.decision_buffer_seconds)


@dataclass(frozen=True)
class Forecast:
    fixture_id: str
    model_version: str
    partition: str
    score: PoissonScore | MixtureScore
    fitted_through: datetime
    frozen_at: datetime
    available_at: datetime
    artifact_sha256: str
    lineage_sha256: str
    fold_id: str
    fitted_fixture_ids: tuple[str, ...]

    def __post_init__(self):
        if (not self.fixture_id or not self.model_version or not self.fold_id or not identities(self.fitted_fixture_ids)
            or not isinstance(self.score, (PoissonScore, MixtureScore))
            or not utc(self.fitted_through) <= utc(self.frozen_at) <= utc(self.score.as_of) <= utc(self.available_at)):
            raise ValueError("Forecast must have immutable forward-fitted version/fold lineage")
        sha(self.artifact_sha256)
        sha(self.lineage_sha256)


@dataclass(frozen=True)
class Opportunity:
    fixture_id: str
    model_version: str
    market: str
    partition: str
    policy_version: str
    decided_at: datetime
    recorded_at: datetime
    status: str
    publication_id: str | None

    def __post_init__(self):
        if (self.market not in MARKETS or self.status not in {
                "qualified", "no_selection", "insufficient_evidence", "source_unavailable"}
            or (self.status == "qualified") != (isinstance(self.publication_id, str) and bool(self.publication_id))
            or (self.status != "qualified" and self.publication_id is not None)
            or utc(self.recorded_at) < utc(self.decided_at)):
            raise ValueError("Invalid immutable opportunity/publication decision")


@dataclass(frozen=True)
class Publication:
    id: str
    fixture_id: str
    model_version: str
    market: str
    partition: str
    policy_version: str
    decided_at: datetime
    published_at: datetime
    quote_id: str
    quote_observed_at: datetime
    quote_available_at: datetime
    quote_ingested_at: datetime
    quote_expires_at: datetime
    odds_decimal: str
    mapping_verified: bool
    rights_verified: bool
    stake_minor: int | None
    stake_reference: str | None
    currency: str
    manifest_sha256: str
    stake_placed_at: datetime | None = None
    stake_available_at: datetime | None = None

    def __post_init__(self):
        sha(self.manifest_sha256)
        try:
            odds = Decimal(self.odds_decimal) if isinstance(self.odds_decimal, str) else Decimal("NaN")
        except InvalidOperation:
            odds = Decimal("NaN")
        if (not self.id or not self.quote_id or self.market not in MARKETS or not odds.is_finite() or odds <= 1
            or re.fullmatch(r"[0-9]{1,12}(?:\.[0-9]{1,12})?", self.odds_decimal) is None
            or self.mapping_verified is not True or self.rights_verified is not True
            or not utc(self.quote_observed_at) <= utc(self.quote_available_at) <= utc(self.decided_at)
            or not utc(self.quote_observed_at) <= utc(self.quote_ingested_at) <= utc(self.decided_at)
            or not utc(self.decided_at) <= utc(self.published_at) < utc(self.quote_expires_at)
            or (self.stake_minor is not None and (type(self.stake_minor) is not int or self.stake_minor <= 0))
            or (self.stake_minor is None) != (self.stake_reference is None)
            or (self.stake_reference is not None and not self.stake_reference)):
            raise ValueError("Unverified publication, historical quote, or recorded stake")
        if self.stake_minor is not None:
            if (self.stake_placed_at is None or self.stake_available_at is None
                or not utc(self.published_at) <= utc(self.stake_placed_at) <= utc(self.stake_available_at)):
                raise ValueError("Recorded exposure requires actual placement/availability clocks")
        elif self.stake_placed_at is not None or self.stake_available_at is not None:
            raise ValueError("Unknown exposure must not imply a placement clock")


@dataclass(frozen=True)
class Settlement:
    id: str
    publication_id: str
    revision: int
    supersedes_id: str | None
    result_revision: int
    outcome: str
    observed_at: datetime
    available_at: datetime
    ingested_at: datetime
    returned_minor: int | None
    fee_minor: int | None
    contract_sha256: str
    ledger_verified: bool

    def __post_init__(self):
        sha(self.contract_sha256)
        if (not self.id or not self.publication_id or type(self.revision) is not int or self.revision < 1
            or type(self.result_revision) is not int or self.result_revision < 1
            or self.outcome not in {"won", "lost", "void", "disputed"}
            or (self.revision == 1) != (self.supersedes_id is None)
            or any(x is not None and (type(x) is not int or x < 0) for x in (self.returned_minor, self.fee_minor))
            or utc(self.observed_at) > utc(self.available_at) or utc(self.observed_at) > utc(self.ingested_at)
            or type(self.ledger_verified) is not bool):
            raise ValueError("Invalid append-only settlement revision or recorded money")

    @property
    def usable_at(self):
        return max(utc(self.available_at), utc(self.ingested_at))
