"""Validated offline input and point-in-time result revision selection."""

from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from hashlib import sha256
import json
import math


def utc(value: datetime) -> datetime:
    if not isinstance(value, datetime) or value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("Timezone-aware timestamps are required")
    return value.astimezone(timezone.utc)


def digest(value) -> str:
    def encode(item):
        if isinstance(item, datetime):
            return utc(item).isoformat()
        if hasattr(item, "__dataclass_fields__"):
            return asdict(item)
        raise TypeError(type(item).__name__)

    payload = json.dumps(value, default=encode, sort_keys=True, separators=(",", ":"), allow_nan=False)
    return sha256(payload.encode()).hexdigest()


@dataclass(frozen=True)
class Manifest:
    partition: str
    validation_use: str
    purpose: str
    outcome_selected: bool
    source_manifest_sha256: str

    def validate(self):
        if type(self.outcome_selected) is not bool:
            raise ValueError("Explicit outcome-selection declaration required")
        if self.partition not in {"synthetic", "authorized_live", "approved_backfill"}:
            raise ValueError("Unknown partition")
        if self.validation_use not in {"demo", "research"} or self.purpose not in {"unit_test", "research"}:
            raise ValueError("Release training requires the absent approved model-platform boundary")
        if self.outcome_selected or (self.partition == "synthetic" and self.purpose != "unit_test"):
            raise ValueError("Synthetic data is restricted to unit tests; outcome selection is forbidden")
        if self.partition == "synthetic" and self.validation_use != "demo":
            raise ValueError("Synthetic observations must remain in demo validation scope")
        if self.partition != "synthetic" and self.validation_use != "research":
            raise ValueError("Real data requires research permission")
        if len(self.source_manifest_sha256) != 64 or any(c not in "0123456789abcdef" for c in self.source_manifest_sha256):
            raise ValueError("A source manifest digest is required")


@dataclass(frozen=True)
class Revision:
    number: int
    status: str
    home: int | None
    away: int | None
    observed_at: datetime
    available_at: datetime
    ingested_at: datetime

    def __post_init__(self):
        if type(self.number) is not int or self.number < 1:
            raise ValueError("Invalid result revision")
        if self.status not in {"accepted", "void", "disputed"}:
            raise ValueError("Invalid result status")
        if self.status == "accepted":
            if any(type(x) is not int or x < 0 or x > 1000 for x in (self.home, self.away)):
                raise ValueError("Invalid accepted score")
        elif self.home is not None or self.away is not None:
            raise ValueError("Non-accepted results must not imply scores")
        observed, available, ingested = map(utc, (self.observed_at, self.available_at, self.ingested_at))
        if available < observed or ingested < observed:
            raise ValueError("Backdated observation evidence")

    @property
    def usable_at(self):
        return max(utc(self.available_at), utc(self.ingested_at))


@dataclass(frozen=True)
class Fixture:
    id: str
    platform: str
    league: str
    season: str
    round_id: str
    kickoff_at: datetime
    context_available_at: datetime
    partition: str
    revisions: tuple[Revision, ...] = ()
    round_number: int | None = None
    source_quality: float | None = None

    def __post_init__(self):
        if not isinstance(self.revisions, tuple):
            raise ValueError("Immutable revision tuple required")
        if any(not isinstance(x, str) or not x for x in (self.id, self.platform, self.league, self.season, self.round_id)):
            raise ValueError("Scoped fixture identities are required")
        utc(self.kickoff_at)
        utc(self.context_available_at)
        if self.round_number is not None and (type(self.round_number) is not int or self.round_number < 0):
            raise ValueError("Invalid round context")
        if self.source_quality is not None and (not math.isfinite(self.source_quality) or not 0 <= self.source_quality <= 1):
            raise ValueError("Invalid source quality")
        numbers = [revision.number for revision in self.revisions]
        if len(set(numbers)) != len(numbers):
            raise ValueError("Duplicate result revision")
        ordered = sorted(self.revisions, key=lambda r: r.number)
        if any(a.usable_at > b.usable_at for a, b in zip(ordered, ordered[1:])):
            raise ValueError("Revision availability must be monotonic")
        if any(revision.usable_at < utc(self.kickoff_at) for revision in self.revisions):
            raise ValueError("Result available before kickoff")

    @property
    def segment(self):
        return (self.platform, self.league)

    @property
    def round_key(self):
        return (self.platform, self.league, self.season, self.round_id)

    def result_as_of(self, as_of: datetime):
        known = [r for r in self.revisions if r.usable_at <= utc(as_of)]
        return max(known, key=lambda r: r.number) if known else None


@dataclass(frozen=True)
class Dataset:
    manifest: Manifest
    fixtures: tuple[Fixture, ...]

    def __post_init__(self):
        self.manifest.validate()
        if not isinstance(self.fixtures, tuple):
            raise ValueError("Immutable fixture tuple required")
        if not self.fixtures or len({f.id for f in self.fixtures}) != len(self.fixtures):
            raise ValueError("Nonempty dataset with unique fixture IDs required")
        if any(f.partition != self.manifest.partition for f in self.fixtures):
            raise ValueError("Mixed data partitions")

    def labels(self, cutoff: datetime, fixtures=None):
        rows = []
        for fixture in self.fixtures if fixtures is None else fixtures:
            result = fixture.result_as_of(cutoff)
            if utc(fixture.kickoff_at) < utc(cutoff) and result is not None and result.status == "accepted":
                rows.append((fixture, result))
        return sorted(rows, key=lambda row: (utc(row[0].kickoff_at), row[0].id))

    def validate_training_rows(self, rows, cutoff):
        self.manifest.validate()
        owned = {fixture.id: fixture for fixture in self.fixtures}
        if len({fixture.id for fixture, _ in rows}) != len(rows):
            raise ValueError("Duplicate training fixture")
        for fixture, result in rows:
            if (owned.get(fixture.id) != fixture or fixture.partition != self.manifest.partition
                or result != fixture.result_as_of(cutoff) or result.status != "accepted"
                or result.usable_at > utc(cutoff) or utc(fixture.kickoff_at) >= utc(cutoff)):
                raise ValueError("Training rows violate ownership, latest revision or point-in-time cutoff")
