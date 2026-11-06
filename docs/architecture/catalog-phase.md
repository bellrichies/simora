# Catalog implementation and reconciliation

Status: partial; do not deploy or mark the catalog phase complete until MySQL 8 fresh-install and upgrade tests pass. No application database was identified and no migration was applied. The existing fixture-backed UI and history endpoint continue using their existing six-market preview configuration; the new canonical registry and settlement rules cover all eight without manufacturing preview picks. There is no new API.

## Operation

`bin/migrate.php` accepts `000_schema_ledger`, `010_catalog`, or `020_identity` as its positional target. Supply `SIMORA_DB_DSN` (PDO MySQL DSN with selected database), `SIMORA_DB_USER`, `SIMORA_DB_PASSWORD`, `SIMORA_MIGRATION_ACTOR`, and `SIMORA_BUILD_SHA` through the environment; never commit credentials. Only MySQL 8.0.16–8.x is accepted. The runner uses a database-scoped advisory lock, SHA-256 checksums, dependency records and running/succeeded/failed ledger states. MySQL DDL may commit implicitly; a failed or interrupted migration requires manual inspection and recovery, not automatic replay. Ledger bootstrap itself is non-atomic and must be inspected if interrupted.

`010_catalog` stops before catalog DDL if any legacy, shared or catalog table already exists. It can have already created `schema_migrations`. It does not automatically reconcile an existing schema. This prevents accidental duplication or rewriting, but is not a completed upgrade path.

## Reconciliation required before an upgrade

1. Identify the actual database and inspect its ledger, `SHOW CREATE TABLE`, keys, collations, rows, grants and backups; compare with `database/schema.sql`. Determine whether there are real records and their provenance and contractual identifiers.
2. Design and test additive expand/backfill/dual-read/contract migrations for `leagues` → `virtual_leagues`, `league_weeks` → `virtual_rounds`, and legacy `predictions` → an appropriate later immutable publication path. Shared `platforms`, `teams`, `football_matches` and `markets` need reviewed in-place transformations; never create a duplicate set of canonical tables. Preserve the existing endpoint IDs and results through an adapter. The provided `LegacyCatalogAdapter` is deterministic identity translation, not a read-model or database migration adapter.
3. Record durable source/external-ID mappings and collision/provenance evidence in the source ingestion phase; the SHA-256-derived canonical ID does not itself retain the external ID. Do not turn synthetic rows into live observations or publications.
4. Rehearse fresh install, populated upgrade, interrupted DDL and restore on isolated MySQL 8; check all FKs and constraints with `SHOW CREATE TABLE`, collision and cross-league insert tests, checksums and concurrent migration locks. Validate seeded registry against `MarketCatalog`; add operational immutability controls for market rules before relying on them. Review and approve the resulting data migration before execution.

## Foreign keys in proposed 010

`virtual_leagues.platform_id → platforms.id`; `league_seasons.league_id → virtual_leagues.id`; `teams.league_id → virtual_leagues.id`; `team_aliases.(league_id,team_id) → teams.(league_id,id)`; `virtual_rounds.(league_id,season_id) → league_seasons.(league_id,id)`; `football_matches.(league_id,round_id) → virtual_rounds.(league_id,id)` and both `(league_id,home_team_id)` and `(league_id,away_team_id) → teams.(league_id,id)`; `market_rules.market_id → markets.id`. Canonical football ID columns are `VARCHAR(128) ascii_bin`; the platform slug uses `VARCHAR(32) ascii_bin`. The migration seeds three unavailable catalog platforms and eight rules. These are SQL definitions, not verified physical constraints.

## Rollback / restart

No migration was applied in this session. For a future installation, restore the tested backup or execute a separately reviewed forward repair after stopping writers; do not assume transactional DDL rollback or edit ledger checksums. Preserve legacy data and assess dependencies before removing any table. Re-run only after the ledger and actual schema are reconciled and a recovery procedure is verified. No live source or trading connector is enabled.
