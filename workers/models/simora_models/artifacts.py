"""Immutable JSON numerical artifacts. No pickle, executable imports or promotion."""

from hashlib import sha256
from importlib.metadata import version
import json
from pathlib import Path

import lightgbm as lgb
import numpy as np

from . import __version__
from .data import digest
from .engines import ShrunkPoisson, StructuredLightGBM
from .features import FEATURE_NAMES, FEATURE_VERSION, FoldPreprocessor

SCHEMA = "simora.model.v1"


def code_digest():
    root = Path(__file__).parent
    return digest([(file.name, sha256(file.read_bytes()).hexdigest()) for file in sorted(root.glob("*.py"))])


def write_artifact(path, model):
    payload = {"schema_version": SCHEMA, "implementation_version": __version__, "code_sha256": code_digest(), "engine": model.code,
               "dependencies": {name: version(name) for name in ("lightgbm", "numpy", "scipy")},
               "payload": model.artifact(), "release_eligible": False}
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()
    # Exclusive creation refuses overwrite of an existing model publication.
    with Path(path).open("xb") as handle:
        handle.write(encoded)
    return sha256(encoded).hexdigest()


def _load_payload(path, expected_sha256, engine):
    file = Path(path)
    if file.stat().st_size > 16 * 1024 * 1024:
        raise ValueError("Artifact exceeds size contract")
    encoded = file.read_bytes()
    if len(expected_sha256) != 64 or sha256(encoded).hexdigest() != expected_sha256:
        raise ValueError("Artifact SHA mismatch")
    payload = json.loads(encoded)
    if (payload["schema_version"] != SCHEMA or payload["engine"] != engine
        or payload["implementation_version"] != __version__
        or payload["code_sha256"] != code_digest() or payload["release_eligible"] is not False
        or payload["dependencies"] != {name: version(name) for name in ("lightgbm", "numpy", "scipy")}):
        raise ValueError("Incompatible artifact schema, code or numerical dependencies")
    return payload["payload"]


def load_mlpe(path, expected_sha256):
    data = _load_payload(path, expected_sha256, "mlpe")
    if data["feature_names"] != list(FEATURE_NAMES) or data["feature_version"] != FEATURE_VERSION:
        raise ValueError("Feature schema mismatch")
    model = StructuredLightGBM(seed=data["seed"], rounds=data["rounds"], min_data_in_leaf=data["parameters"]["min_data_in_leaf"])
    from datetime import datetime
    from .data import utc

    model.cutoff = utc(datetime.fromisoformat(data["training_cutoff"]))
    model.buffer_seconds = data["buffer_seconds"]
    model.params, model.training_digest = data["parameters"], data["training_digest"]
    model.source_manifest_sha256 = data["source_manifest_sha256"]
    model.partition = data["partition"]
    model.preprocessor = FoldPreprocessor()
    model.preprocessor.medians = np.asarray(data["imputation_medians"], dtype=float)
    model.preprocessor.league_codes = {(platform, league): code for platform, league, code in data["league_codes"]}
    if model.preprocessor.medians.shape != (len(FEATURE_NAMES),) or not np.all(np.isfinite(model.preprocessor.medians)):
        raise ValueError("Invalid preprocessing artifact")
    if len(data["models"]) != 2:
        raise ValueError("Exactly two structured goal heads required")
    model.heads = [lgb.Booster(model_str=text) for text in data["models"]]
    if any(head.feature_name() != list(FEATURE_NAMES) for head in model.heads):
        raise ValueError("LightGBM feature mismatch")
    return model


def load_spe(path, expected_sha256):
    from datetime import datetime
    from .data import utc
    from .distribution import PoissonScore

    data = _load_payload(path, expected_sha256, "spe")
    if data["type"] != "shrunk_poisson" or data["feature_version"] != "shrunk-league-goals-v1":
        raise ValueError("SPE feature schema mismatch")
    model = ShrunkPoisson(**data["parameters"])
    model.cutoff = utc(datetime.fromisoformat(data["training_cutoff"]))
    model.training_digest, model.source_manifest_sha256 = data["training_digest"], data["source_manifest_sha256"]
    model.partition = data["partition"]
    model.rates = {}
    for platform, league, home, away in data["rates"]:
        if (platform, league) in model.rates:
            raise ValueError("Duplicate SPE segment")
        PoissonScore(home, away, "spe", model.cutoff)
        model.rates[(platform, league)] = (home, away)
    if not model.rates:
        raise ValueError("Missing supported SPE segments")
    return model


def load_beve(path, expected_sha256):
    """Reload a research posterior; approval/coverage flags cannot be promoted."""
    from datetime import datetime
    from .ensemble import BayesianEnsemble, EnsemblePlan, FrozenComponent, WeightPosterior

    data = _load_payload(path, expected_sha256, "beve")
    if (data["type"] != "exact_beta_mixture_v1" or data["operator_label"] != "regularized_ensemble"
        or data["coverage_validated"] is not False or data["release_eligible"] is not False):
        raise ValueError("Unsupported or falsely promoted ensemble artifact")
    plan = dict(data["plan"])
    components = []
    for item in plan["components"]:
        item = dict(item)
        for key in ("fit_cutoff", "frozen_at"):
            item[key] = datetime.fromisoformat(item[key])
        item["fitted_fixture_ids"] = tuple(item["fitted_fixture_ids"])
        item["fitted_round_keys"] = tuple(tuple(key) for key in item["fitted_round_keys"])
        components.append(FrozenComponent(**item))
    plan["components"] = tuple(components)
    for key in ("declared_at", "fit_cutoff"):
        plan[key] = datetime.fromisoformat(plan[key])
    for key in ("fit_ids", "holdout_ids", "prior"):
        plan[key] = tuple(plan[key])
    posterior = dict(data["posterior"])
    posterior["prior"], posterior["masses"] = tuple(posterior["prior"]), tuple(posterior["masses"])
    model = BayesianEnsemble(EnsemblePlan(**plan), WeightPosterior(**posterior), data["training_digest"])
    if model.posterior.count != len(model.plan.fit_ids) or model.posterior.prior != model.plan.prior:
        raise ValueError("Posterior does not match fitted plan")
    from .ensemble import _hash

    _hash(model.training_digest)
    return model
