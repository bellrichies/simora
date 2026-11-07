# Phase 11 delivery report — referrals, reviews, support and optional chat

Date: 2026-11-06. Source of truth: [master backend blueprint V4](../SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md). This is an implementation report, not an alternate backend blueprint.

## Approval and implementation status

The user authorized only this phase and explicitly clarified that financial referral terms and chat launch policies remain unapproved recommendations. Existing proposed 10% referee discount, 20% referrer credit capped at NGN2,000, 30-day first-valid attribution and 90-day qualification are preserved. The alternative 5% recommendation, five rewards/month and 90-day credit expiry are not merged into an invented policy. No referral discount, visitor tracking/cookie, attribution capture, paid qualification, reward issuance/redemption/reversal or payout path is enabled. Max priority support and proposed 90-day chat retention are not approved or operational.

Implemented: gated server APIs and canonical persistence for private referrals/code projections, verified-account reviews with independent moderation, owner/assigned-agent support conversations, protected security/privacy cases, article publication and optional consented/premoderated shared community chat. All modules default disabled, require unexpired approval/privacy/moderation/retention references and positive bounded rate policies. Optional chat additionally requires named moderation/privacy owners and approved abuse-response/rights procedures. Providing configuration strings is not evidence of real-world approval; release owners must supply and verify the referenced decisions.

The existing SSR referral/review/support pages and floating chat widget remain clearly labeled, isolated demonstrations. They do not post content, track visitors, access live tickets or transmit messages. Live UI integration is not declared implemented. Existing legacy history contracts and all eight markets are retained. No model training, numerical worker, prediction, source, payment or trading logic is changed.

At phase start, Git contained substantial uncommitted prior-phase work, which was preserved. Local read-only verification at 2026-11-06T16:52:59Z found MySQL 8.4.13, seven successful migration checksums, 51 InnoDB tables, 78 foreign keys and eight canonical markets. Existing code/tests/module docs were examined before implementation. Older module proposals suggested extra schemas and unversioned routes; V4 takes precedence and those docs are now clearly marked historical/proposed where superseded.

## API contracts

All new paths are under `/api/v1`; replies retain `{data,error,meta}`, safe error codes and no-store caching. **All reads are authenticated and permission checked.** Writes require HTTPS, actual DB session/RBAC, CSRF and verified active accounts. Request identity, public visibility, authorship, financial benefits and staff roles are derived server-side. No preview cookie/query grants access. Successful content commands return 200 receipts; receipt replays do not assert a still-current content status. Current state is read separately.

| API | Permission and behavior |
| --- | --- |
| `GET /referrals` | `referrals.read`; own opaque code/null and own registered count only. No peer identity/activity or invented earnings. |
| `POST /referrals/code` | `referrals.create`; empty object, stable random 128-bit code per owner, revoked code never silently restored. Attribution/rewards/payouts false. |
| `GET /reviews` | `reviews.read`; published-only member audience, generic author label, bounded cursor. |
| `POST /reviews` | `reviews.submit`; integer rating 1–5, plain title/body, request key. Permanent account slot, pending state, idempotent receipt; changed payload conflicts. |
| `GET /me/review` | `reviews.read`; own pending/current content, status/version or null. |
| `POST /me/review/{id}/withdraw` | `reviews.submit`; owner-only, expected version/request key, terminal withdrawal. |
| `GET /admin/reviews` | `reviews.moderate`; bounded private moderation queue. |
| `POST /admin/reviews/{id}/decisions` | `reviews.moderate`; independent actor, action/reason/version/key; approve/reject/hide with auditable transitions. Negative ratings alone do not prevent approval. |
| `GET /support/tickets`; `POST /support/tickets` | `support.read` or `support.create`; owner-only list and idempotent private ticket/initial message creation. Categories allowlisted; priority normal only. |
| `GET /support/tickets/{id}`; `POST /support/tickets/{id}/messages` | Owner read/reply permissions; customer-visible messages only, expected version/key for reply. No client-controlled internal visibility. |
| `GET /admin/support/tickets` | `support.triage`; bounded queue metadata. Restricted privacy/security cases require `support.restricted`. |
| `GET /admin/support/tickets/{id}`; `POST /admin/support/tickets/{id}/messages` | Assigned eligible `support.agent`; independently staffed case access. Agent reply, private note or resolution. Additional permission for restricted cases. |
| `POST /admin/support/tickets/{id}/assignment` | `support.manage`; expected version and eligible agent ID; owner cannot be assigned as their own agent; assignment audit records target. |
| `GET /support/articles` | `support.read`; independently published articles only. |
| `POST /admin/support/articles`; `POST /admin/support/articles/{slug}/{version}/publication` | Separate write/publish permissions; immutable initial draft version 1 for each unique slug; independent publication/withdrawal. Further content editing/revision policy is deferred. |
| `POST /admin/chat/room`; `POST /chat/membership` | `chat.manage` opens/closes the general room after policy gates; verified join requires exact current-policy consent. No room seeded. |
| `GET /chat/messages`; `POST /chat/messages` | Consented members with read/send permissions; published-only bounded snapshots and idempotent pending submissions. No direct messages, rich links or uploads. |
| `GET /admin/chat/messages`; `POST /admin/chat/messages/{id}/decisions` | Consented independent moderators review/approve/hide with reason and expected version. Private queue includes author account ID for authorized bans/mutes; normal feeds omit it. |
| `POST /chat/messages/{id}/reports`; `GET /admin/chat/reports` | Authenticated member reports; one retained report per actor/message, no automatic hiding. Moderators read protected reason/message references without reporter identity. |
| `POST /admin/chat/members/{id}/restriction` | `chat.manage`; staff ban/mute/unban, bounded minutes, audit. Rejoin does not clear bans/mutes. |

