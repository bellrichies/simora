# Phase 9 access and notifications

Historical initial report before V1 approval. The user has now approved the reconciled policy; current schema, API, test and release evidence is in [the approved-policy continuation report](approved-access-policy-implementation.md). Pending-policy statements below describe that earlier point in time.

Date: 2026-11-06, Africa/Lagos. **Access infrastructure implemented and MySQL-tested; commercial policy activation and production release remain gated.**

Authoritative specification: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, sections 6.6/6.8, migration DAG `080`, section 11, section 12 and Phase 9. This report is implementation evidence, not another blueprint. The V4 user instruction continues to supersede the obsolete blueprint path in AGENTS.md.

## Inspection and implementation

Inspected the local Git tree, migration ledger/schema, identity/session/CSRF and access primitives, API composition, existing billing fixtures/documentation, SSR preview contracts and existing tests. Preserved all pre-existing Phase 7/8 tracked and untracked changes. Before implementation, the read-only verifier succeeded at `2026-11-06T14:54:58Z`: MySQL 8.4.13, UTC, four checksummed migrations, 32 tables, 39 FKs and eight markets.

`docs/plans-billing.md` records confirmed names/prices: Trial NGN 2,000/day, Standard NGN 30,000/two weeks, Pro NGN 50,000/month, Max NGN 80,000/month. It explicitly leaves feature grants, quotas/reset rules, eligibility, renewal and billing policies unresolved. The fixture's 3/10 usage and illustrative feature cards are not approvals. Requested the missing approved feature/quota/reset policy from the user; no answer was available during implementation. Consequently no plan version, price, subscription, entitlement or account privilege was provisioned automatically.

Plan executed: add the phase's canonical identity-dependent migration; implement PDO-backed session/RBAC/CSRF verification, approved catalog publishing, grants/revocation and exact scoped quota consumption; add owner notifications and in-app delivery records; expose the canonical read/read-state routes; rehearse isolated MySQL installation/concurrency, apply the verified additive local migration, run regressions and document release gates. No payment or trading work was performed.

## Changed paths

Created:

- `database/migrations/080_access_notifications.sql`.
- `src/Domain/Access/AccessException.php`, `SessionCredentials.php`.
- `src/Repositories/AccessRepository.php`: named PDO operations, transaction boundary and owner-row locking.
- `src/Services/AccessService.php`: catalog approval, database-verified access, grants, consumption and notification business rules.
- `src/Http/AccessController.php`: canonical JSON access routes.
- `tests/AccessIntegrationTest.php`: isolated real MySQL PHPUnit tests, including separate-process races.
- `tests/Support/AccessTestDatabase.php`, `tests/access-quota-worker.php`.
- `tests/access-test-db.sh`, `bin/test-access.ps1`: isolated database creation/test/drop harness.
- `docs/architecture/access-notifications-phase.md`: this report.

Modified:

- `src/Infrastructure/MigrationRunner.php`: register `080` with dependency `020`, reconcile existing phase tables/CSRF column before DDL.
- `bin/verify-database.php`: accept the applied `080` ledger, verify its table/FK inventory and nullable binary CSRF digest column.
- `public/index.php`: compose the real access controller before the existing analytics guards; safe JSON 503 on storage failure.
- `tests/LegacyRoutesTest.php`: new access HTTP/identity/proxy-header regressions while preserving old tests.
- `IMPLEMENTATION_STATUS.md`: current evidence and remaining gates.

Other prior-phase changes remain as they were at the start of this phase. No SSR design, demo fixtures, workers, secrets, environment files, model releases or billing provider configuration changed.

## Migration and FK report

**Added and applied locally:** `080_access_notifications`, through `php bin/migrate.php 080_access_notifications`.

Actual migration output:

```text
000_schema_ledger: already applied
020_identity: already applied
080_access_notifications: applied
```

This optional user-facing track depends only on identity, as permitted by the V4 DAG. It does not imply that absent model, publication, simulator or payment migrations ran.

Adds ten canonical tables and fifteen FKs, all InnoDB with default restrictive deletion:

