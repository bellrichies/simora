"""Offline research entry point; never called from HTTP or an external queue."""

import argparse
from datetime import datetime
import json
from pathlib import Path
import sys

from .artifacts import code_digest
from .data import Dataset, Fixture, Manifest, Revision
from .evaluation import WalkForwardSpec, walk_forward


def read_dataset(payload):
    if payload.get("schema_version") != "simora.research-dataset.v1":
        raise ValueError("Unsupported dataset schema")
    fixtures = []
    for item in payload["fixtures"]:
        item = dict(item)
        for key in ("kickoff_at", "context_available_at"):
            item[key] = datetime.fromisoformat(item[key])
        revisions = []
        for revision in item.pop("revisions"):
            revision = dict(revision)
            for key in ("observed_at", "available_at", "ingested_at"):
                revision[key] = datetime.fromisoformat(revision[key])
            revisions.append(Revision(**revision))
        fixtures.append(Fixture(**item, revisions=tuple(revisions)))
    return Dataset(Manifest(**payload["manifest"]), tuple(fixtures))


def main():
    parser = argparse.ArgumentParser(description="Internal offline SPE/MLPE research evaluation")
    parser.add_argument("--input", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        if args.input.stat().st_size > 20 * 1024 * 1024:
            raise ValueError("Input exceeds offline research size limit")
        payload = json.loads(args.input.read_bytes())
        data = read_dataset(payload)
        quotes = payload.get("quotes", {})
        for quote in quotes.values():
            for key in ("observed_at", "available_at", "ingested_at", "expires_at"):
                quote[key] = datetime.fromisoformat(quote[key])
        result = walk_forward(data, WalkForwardSpec(**payload["fold_spec"]),
                              datetime.fromisoformat(payload["evaluation_as_of"]), quotes)
        result["code_sha256"] = code_digest()
        result["source_manifest_sha256"] = data.manifest.source_manifest_sha256
        with args.output.open("x", encoding="utf-8") as handle:
            json.dump(result, handle, sort_keys=True, indent=2, allow_nan=False)
            handle.write("\n")
        return 0
    except (ValueError, TypeError, KeyError, OSError) as exception:
        print(f"Research evaluation rejected: {exception}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