IDs for content are opaque prefixed random tokens. Lists use indexed descending numeric `before_id` and limit 1–50 (default 20); next cursor never bypasses ownership/visibility. Unknown fields/queries, wrong types and invalid UTF-8/content are rejected. Bodies are bounded to 64 KiB before parsing; narrower text/byte caps apply per domain. 401/403 authentication/authorization, 404 inaccessible records, 405 with Allow, 409 stale/duplicate/transition conflict, 413 size, 422 validation, 429 with Retry-After and 503 gate/storage failures use structured JSON. There is no generic reward/claim/capture/attachment or external communication route.

Repeated creation/message/review-decision keys with the same payload replay immutable receipts; changed payload conflicts. Version checks protect staff assignment/publication/moderation state, which do not promise generic command receipts where none are stored. No unapproved close/reopen, escalation, SLA or automatic support response is invented. Article history and chat report decisions do not introduce extra noncanonical tables.

## Privacy, moderation and abuse controls

- Account row locks serialize shared audit-count rate checks across sessions/processes. Rolling minute write/read ceilings and rolling 24-hour ticket limits use DB UTC. Identical creation/message retries consume no additional write slot; failed business writes roll back content, audit and outbox. This is account-based abuse control, not a deployed anonymous/IP/network flood defense.
- Review publication requires a distinct human moderator. Pending/rejected/hidden/withdrawn content never appears in normal reads, and withdrawal/hiding removes it immediately without a public cache. Reviews are application feedback, never model performance evidence or marketing consent. No edit/resubmission/appeal policy is fabricated.
- Support thread bodies require ownership or assigned-agent access; privacy/security cases require an additional staff permission. Ordinary owner projections exclude internal notes, and note creation produces no customer notification. In-app notifications and canonical outbox payloads contain record IDs/visibility/status, never message bodies. No email/Slack/provider/user message is sent externally.
- Optional chat requires current consent on every read/send, active membership, applicable mute/ban state and independent premoderation. Report audit records are private; staff reporting output omits reporter IDs. No personal-name/account-ID field is in normal content projections. No IP/device fingerprint or third-party tracker is stored. Plain-text heuristics reject common markup/link/email/phone disclosures but are not comprehensive DLP or proof that human free text is anonymous.
- Chat reads are **replaceable bounded snapshots**, not an append-only feed/event cursor. A future UI must replace its visible window, escape text, honor no-store and hide removed content. No live polling/reconnect UI is integrated. Existing widget remains disabled and non-transmitting.
- Privacy/deletion request intake uses owner-bound `privacy_security` support tickets after approval. Automated erasure/export/retention jobs, personal block lists, staffed appeals and locked-out recovery are deferred policy/operations work. Restrictive FKs retain history pending an approved retention/legal-hold process; no arbitrary duration or compliance guarantee is claimed.

No billing privileges, experience roles, policies, FAQ text, review content, tickets, chat room/member/messages or referral financial obligations are seeded for application users. The existing broad local app DML role is not database-level append-only enforcement; deployment privilege/audit-access hardening remains a release gate. Redis outbox delivery/retries/dead-letter operations and network/TLS/load/restore reviews remain unverified infrastructure dependencies; this phase does not implement Phase 12.

## Migrations and foreign keys