| Table | Ownership / foreign keys |
| --- | --- |
| `plans` | catalog root; unique code; enabled false by default |
| `plan_versions` | plan FK; approving user FK; unique plan/version; approved feature/effectivity snapshot |
| `plan_prices` | plan-version FK; unique version/currency/interval; integer minor amount |
| `subscriptions` | user and plan-version FKs; unique user/id for owner-scoped references; default pending |
| `subscription_events` | subscription and actor-user FKs; reserved for approved lifecycle work |
| `entitlements` | owner-user FK, composite user/subscription FK, approving-user FK; explicit scope, effectivity, approval, limit and period |
| `usage_counters` | composite user/entitlement FK; unique user/feature/scope/period; unique user/id |
| `usage_events` | user FK and composite user/counter FK; unique user/feature/request; immutable consumption receipt/result |
| `notifications` | user FK; unique user/event key; owner/id pagination index |
| `notification_outbox` | notification FK; unique notification/channel; atomic in-app availability record |

Also adds nullable `sessions.csrf_token_hash BINARY(32)`. Existing session token hashes/expiry/revocation are preserved; old sessions without a CSRF digest can perform permitted reads but cannot perform new writes. Only a trusted session issuer may populate that digest. Seeds four **disabled name-only** plans and six permission codes; seeds no role assignments or business grants. Existing migration files/checksums remain untouched.

Post-application `php bin/verify-database.php` output at `2026-11-06T15:07:15Z`:

```text
mysql_version: 8.4.13
timezone: +00:00
table_count: 42
foreign_key_count: 54
verified_migrations:
  000_schema_ledger
  010_catalog
  020_identity
  030_source_provenance
  080_access_notifications
canonical_markets: 8
```

An additional read-only count confirmed local data isolation: plans 4, plan_versions 0, plan_prices 0, subscriptions 0, entitlements 0, usage_events 0, notifications 0. All integration fixture records lived in disposable test databases, which were dropped with their temporary grants revoked.

### Plan immutability constraint and escalation

The first isolated DDL rehearsal rejected proposed plan immutability triggers with MySQL error **1419**: the migration account lacks SUPER under binary logging. No global server setting or privilege was broadened. The uncommitted trigger design was removed before the final migration was installed locally; that failed scratch database was discarded. Plan publishing now appends new versions/prices through the service, and exposes no update/delete operation; its tests verify prior prices are preserved when publishing another version.

**Database enforcement against direct SQL mutation remains a release gap.** The existing app account has broad DML on its application database, so append-only service code is not a proof against another direct database writer. Resolve this through an independently reviewed least-privilege/immutability deployment design before production. Do not silently enable a MySQL global trust flag or grant SUPER to satisfy the trigger proposal. The applied `080` checksum is final; subsequent hardening requires an additive migration/deployment change, not editing/replaying this migration.

## Access, quotas and notifications

Transport credentials contain no owner ID or plan. The front controller reads only `__Host-simora_session` and the CSRF header and derives HTTPS from the web-server `HTTPS=on` flag. It ignores supplied user/plan headers, query claims and untrusted forwarded-protocol headers. Private services resolve the opaque token's SHA-256 digest against an unrevoked, unexpired database session and active user, then check live database RBAC. Write operations additionally compare the presented random CSRF token's digest in constant time. No cached/browser plan flag grants access.

Creation of login sessions, rotation, cookie issuance and account/role provisioning remain owned by the earlier identity phase; no insecure login/bootstrap endpoint was introduced here. A production issuer must bind a random CSRF digest to its session and set secure host-only/HttpOnly/SameSite cookie attributes. Trusted TLS/proxy configuration is also required; an arbitrary `X-Forwarded-Proto` header cannot enable private access.

`approvePlan` is an internal RBAC/CSRF-protected administrative service, with an approval reference and non-empty explicit feature rules. It supports the four recorded names/prices only, appends a feature/price version and audit, and exposes prices as informational with `purchase_available: false`. No purchase, paid subscription activation or implicit feature assignment occurs. Feature rules must state exact feature, scope, quota (null only for an explicitly approved unlimited grant) and fixed reset-period seconds. Calendar-month quota resets, rollover, grace, trial eligibility and renewal are not inferred from price labels.

Manual grants require the `access.admin` permission, explicit approval reference, non-backdated UTC effectivity, quota and fixed period/anchor. They lock the target owner, reject overlapping feature/scope intervals, and audit grants/revocations. Exact-scope lookup deliberately has no wildcard/free fallback or additive stacking. A subscription-linked grant must belong to the same user, and only operates while the subscription is active, in-period and linked to an effective approved plan version. Pending, expired, revoked or ambiguous grants fail closed. Entitlement permission does not override model/source release, financial or independent trading gates.

