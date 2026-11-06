# Source and contract conflicts

Historical baseline audit. Subsequent work added all eight canonical market helpers, a migration runner, identity/ingestion primitives, Composer/dotenv and isolated local MySQL 8.4.13. See `live-source-phase.md`, `local-database-verification.json` and `../local-development.md` for current verification. Statements below describe the initial audit and must not be treated as the current feature inventory. No provider access or production backend capability is established by these additions.

Status: audit, 2026-11-06. The user designated `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md` as authoritative for implementation. `AGENTS.md` instead names `docs/architecture/SIMORA_BACKEND_BLUEPRINT.md`, which is absent; the tracked `docs/SIMORA_BACKEND_BLUEPRINT.md` is deleted in the existing working tree. This is a source-of-truth conflict, not evidence the deleted file should be restored. V4 supersedes older design documents; preserve current interfaces until a separately approved phase provides adapters.

| Subject | Observed contract | V4 target / disposition |
| --- | --- | --- |
| Markets | `config/app.php` and `MarketSettlement` implement six; `under_15` and `over_35` absent. Existing fixtures are six-market. | Eight canonical markets; additive implementation and tests in later approved phases. Do not represent current preview as eight-market. |
| Football schema | `schema.sql` has `leagues`, `league_weeks`, `predictions`, varying VARCHAR lengths and no binary collation declaration. | `virtual_leagues`, `virtual_rounds`, publication lineage and VARCHAR(128) ascii_bin football IDs; inspect any deployed DB and stage adapters, never create duplicate systems. |
| Result history | Reference schema stores scores on matches and `predictions` with mutable-looking records. | Result revisions, immutable publications and settlement revisions, point-in-time evidence; preserve legacy DTO. |
| History API | `GET /api/platforms/{slug}/predictions` accepts legacy filters, returns `{data,error,meta}`, labels and minor-unit odds. | Preserve endpoint and error envelope; new v1 endpoints remain proposed, no silent replacement. |
| Metrics and synthetic data | Generator and preview calculate selections and simulated returns from synthetic results; fixtures cannot establish forecast skill or financial ROI. | Isolate preview; never train or evaluate release models on outcome-selected fixtures, never publish synthetic financial claims. |
| Access | `access=pro` changes preview presentation; billing defaults to mock. | Authentication, CSRF, RBAC and server-side entitlements are future requirements; query parameters do not authorize access. |
| Execution | Auto Trade is local draft/risk preview. | Execution remains blocked pending independently approved provider, security, legal, consent and risk gates. |
| Infrastructure | Manual autoloader, no Composer, PDO backend, migration runner, Redis outbox, Python worker or CI found. | V4 target is proposed, not operational. PHP CLI 8.2.12 observed locally only. |
| DB | Reference schema describes MySQL 8, not an applied ledger. Local reachable server is MariaDB 10.4.32, no Simora-named database or configured target found. | No inference about deployed schemas; MySQL 8 fresh/upgrade and actual target introspection remain blocked. |

V4 sections 2 and 7 resolve the design-level naming, chronology and migration conflicts. Those resolutions are target decisions, not evidence of implementation or provider authorization. Exact physical schema, external rights and deployed database require owner confirmation. No contract has been changed in this audit.