`100_experience_gated.sql` creates the eight canonical referral/review/support tables. It adds the actor/operation/time audit index, extends the **existing** canonical outbox for review/support-message ownership and adds 14 unassigned permission codes. `110_chat_optional.sql` separately creates only the three canonical chat tables and adds five unassigned permissions. It is part of optional Phase 11, not permission to proceed to the next phase.

The runtime outbox already contains payment ownership columns from 090; therefore the implementation DAG explicitly makes 100 depend on 090 and its existing identity/access/provenance prerequisites. This is a documented compatibility dependency, not referral-reward approval. Optional 110 depends on 100 and catalog 010 for platform/league FKs and shared guards. No applied older migration is rewritten, no duplicate table created, no reference schema imported and no demo content backfilled. V4 retains a unique FAQ slug; the older proposal to store multiple rows per slug was rejected before local application.

15 FKs in 100:

| Ownership/relationship | Constraints |
| --- | --- |
| Referral code owner | `fk_referral_code_user` |
| Referrer/code pair and referred account | `fk_attribution_code_owner`, `fk_attribution_referred` |
| Reward attribution/referrer pair | `fk_referral_reward_owner` |
| Review author and moderation review/actor | `fk_review_author`, `fk_review_moderation_review`, `fk_review_moderation_actor` |
| Support owner/assigned agent | `fk_support_ticket_owner`, `fk_support_ticket_agent` |
| Message ticket/actor | `fk_support_message_ticket`, `fk_support_message_actor` |
| FAQ author/publisher | `fk_faq_author`, `fk_faq_publisher` |
| Canonical outbox to review/message | `fk_outbox_review`, `fk_outbox_support_message` |

Six optional chat FKs: `fk_chat_room_platform`, `fk_chat_room_league_scope`, `fk_chat_membership_room`, `fk_chat_membership_user`, `fk_chat_message_membership`, `fk_chat_message_moderator`. Composite scope keys preserve code ownership, attribution/reward ownership, platform/league mapping and message/membership scope. Self-referral and invalid review ratings are checked in SQL; author/referred-user/request uniqueness prevents duplicate slots. Published FAQs/chat messages require a distinct publisher/moderator in SQL. All new FKs restrict deletion, not cascade content/audit away. The outbox CHECK retains exactly one owner across ingestion/payment/webhook/review/support-message fields; existing financial/ingestion inserts remain compatible.

Local migration **100 applied** after fresh-schema rehearsal; optional **110 remains unapplied locally** and was tested only in isolated schemas. Verification at **2026-11-06T17:26:28Z** confirms MySQL **8.4.13**, eight successful checksummed migrations, **59 InnoDB tables**, **93 FKs** and **eight markets**. All eight experience tables have zero application records; all four configured feature flags are false. Chat table counts are null (not installed), not fabricated zero counts. Financial invariant verification still reports zero violations, zero payments/journals and payouts disabled. Optional 110 installation is a separate deployment choice, not a live launch and has no seed/open effect.

## Changed paths

- Schema: `database/migrations/100_experience_gated.sql`, `110_chat_optional.sql`, `src/Infrastructure/MigrationRunner.php`, `bin/verify-database.php`.
- Configuration/composition: `.env.example`, `config/experience.php`, `src/Infrastructure/ExperienceServiceFactory.php`.
- Domain: `src/Domain/Experience/ExperienceException.php`, `ExperiencePolicy.php`; existing approved `src/Domain/Reviews/ReviewPolicy.php` reused.
- Persistence: `src/Repositories/ExperienceRepository.php`, `ChatRepository.php`.
- Services: `src/Services/ExperienceContext.php`, `ReferralService.php`, `ReviewService.php`, `SupportService.php`, `ChatService.php`.
- Routing: `src/Http/ExperienceController.php`, `public/index.php`; existing session verification and JSON response components reused.
- Verification: `bin/test-experience.ps1`, suite allowlist in `bin/test-access.ps1`, `bin/verify-experience.php`, `phpunit.xml.dist`, `tests/ExperiencePolicyTest.php`, `ExperienceIntegrationTest.php`, `experience-race-worker.php`, added HTTP privacy/gate checks in `LegacyRoutesTest.php`.
- Documentation: `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, `docs/referrals.md`, `docs/reviews.md`, `docs/support.md`, `docs/community-chat.md`, `docs/plans-billing.md`, new `docs/privacy.md`, this report and `IMPLEMENTATION_STATUS.md`.

All prior-phase uncommitted changes remain separate context; they are not claimed as newly implemented in this phase. Existing views/assets/demo services are not rewired to real customer data.

## Tests actually run

```text
php vendor/phpunit/phpunit/phpunit --testsuite experience
OK (10 tests, 13 assertions)