Quota consumption requires a verified write session, exact feature/scope, positive bounded integer cost, owner-scoped request key and a canonical business-operation hash. All requests for an owner serialize on the locked database session/user; grants and RBAC are locked/rechecked. The fixed UTC period is computed from database time and the explicitly approved anchor, never a client clock. A unique counter is locked and checked before running the protected database callback. Consumption, business writes, immutable request receipt/result and audit commit together or roll back together. Same-key retries return the stored result without rerunning work or consuming again; changed inputs yield 409. Revoked access cannot retrieve a previous consumption through the retry path. Exhaustion is **429 `quota_exhausted`**. Counter/grant replacement within an existing period yields `quota_policy_changed` rather than silently resetting usage. There is no quota-consumption HTTP endpoint; feature handlers must wrap their real transactional work with this service. Callbacks must not send network requests or irreversible external effects; use an owned outbox for such future integrations.

In-app notifications have bounded plain-text title/body payloads and per-owner event keys. A verified notification producer writes the notification, its in-app availability/outbox record and creation audit in one transaction. Repeated identical events return the existing ID; changed payloads conflict. `delivered` here means committed availability in the owner inbox, not email delivery or user reading. This is not an external Redis dispatch pipeline. No SMTP, push, Slack, SMS or other external sending is implemented or performed. Read-state updates require owner, RBAC and CSRF, retain the first read timestamp and audit that transition once. Pagination is by owner and descending ID.

## API diff

These routes now use real PDO-backed data rather than missing-route behavior:

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/api/v1/plans` | public approved/effective catalog only; currently 200 with empty data because no feature policy was approved; no demo fallback |
| GET | `/api/v1/subscription` | verified owner and `access.read`; returns that owner's ledger records, max 100; status alone is not an access grant |
| GET | `/api/v1/me/notifications` | verified owner and `notifications.read`; optional positive `before_id`, `limit` 1..100; `meta.next_before_id` |
| PATCH | `/api/v1/me/notifications/{id}/read` | verified owner, `notifications.update`, CSRF; idempotent first read timestamp; non-owner/missing 404 |

Envelope remains `{data,error,meta}`, with object error, request ID, UTC as-of, no-store and nosniff. Successful data origin is `server_verified`; this metadata is not itself a credential. Timestamps are UTC ISO strings and prices use minor-unit decimal strings. Wrong methods return 405/Allow; malformed notification query parameters 422; missing/invalid session under HTTPS 401; insecure transport, missing RBAC or failed CSRF 403; unavailable schema/storage 503. Query validation may reject unknown fields before authentication; it never returns owner data on that path. No duplicate aliases were added.

Legacy history fields/string errors and all PHP SSR routes/designs remain unchanged. Demo `access=pro` still only changes a synthetic preview. Model/simulator APIs keep their prior missing-persistence guards; this phase does not pretend those earlier modules are operational.

## Tests actually run

```text
powershell -NoProfile -ExecutionPolicy Bypass -File bin/test-access.ps1
PHPUnit 10.5.66 / PHP 8.2.12 / real MySQL 8.4
OK (13 tests, 62 assertions)

