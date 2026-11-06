# Decision records

This directory indexes decisions, not evidence that proposed infrastructure is deployed. The authoritative target is [V4](../../SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md); source conflicts and open gates are in [the audit](../source-contract-conflicts.md).

| ID | Decision | Status | Evidence / next gate |
| --- | --- | --- | --- |
| DR-001 | V4 governs new implementation; retain SSR and legacy history contract. | User-directed | Preserve existing routes in subsequent changes. |
| DR-002 | Existing JSON fixture repository is preview-only. | Confirmed code boundary; production enforcement proposed | Partitioned persistence and release tests required. |
| DR-003 | Eight markets and canonical relational names. | Target, not implemented | Catalog and compatibility migrations, eight-market tests. |
| DR-004 | Migration ledger, locked runner, staged additive upgrades and deferred circular FKs. | Proposed | Inspect actual target DB and test MySQL 8 fresh/upgrade/restore; no migrations applied here. |
| DR-005 | Auto Trade execution default-off pending independent approvals. | Required gate; no execution connector found | Written provider, security, legal, consent and risk approvals. |
| DR-006 | Observation polymorphism uses typed FKs or a referenced mapping with transactional validation. | Open design choice | Select and test in ingestion phase; payload FK mandatory. |

Add dated, individually reviewed records when implementation choices are approved; do not imply an open decision is closed by this index.