powershell -NoProfile -File bin/test-experience.ps1
OK (11 tests, 100 assertions)
Final run: 22.447 seconds; earlier fresh-schema rerun passed 11/97 in 15:15.638 on this slow host

php vendor/phpunit/phpunit/phpunit
OK (201 tests, 828 assertions)
Final run: 5.667 seconds after background work completed

php vendor/phpunit/phpunit/phpunit --filter '^(?!.*LegacyRoutesTest)'
OK (182 tests, 628 assertions)

Scoped PSR-12
Exit 0; no errors or warnings

node tests/community-chat.cjs
Community chat widget tests passed

php tests/review-policy.php
Review policy tests passed
```

MySQL coverage includes fresh migrations/idempotent reruns, private pending review and one-slot replay, negative-rating approval, independent moderator/publisher, version/idempotency conflicts, owner-only tickets, assigned agents/private notes, restricted security tickets, daily/minute write rollback, exact account-wide two-session/two-process last-slot enforcement, optional chat consent/premoderation/reports/mutes/bans/hiding, referral ownership/no reward/ledger writes, and feature/approval/CSRF/verification gates. A Unicode text test exposed a byte cap applied before bounded whitespace trimming; allowing bounded padding while retaining codepoint/byte caps fixed it, and the focused suite passed. Initial formatting findings were corrected; no abuse threshold was weakened.

PHP syntax checks passed for all 20 new/affected PHP files; the two final service projection changes were checked again. Scoped core PSR-12 and the verification CLI are clean, and whitespace checks passed with informational LF/CRLF notices. Final privacy projection tests confirm only authorized moderators see the chat author ID, the ordinary feed omits it and staff support views label customer messages consistently.

Regression history is retained rather than hidden: an initial full run failed HTTP server readiness (183 tests/628 assertions, one LegacyRoutesTest setup error). A second full run completed 201 tests/771 assertions with 12 HTTP timeout failures/warnings; a focused HTTP retry completed 19/175 with five timeout failures/warnings. An initial referrals browser run timed out at `Page.captureScreenshot`. DB metadata sampled during the slow period showed executing/update work without a lock wait at that moment; this is not a complete performance diagnosis. After background work completed, the unchanged full suite passed 201/828 in 5.667 seconds, including legacy SSR/history and new HTTP privacy gates. No timeout, assertion, market behavior or abuse limit was relaxed. This does not certify latency/load/SLO performance.

Relevant browser preview reruns all passed: `node tests/referrals-page.cjs`, `node tests/reviews-page.cjs`, `node tests/support-page.cjs` validated totals/filters/pagination, local draft/copy behavior, help search/FAQ/conversation details, responsive layout and no-JavaScript behavior. The shared chat widget unit test passed; no live chat UI is integrated. Final read-only verification at 2026-11-06T17:42:02Z again confirmed all four feature flags false, zero records in all eight application experience tables, optional chat uninstalled and referral tracking/rewards/payouts false.

Unverified/release dependencies: real production identity/TLS/roles, commercial/consent/retention/privacy decisions, real named staffing, live UI/transmission/two-session browser integration, shared network flood defense, Redis operations, DB privilege hardening, automated rights/retention execution, external notification integrations, load/accessibility/security/backup/restore/hosted CI deployment. Optional backend tests are not public chat release certification. No next phase started.

## Rollback and restart

Disable all four experience flags and stop any downstream experience consumers. Keep existing SSR previews clearly labeled; keep visitor attribution/rewards/payouts disabled. Preserve content, request keys, consent/moderation references, audit, notifications and outbox records. Do not delete posted financial history or apply proposed referral terms as a rollback shortcut.

Prefer a code rollback of only this phase's hunks, retaining schema-aware migration/verifier support and all earlier uncommitted work. MySQL DDL is nontransactional; a failed/partial migration requires checksum/physical-state inspection and a reviewed forward correction. Never edit/delete an applied 100/110, drop customer tables automatically or invent destructive down migrations. Retention/deletion changes require the approved process and backup/legal-hold handling, not a broad cascade.

Restart by inspecting Git, migration ledger/checksums and feature flags. Run `php bin/verify-database.php`, `php bin/verify-experience.php`, `php bin/verify-payments.php`, isolated experience/access/payment suites, the default suite and relevant browser regressions. Apply only the intended experience migration with a configured migration actor/build; optional 110 requires an explicit installation choice and does not enable chat. Recommended next work is a separately requested Phase 11 launch-readiness continuation covering the missing approvals, retention/rights, UI and operations; Phase 12 remains unstarted.
