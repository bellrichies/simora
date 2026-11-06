# Local MySQL development environment

This configuration is for isolated local development, not production deployment. The authoritative backend specification remains `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`. The PHP server-rendered preview and legacy history API continue to use their existing fixture repository. Environment flags do not activate unfinished ingestion, identity, publication or Auto Trade features.

## Install and start

Requirements: PHP 8.1+ with PDO MySQL, Composer, PowerShell, and Docker Desktop with its Linux engine running.

```powershell
composer install --no-interaction --prefer-dist
powershell -NoProfile -ExecutionPolicy Bypass -File bin/setup-local.ps1
php bin/migrate.php 010_catalog
php bin/migrate.php 020_identity
php bin/migrate.php 030_source_provenance
php bin/verify-database.php
php tests/mysql-prerequisites.php
php -S 127.0.0.1:8080 -t public public/index.php
```

Catalog must be explicitly installed before source provenance. The migration runner refuses pre-existing legacy or partially created catalog tables; it must not be used to force a deployed legacy database into this schema. Migrations are checksum checked and MySQL DDL implicitly commits. Stop on a failed ledger row or mismatch and reconcile schema and evidence before retrying. Use the actual build SHA and operator actor for any non-local migration.

The setup script generates `.env` and three independent 256-bit database secrets only when no configuration exists. It preserves secrets on reruns and fails rather than rotating existing passwords. It applies Windows ACLs for the current user and SYSTEM. Keep these local files outside Git and do not share their contents. Composer installs pinned dependencies from `composer.lock`. The public front controller and migration CLI load `.env` through `vlucas/phpdotenv`; exported process values take precedence.

MySQL 8.4 listens only on `127.0.0.1:33080`; its persistent data is in the `simora-local_mysql_data` Docker volume. Root is restricted to localhost within the container. The migration account owns DDL rights only on `simora_dev`; the runtime account has SELECT/INSERT/UPDATE/DELETE on that development database and no DDL or global administration rights. Production requires tighter per-table append-only grants and audited migration credential provisioning. The local `.env` includes both runtime and migration credentials for developer CLI use; production web processes must never receive migration credentials. No provider credentials are invented or provisioned.

UTC is configured on the MySQL server and every PDO connection. Native prepared statements and exception error handling are enabled. `APP_KEY` is randomly generated for future application use; this change does not claim to implement encryption, sessions, CSRF or entitlements using it. Serve only `public/` as the web root.

## Verification and lifecycle

`bin/verify-database.php` reads the actual server version, UTC setting, ledger checksums, eight canonical markets, table engines and exact table/FK inventories. `tests/mysql-prerequisites.php` uses rollback-only test rows to exercise FK rejection, invalid grant and payload times, duplicate batch keys and joint batch/outbox rollback, checks runtime DDL denial, and proves checksum corruption is rejected. It permits only `simora_dev` or `simora_test` as target names. These tests do not certify ingestion handler idempotency or queue delivery.

The image is pinned by digest in `compose.yaml`; update that pin deliberately after validating a new MySQL patch. The actual local verification report is in `docs/architecture/local-database-verification.json`.

```powershell
docker compose stop mysql
docker compose start mysql
php bin/verify-database.php
```

Stopping or running `docker compose down` preserves the database volume. Do not add `--volumes` unless deliberately destroying this isolated local database. Initialization scripts run only for an empty volume. Changing secret files does not rotate credentials in an existing database; use a deliberate ALTER USER/secret update procedure. Back up both data and local secrets securely before any destructive reset. Re-run ledger/schema verification after restart.

Rollback application bootstrapping by reverting the front-controller and CLI bootstrap changes together with their supporting files. Preserve the database volume and secrets. Do not remove a migration ledger row or downgrade checksums to undo DDL; use a reviewed forward repair or tested restore. No production schema or external service is changed by this local setup.