php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml.dist
OK (148 tests, 658 assertions)
```

The isolated harness creates a uniquely prefixed database, applies all prerequisite migrations plus `080`, verifies an idempotent rerun, creates unit-only identities/sessions/roles/policies, runs real separate-process quota races, then drops only that database and revokes only its temporary grants. The app user retains DML-only rights; the migration user does not receive SUPER. Container root is used locally only for creating/removing that isolated test database, through an ephemeral socket client config removed on exit; credentials are not printed or committed.

Integration cases prove approved-only catalog snapshots/prior-version preservation, no automatic subscriptions, missing-grant denial, current database session/revocation/RBAC/CSRF verification, transaction rollback including a business write, exact quota exhaustion, same-key and changed-key behavior, simultaneous last-slot contention **200/429**, concurrent duplicate requests **200/200 with one effect**, reset windows, entitlement/subscription expiry, pending subscription denial, administrative overlap/backdate rejection and auditing, notification ownership/pagination/deduplication/read idempotency, structured controller errors and composite FK rejection of cross-owner counters/events. Standard tests include actual old PHP/legacy history HTTP regressions plus forged identity/plan/proxy-header rejection.

Other executed commands, all final exit 0:

```text
php bin/verify-database.php            42 tables, 54 FKs, five successful checksummed migrations, 8 markets
php tests/migration-checksum.php       Migration checksum regression test passed
php tests/mysql-prerequisites.php      MySQL prerequisite integration tests passed
php tests/billing.php                  Billing tests passed
php tests/identity.php                 Identity primitive tests passed
php tests/catalog.php                  Catalog market and identity tests passed
php tests/platform-details.php         Platform dataset, service and integration-contract tests passed
php tests/predictions-hub.php           Predictions hub tests passed
node tests/history-load-more.cjs       Load-more interaction tests passed
node tests/platform-interactions.cjs   Platform interaction tests passed
node tests/auto-trade-draft.cjs         Auto Trade preview draft tests passed
```

Scoped PSR-12 passed with no warnings/errors for all new access PHP components/helpers/tests and the modified legacy HTTP test. PHP lint passed for all twelve added/modified PHP files. `git diff --check` passed with existing Windows LF/CRLF notices. The integration suite is intentionally invoked separately, not silently skipped inside the default suite when MySQL setup is absent.

Development failures and resolutions: Windows stdin appended a CR line to the first scratch setup; changed transport to base64 for the static script. MySQL trigger privilege failure is documented above. An HTTP test originally expected only 403 when forged notification query fields correctly produced 422; corrected the assertion and added a separate valid-query proxy/header-spoof denial check. PSR-12 formatting/length findings were corrected. Earlier integration iterations passed 6/34, 11/54 and 12/60 before final FK coverage reached 13/62. No regression remains in executed checks.

Not run: hosted CI, production deployment, real browser HTTPS login/session issuance and rotation, trusted reverse-proxy E2E, approved real-customer grant rollout, database restore/destructive rollback, large-scale load/security assessment, direct-SQL plan immutability hardening, external notification delivery/Redis dispatch, payment provider/webhook/wallet flows, model-serving integration or workers. No trial/renewal/payment behavior is claimed tested.

## Remaining gates, rollback and restart

The commercial feature/quota/reset matrix is still awaiting the user's answer. No illustrative limits have been promoted to policy. Administrative separation/role provisioning, approval operations and the earlier secure identity issuance integration need release review. Direct-SQL plan immutability needs independently reviewed least-privilege hardening. Fixed-period grants do not invent calendar/rollover/renewal rules. External notification channels and provider billing remain separate gated work. Earlier source/model/publication/simulation persistence blockers are unaffected.

Subsequent update: the user supplied a matrix explicitly marked **recommended, pending final approval**. It is preserved in [the pending V1 policy proposal](access-policy-v1-proposal.md), with the six-versus-eight-market conflict, missing model/market identities, simulator-cap changes and Basic/Full/automation definitions identified for reconciliation. Its arrival resolves the absence of a proposal, not its approval; no live grant or published version was activated.

Prefer a **code-only rollback retaining the applied additive schema and ledger**: remove the new access dispatch branch and its consumers while preserving the Phase 7 API branch, old SSR/history routes, existing files and all unrelated edits. Retain migration `080`, its checksum, the runner registration and schema-aware verifier even if access routes are disabled. Do not delete a successfully applied migration file or rewrite its ledger. The ten new tables and nullable column are compatible with the previous preview code. No grants/customer data were seeded, and no daemon, provider, billing flow or external sender was started.

A database reversal is not automatic or transactional because MySQL DDL commits. It requires a backup, reconciliation of any later real records/role assignments and a separately reviewed additive reversal/restart plan. Preserve immutable audit/event history; do not drop tables or CSRF state after customers start using them merely to match an old checkout. Scratch test databases are already cleaned up. No database server restart was needed.

Restart with Git/ledger inspection, `php bin/migrate.php 080_access_notifications` (idempotent), `php bin/verify-database.php`, the isolated test harness and the standard PHPUnit suite. Keep approved plan versions/grants disabled until the actual feature/reset policy and authorization operations are supplied. Finish this phase's release gates before considering the next phase; no payment phase was started automatically.
