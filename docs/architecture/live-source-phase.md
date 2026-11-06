# Live source phase status

Status: **incomplete; live collection and persistence disabled**. The isolated domain rules calculate eight market outcomes for an accepted score, void, or dispute, and check quote observation, availability, ingestion, expiry and publication cutoff against a supplied kickoff and decision time. These rules are not connected to an API, repository, provider or database. A correction can be calculated independently but is not persisted or audited. The caller must supply a trusted fixed kickoff and verify source authorization and mapping; this code cannot establish either.

The live-source slice adds no migration, foreign key or API change. A subsequent prerequisite setup applied the existing 000/010/020/030 migrations to isolated local MySQL 8.4.13 and verified its ledger, 32 tables, eight markets and 39 FKs. No production database is configured. Phase 3 still lacks authorized adapters, transactional source/scope and grant validation, partition isolation, idempotent ingestion handlers and outbox delivery. Provider-specific void and correction contracts also remain unverified. These remain release blockers.

Next, complete and validate the remaining phase 3 prerequisites and obtain authorized source and settlement contracts. Then implement immutable odds and result revisions, a same-result current pointer, auditable correction/void handling and eight outcome rows with transactional concurrency and integration tests. Do not activate live publication until these gates are met. Source procurement findings are in `live-source-research.md`; local database operation is documented in `../local-development.md`.

Rollback: remove the two new domain classes and their test. No restart, database rollback or data migration is required because nothing is wired into runtime.

## Prerequisite audit update

The migration runner previously reused the target checksum variable when checking dependencies, so it could record a dependency checksum for the target. The runner now retains the target checksum; `tests/migration-checksum.php` exercises this using a PDO test double. This is not a MySQL integration test. Existing ledgers must be inspected before migration: if a previously applied migration has a mismatch, stop and reconcile the actual schema, migration file and audit history; do not rewrite the checksum automatically.

The initial audit found no configured database and an unavailable Docker daemon. The subsequent authorized setup started Docker, created protected local credentials, provisioned MySQL 8.4.13, applied the existing migrations, and verified checksum replay and database constraints. Written provider rights, approved access method, scope and settlement/void/correction rules remain outstanding. The technical ingestion and queue prerequisites listed in `ingestion-phase.md` remain unimplemented; database verification does not complete them.
