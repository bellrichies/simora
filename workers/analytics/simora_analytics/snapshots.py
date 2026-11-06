"""Exclusive immutable signed JSON snapshots; no DB or approval capability."""

from hashlib import sha256
import hmac
import json
import os
from pathlib import Path

SCHEMA = "simora.comparison-snapshot.v1"


def _message(key_id, digest):
    return (SCHEMA + "\n" + key_id + "\n" + digest).encode()


def write_snapshot(path, report, key_id, key):
    if (not isinstance(key, bytes) or len(key) < 32 or not isinstance(key_id, str) or not key_id
        or "\n" in key_id or report.get("schema_version") != "simora.comparison-report.v1"
        or report.get("release_eligible") is not False or report.get("status") != "research_only"):
        raise ValueError("Research-only report and explicit signing key required")
    payload = json.dumps(report, sort_keys=True, separators=(",", ":"), ensure_ascii=False, allow_nan=False)
    digest = sha256(payload.encode()).hexdigest()
    envelope = {"schema_version": SCHEMA, "key_id": key_id, "payload_json": payload,
                "payload_sha256": digest, "signature": hmac.new(key, _message(key_id, digest), "sha256").hexdigest()}
    encoded = json.dumps(envelope, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()
    with Path(path).open("xb") as handle:
        handle.write(encoded)
        handle.flush()
        os.fsync(handle.fileno())
    return digest


def read_snapshot(path, expected_sha256, keys):
    path = Path(path)
    if path.stat().st_size > 32 * 1024 * 1024:
        raise ValueError("Report snapshot exceeds size bound")
    envelope = json.loads(path.read_bytes())
    key = keys.get(envelope.get("key_id"))
    payload = envelope.get("payload_json")
    if not isinstance(payload, str) or not isinstance(key, bytes) or len(key) < 32:
        raise ValueError("Missing snapshot payload or trusted key")
    digest = sha256(payload.encode()).hexdigest()
    if (envelope.get("schema_version") != SCHEMA or digest != expected_sha256
        or digest != envelope.get("payload_sha256") or not hmac.compare_digest(
            hmac.new(key, _message(envelope["key_id"], digest), "sha256").hexdigest(), envelope.get("signature", ""))):
        raise ValueError("Snapshot hash/signature mismatch")
    report = json.loads(payload)
    if report.get("schema_version") != "simora.comparison-report.v1":
        raise ValueError("Unsupported report schema")
    return report
