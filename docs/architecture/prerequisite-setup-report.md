# Prerequisite setup report

Date: 2026-11-06. Scope: authorized local MySQL provisioning, environment configuration, existing migration verification and scheduled virtual source research. Local database setup is complete. Source procurement and production ingestion remain blocked/incomplete. This report supplements V4 and does not define another backend architecture.

## Changed paths

- `.gitignore`, `.gitattributes`, `.env.example`: ignore secrets/dependencies and preserve shell LF endings. Local `.env` and `.secrets/*` are generated, ACL protected and intentionally untracked.
- `composer.json`, `composer.lock`, `bootstrap.php`: locked dotenv dependency, PSR-4 autoloading and immutable environment loading.
- `compose.yaml`, `docker/mysql/01-app-user.sh`, `bin/setup-local.ps1`: isolated persistent MySQL 8.4, separate root/migration/runtime secrets, runtime DML privileges, loopback-only published port and repeatable startup.
- `src/Infrastructure/DatabaseConnection.php`, `bin/migrate.php`, `public/index.php`: configured PDO factory, UTC/native prepares, dedicated migration credentials and shared environment bootstrap. Existing controller/repository wiring remains unchanged.
- `bin/verify-database.php`, `tests/mysql-prerequisites.php`: actual database inventory/checksum verification and transactional constraint/privilege checks.
- `README.md`, `docs/local-development.md`, `docs/architecture/live-source-research.md`, `docs/architecture/local-database-verification.json` and phase status/audit notes: operation, evidence, provider findings and current blockers.

The working tree already contained `src/Domain/LiveSource/*`, `tests/live-source.php`, `tests/migration-checksum.php`, `docs/architecture/live-source-phase.md` and the migration runner checksum fix from preceding work; these were preserved. Their tests were included in verification.

## Database and FK report

No new migration or alternate table family was introduced. Applied existing `000_schema_ledger`, `010_catalog`, `020_identity`, `030_source_provenance`, in that sequence, to the newly created `simora_dev` database on MySQL 8.4.13. Verified 32 InnoDB tables, 39 FKs including exact ordered FK columns, eight canonical markets, UTC session configuration and successful SHA-256 ledger checksums. All physical tables and FK column references match the migration files. See the JSON report for every FK and delete rule. Legacy reference-schema tables were not imported or duplicated.

Repeated migration targets returned `already applied`. Recreated the container using the final pinned-image/healthcheck configuration, preserved the database volume, and re-ran integration and inventory verification successfully. The first connection attempt occurred during initial server startup and failed with `MySQL server has gone away`; no migration had started. A subsequent ready-server connection succeeded. Final healthchecks use TCP rather than the temporary initialization socket.

## API and security changes

No HTTP route, response field or UI design changed. The migration CLI now reads dedicated `SIMORA_MIGRATION_DB_USER/PASSWORD` instead of sharing runtime credentials; `.env.example` and setup documentation define this explicitly. Runtime database credentials have no CREATE TABLE privilege. Development runtime grants are database-wide DML and are not a production append-only privilege design. Local web and CLI use the same `.env`; production must separate migration secrets from web processes. Do not use the PHP development server as a production server.

No provider has been approved, purchased, contacted or ingested. No source grant, live odds or model result was fabricated. Live ingestion, publication and Auto Trade execution remain disabled by the existing unconnected application architecture; the environment flags do not implement missing authorization gates.

## Tests actually run

- PHP scripts: `auto-trade-risk.php`, `billing.php`, `catalog.php`, `identity.php`, `ingestion.php`, `live-source.php`, `migration-checksum.php`, `platform-details.php`, `predictions-hub.php`, `review-policy.php`: all passed.
- `php tests/mysql-prerequisites.php`: `MySQL prerequisite integration tests passed`, before and after container recreation. Includes FK failure 1452, CHECK failure 3819, duplicate key 1062, rolled-back outbox rows, DDL denial 1142 and checksum mismatch rejection. Test writes were rolled back.
- Existing migration commands: fresh application succeeded; repeated commands reported `already applied`.
- `php bin/verify-database.php`: passed against the running database; JSON evidence saved after recreation.
- `node tests/history-load-more.cjs`: `Load-more interaction tests passed`.
- HTTP smoke checks through PHP's local server: dashboard 200; legacy history 200 with `data,error,meta` and null error. Temporary smoke server stopped afterward.
- PHP syntax checks for new/modified bootstrap/connection/migration/verification files: passed.
- `composer validate --strict`: valid. `composer audit --no-interaction`: no security vulnerability advisories found at execution time. Composer install succeeded on retry after a transient Windows file lock.
- Docker Compose configuration validation, Git secret-ignore checks and environment loading/key presence checks: passed. `.env` ACL inspection showed only the current user and SYSTEM, without inherited access.

No regression failures were observed in these checks. The complete JavaScript suite, deployed legacy-schema conversion, concurrent migration/ingestion, source-specific adapters, Redis/outbox recovery, backup restoration and production security were not tested. Do not infer those capabilities from schema checks.

## Remaining work and restart/rollback

The best next source step is an exact operator-authorized feed/export request, with Sportradar UOF a documented candidate only if exact coverage and bookmaker quote identity are demonstrated. Provider licensing, retention/training/display rights, all eight market mappings and correction/void rules remain unresolved. Source scope/partition enforcement, transactional ingestion handlers, immutable payload storage and durable queue/outbox processing still require implementation and integration tests before the live-source phase can be completed.

Restart with `docker compose start mysql` and `php bin/verify-database.php`; dependency installation uses `composer install`. `docker compose stop mysql` or `docker compose down` preserves the named volume. Do not use `down --volumes` for rollback. Preserve secrets with the volume. Revert application bootstrap/configuration changes together if rolling back code; applied DDL needs reviewed forward repair or a tested restore, not deleted ledger entries. No production rollback is required because only this new local database was changed. Do not proceed automatically to model training, publication or execution phases.
