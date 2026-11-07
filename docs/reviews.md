# Reviews module notes

## Phase 11 current implementation — 2026-11-06

The [master V4 blueprint](SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md) is authoritative. Historical design suggestions below are not another backend blueprint. The approved application-only, one-review-per-verified-account, 1–5-star, human-premoderation rules are implemented in gated `/api/v1` services using only canonical `reviews` and `review_moderation`. An independent moderator must approve; published content can be hidden or withdrawn. No sentiment-based approval or profitability metric is inferred. Public naming/audience policies are not assumed: the current API is members-only and uses “Community member.” No parallel revision/report/aggregate tables or unversioned proposal APIs were introduced.

Default deployment remains off pending privacy, moderation staffing, retention and operational approval. The SSR fixture preview remains labeled and isolated. Edits, resubmission, appeals, public marketing/SEO and public aggregates are deferred; the approved one-account slot survives withdrawal. Current fields, routes, tested behavior and release/rollback gates are in the [Phase 11 implementation report](architecture/experience-phase.md). Remaining prose below records the earlier assessment/proposals, not current implementation claims.

Status: implementation authorized; live release remains dependent on production foundations and unresolved policy decisions. The user approved application-only reviews, one review per account, 1–5 stars, human approval before publication, and verified email for submission. Other proposed rules remain subject to approval.

## Current page preview

`/reviews` now follows the Platforms workspace: compact, legible KPI cards, an accessible star distribution, a local draft editor and two-column review cards that stack on phones. Names, ratings and feedback are explicitly labeled fictional examples. This preview does not activate review submission, moderation or publication.

`ReviewsPreviewService` receives `database/fixtures/reviews.php` records and includes only published entries in aggregates and browsing. Eight example reviews produce a 4.0/5 average and 75% rated four or five stars. Distribution counts, totals and averages are derived from source records before rating filters. Empty datasets have no average or positive percentage. Rating and sort parameters are allowlisted, ordering has a stable tie-break, and pagination is bounded to six reviews per page.

GET forms and pagination support browsing without JavaScript. `reviews.js` adds automatic filter submission and a loading state. The draft editor validates rating/title/body, saves only to sessionStorage in the current browser tab, restores after reload, and supports clearing and storage errors. Draft content is inserted through form values and is never rendered as HTML or published. Without JavaScript, draft fields remain disabled to prevent accidental submission. Live submission retains the approved verified-email and human-approval requirements.

Validation: `node tests/reviews-page.cjs` covers aggregates, filters, empty results, sorting, pagination, the local draft lifecycle, responsive layouts at 1440/900/390/320px, and no-JavaScript fallback. PHP checks additionally cover exclusion of unpublished records, empty datasets and malformed queries.

## Document structure and decisions

This document contains assessment (§1), scope (§2), lifecycle and governance (§3), architecture and data (§4), API (§5), UX (§6), security and operations (§7), QA (§8), roadmap (§9) and decision records (§10). See [implementation-plan](implementation-plan.md), [reference analysis](reference-analysis.md), [data contract](data-contract.md), [plans and billing](plans-billing.md) and [community chat](community-chat.md). No approved SRS or ADR set was found; reference links do not establish feature requirements.

| Status | Decision |
| --- | --- |
| Confirmed constraints | Live scope remains planned; demo reviews and ratings are explicitly illustrative; keep subjective feedback separate from prediction outcomes; preserve existing architecture and approved documentation. |
| Approved | Simora application reviews only, one review per account, 1–5 stars, verified email required for submission, human approval before publication. |
| Open: product | Actual scope, audience and anonymous reading; experience eligibility; uniqueness, resubmission, editing and withdrawal; text limits, average threshold, incentives, search and notifications. |
| Open: governance | Moderation standards, staffing, permissions, appeals, sanctions, escalation, response times and official responses. |
| Open: legal/privacy | Jurisdictions, notice, retention, deletion, legal holds, author naming, incentive disclosure, marketing consent and public SEO. |
| Open: engineering | Production identity and verification, database and migrations, session/CSRF/RBAC, audit, shared rate limits, queues, notifications, cache and operating targets. |

## 1. Existing system assessment

`/reviews` is a fixture-driven preview through `DashboardController`, `ReviewsPreviewService` and `views/reviews.php`; `views/layout.php` supplies the navigation entry. There is no live reviews API or moderation service. The reference analysis observed a Reviews link but did not inspect its content. Public testimonials or a marketing page are not established requirements.

The application is PHP 8.1+ with a front controller, manual autoloading, injected services and repository interfaces, server-rendered templates, compiled Tailwind and small JavaScript modules. Existing dashboard layout, escaping, controls, responsive navigation and filter patterns can inform the UI. The existing prediction JSON envelope is `{data,error,meta}` with a string error; do not change it. Platform/league/prediction data and the account are synthetic fixtures. `database/schema.sql` is an unapplied MySQL reference without users or reviews. Fixture plan, notification and account data do not provide authentication, entitlements or real events.

Persistent users, verification, session/CSRF, RBAC, migrations, audit, shared rate limiting, queues and notification delivery are not established live services. Billing and chat documents propose future foundations, not implemented integrations. Do not import fixture identities or fabricate reviews. The existing roadmap marks Reviews design required.

## 2. Proposed functional scope

Recommend first release as reviews of Simora overall, with one current review per eligible account, an overall integer rating and written opinion. No subscription restriction is proposed. Authenticate and check account status server-side; verified email is required; minimum age, proof of use and anonymous reading remain decisions. Disable submission until eligibility can be established from real records. Subjective opinions never establish prediction accuracy, profit or bookmaker performance.

Platform, prediction and feature-specific reviews, dimension ratings, helpful votes, replies, testimonials, public marketing and trend charts are deferred enhancements requiring approval. If a subject category is added, reference authoritative platform/prediction records and define its own eligibility and uniqueness rules; do not accept unvalidated free-text subjects. Publication is not permission to use content in marketing. A verified account must not be presented as proof of third-party platform use.

Candidate validation: rating integer 1–5; optional title 1–120 Unicode characters; required body 20–2,000 after trimming; consistent client/server character counting, separate byte cap, plain text only. An incentive disclosure and bounded explanation are proposed, pending policy. Server derives author, status, verification and aggregates. One permanent account slot prevents concurrent duplicates even after withdrawal; whether resubmission is permitted must be resolved. Suggested configurable starting limits: 5 submissions/edits and 5 reports per account per hour, plus IP limits; tune with operations and monitor false positives.

User journey: browse published reviews; eligible user submits with a moderation notice; view private pending status, edit or withdraw under approved rules; receive a safe decision reason and appeal if that workflow is approved. A reader reports inappropriate published content; moderators review against written standards. Negative opinions are never rejected solely for being negative.

## 3. Lifecycle, aggregation and moderation

Candidate states: `pending`, `published`, `rejected`, `hidden`, `withdrawn`, `removed`. Draft remains client-side; submitted is an event; reported is a separate report state. Every transition locks and checks state/version, authorizes actor and records an audit event. Require reason codes and restricted notes for rejection, hiding, removal and reinstatement; share only an appropriate safe reason with the author.

| From | Actor/action | To / effect |
| --- | --- | --- |
| New | Eligible owner submits | Pending; private |
| Pending | Moderator approves / rejects | Published / rejected |
| Published | Owner edits | Pending; immediately remove old public revision and aggregate contribution |
| Pending or rejected | Owner edits | Pending with new revision |
| Published | Moderator hides | Hidden; remove public contribution |
| Hidden | Authorized moderator reinstates | Published after review |
| Pending, published, rejected or hidden | Owner withdraws | Withdrawn; remove contribution if present |
| Pending, published, rejected or hidden | Authorized moderator removes | Removed; remove contribution if present |

Withdrawn and removed are terminal in this proposal; restoration/resubmission needs approval. Never silently rewrite author content. If approved, appeals are independent cases with bounded grounds, a different reviewer, recorded outcome and a policy-defined deadline; no unapproved SLA. Reports do not themselves hide content. One open report per reporter/review; resolution and any moderation decision are separate audited actions. Staff review spam, harassment, personal information and unsafe links under published rules. Automated screening, if later justified, flags for human review; account sanctions belong to identity governance.

Aggregate only eligible, currently published revisions: count N, integer sum S and five buckets from one consistent snapshot; mean S/N rounded only for display. N=0 is “No ratings yet,” never zero stars. A threshold of five for showing the mean is proposed, not approved; below it show actual count and “Not enough reviews for an average.” Bucket total equals N; every visibility/rating change updates aggregates transactionally. Reconciliation recomputes from source records. No synthetic counts, weighting or mixing with verified performance.

Admin UI: oldest-pending queue, status/date/report filters, current content, revision and policy context; approve/reject/hide/reinstate/remove with version and required reason; restricted report and moderation histories, analytics and appeal queue if approved. Separate permissions `reviews.view_queue`, `reviews.decide`, `reviews.reports`, `reviews.appeal`, `reviews.analytics`, `reviews.audit`. Restrict reporter identity and private notes; audit actor, target, revision, old/new state, action, reason, correlation and UTC time. Role assignment, staffing and escalation require approval.

## 4. Architecture, integrations and data

Use the established modular PHP monolith: SSR/controller and progressive forms → session/CSRF middleware → thin controller → injected `ReviewService`, `ModerationService`, `ReviewQueryService` → repository interfaces and transaction manager → PDO/MySQL. Identity owns account state and permissions; reviews own review state and ratings. Inject clock and policy. Shared Composer PSR-4, middleware and migration foundations are future work. No microservice or external moderation dependency is needed.

Commit revision, state, aggregate change, audit and outbox event in one transaction; lock review then aggregate consistently and safely retry deadlocks. Aggregate is a rebuildable projection. Versioned cache keys use a database-backed version advanced in the same transaction, so late cache fills cannot restore old data; alternatively avoid caching initially. Public lists/details must have equivalent invalidation or no caching, especially after removal. Owner/admin data is private and never publicly cached. Durable workers consume outbox idempotently with bounded retry and dead-letter alerts. Notification of publication, rejection, report resolution or appeal depends on approved preferences and a real notification service; delivery failure never rolls back moderation.

Platform and prediction references remain disabled pending scope and authoritative production IDs; reviews never feed prediction performance calculations. Subscription status is not a gate without approved policy; fixture plan is not authority. Public SEO, structured data and testimonials need separate product/legal approval, accurate source data and consent. Verify applicable search-engine requirements at release.

Proposed MySQL/InnoDB tables assume real users and migrations; neither exists yet. Use matching user key types, unique opaque public IDs, UTC `DATETIME(6)`, utf8mb4, FKs, checks and prepared queries. No subject/category/engagement table is needed for application-only scope.

| Table | Relationships, constraints and indexes |
| --- | --- |
| `reviews` | id, unique public_id, author FK RESTRICT, state, current_revision_id, version, created/published/updated timestamps; unique author for proposed permanent slot; indexes `(state,published_at,id)` and `(author_id,updated_at,id)`. Ensure current revision belongs to review using composite FK where practical. |
| `review_revisions` | review FK RESTRICT, revision number, rating CHECK 1–5, title, body, incentive disclosure/detail, created time; unique `(review_id,revision_no)` and `(review_id,id)`. Immutable except approved privacy erasure. |
| `review_moderation_actions` | review/revision and actor FKs, action, old/new state, reason, private note, correlation, timestamp; index `(review_id,created_at,id)`. |
| `review_reports` | review/reporter FKs, status, reason, explanation, resolver, created/resolved times; index `(status,created_at,id)`; generated nullable open guard with unique `(review_id,reporter_id,open_guard)` enforces one open report while permitting resolved history. |
| `review_aggregates` | Application count, sum, five buckets, version, update time; nonnegative constraints, bucket sum equals count and valid sum bounds. |
| Shared audit, outbox, idempotency | Use shared foundation when built, not duplicate modules; unique principal/operation/key plus canonical request hash and result. |

An appeal table is deferred pending approval. Retention, privacy erasure, legal holds and account deletion/anonymization need explicit policy; RESTRICT FKs prohibit silent cascade. Deletion must remove public contribution and invalidate caches. Create additive versioned migrations behind a disabled flag after users and shared infrastructure; resolve the circular current-revision FK in migration order. Test fresh install, upgrade, checks, query plans, backup and restore. No fixture backfill. Once production writes exist, use forward corrections and reviewed recovery instead of destructive rollback.

## 5. Proposed REST API

These routes do not exist. Same-origin HTTPS; session and CSRF/origin checks on writes; derive identity server-side. New routes use `{ "data": ..., "error": null, "meta": { "request_id": "..." } }`; errors use `{ "data": null, "error": { "code": "...", "message": "...", "details": {} }, "meta": { "request_id": "..." } }`. Existing prediction API remains unchanged. IDs are opaque, timestamps ISO 8601 UTC. Public output includes rating, title, plain body, approved display name, publication date and truthful disclosure only; no email, IP, reports or moderation notes. Own output adds state, version and safe reason.

| Method and route | Authorization, request, response and audit |
| --- | --- |
| GET `/api/reviews/summary` | Approved audience; 200 count, nullable mean, five buckets, threshold, as-of. |
| GET `/api/reviews` | Approved audience; published only; rating 1–5, sort newest/oldest, opaque cursor, limit 1–50 (default 20); 200 array and next cursor. |
| GET `/api/reviews/{id}` | Approved audience; 200 published detail or 404. |
| GET `/api/me/reviews` | Session; 200 own review and status or empty array; private. |
| POST `/api/reviews` | Eligible session + CSRF + idempotency key; `{rating,title,body,incentivized,incentive_detail}`; 201 pending; audit submission. |
| PATCH `/api/me/reviews/{id}` | Owner + CSRF + key; full replacement fields and `version`; 200 pending; audit revision and visibility change. |
| DELETE `/api/me/reviews/{id}` | Owner + CSRF + key, expected `version` in body; 200 withdrawn representation; audit withdrawal. |
| POST `/api/reviews/{id}/reports` | Session + CSRF + key; bounded `{reason,explanation}`; 201 receipt; audit; no other reporters exposed. |
| GET `/api/admin/reviews` and `/api/admin/reviews/{id}` | Queue permission; allowlisted status/date/report filters, bounded cursor/limit; 200 queue or detail. Notes/reports require separate permission. |
| POST `/api/admin/reviews/{id}/decisions` | Decide permission + CSRF + key; `{action,reason_code,note,version}`; 200 state; enforce transition and audit. |
| GET `/api/admin/review-reports`; POST `/api/admin/review-reports/{id}/resolve` | Reports permission; bounded queue; resolution `{outcome,reason,version}` + CSRF/key; 200 and audit. |
| GET `/api/admin/reviews/analytics` | Analytics permission; 200 actual counts by state/rating and queue age, as-of time. |

Appeals, search, replies, votes and category APIs await approval. Cursor binds filters/sort and stable `(published_at,id)` keyset; validate or sign it, reject mismatches, deduplicate across pages and refresh after changes. Search needs approved bounded input, full-text index and tested query plans; avoid unbounded `%LIKE%`.

Mutation keys are scoped to principal/operation and stored with canonical request hash and result atomically: same payload returns original result, changed payload or in-progress key returns 409. Permanent uniqueness survives key expiry; versions protect concurrent distinct operations. All endpoints have configurable shared rate limits; 429 returns `Retry-After`. Errors: 400 malformed, 401 unauthenticated, 403 forbidden/ineligible/CSRF, 404 inaccessible, 405 with Allow, 409 duplicate/stale/transition/key conflict, 422 field validation, 429 rate limit, 503 unavailable. Do not expose SQL or private notes. Authenticated/admin responses use `Cache-Control: private, no-store`.

## 6. Reviews page UX

Reuse the dashboard layout, compact dark-green surfaces, typography, Bootstrap icons and responsive navigation. Hierarchy: breadcrumb and title; compact summary with actual count, rating and accessible five-row distribution; filter/sort controls; full-width review cards; Write review and My review where eligibility is known. Cards display title, text, star rating with accessible label, approved author name, publication date and incentive disclosure. Text bars with labels suffice; trends require meaningful real data. No badge until its exact verification claim is approved and supported.

On phones stack summary, filters and cards; verify 320px, zoom and no page overflow. Provide 44px targets, contrast, visible focus, semantic headings and labelled radio rating group with keyboard support. Submission form has labels, counter, policy/visibility notice, incentive field, inline errors, loading and duplicate prevention. On success clearly say pending, not published; retain input on failure. Announce concise updates in a polite live region. Store UTC, display local dates and identify WAT when time is shown. SSR GET filters and standard session/CSRF forms provide no-JS access, with AJAX as progressive enhancement. Dialogs require initial focus, Escape/close, focus return and full-page fallback.

Distinguish no published reviews, no filter matches, no own review, pending, ineligible, signed-out and unavailable service. Give only real sign-in/support links. A 429 explains retry timing; network errors preserve input and offer retry. Missing or removed detail shows a neutral unavailable message. Fixture mode cannot claim reviews are live.

## 7. Security, privacy and reliability

Use TLS, secure HttpOnly SameSite cookies, session rotation, CSRF/origin checks, object-level authorization, parameterized SQL and strict allowlists. Render plain text using PHP output escaping and DOM `textContent`; no uploads, HTML or automatic links initially. Set security headers/CSP, byte and character caps, and shared per-account/IP rate limits. Protect admin access and prevent enumeration. Investigate velocity and coordinated patterns as signals, not proof; never suppress genuine negative feedback. Disclose incentives under approved policy. Restrict PII in content and reports, provide a private sensitive-report route when available and avoid review bodies, credentials and unnecessary IPs in logs.

Counsel and product must settle applicable privacy/consumer obligations, lawful basis, notice, retention per record type, deletion, redaction, legal holds, encrypted backups and incident response. Define RPO/RTO, SLOs and owners before release. Monitor request errors/latency, rate-limit false positives, moderation/report backlog and age, outbox lag, aggregate mismatch and invalidation failures. Use bounded indexed queries, capacity-tested keyset pagination, reconciliation and restore drills. Fail closed on authorization or moderation failure; stale public caches must not expose removed content.

## 8. Testing and acceptance

| Feature | Measurable acceptance and verification |
| --- | --- |
| Submission | Only eligible authenticated owners create; boundaries, Unicode, empty, oversized, HTML and incentive inputs validated; parallel requests create one slot; retry is idempotent and changed payload conflicts. |
| Ownership/lifecycle | Table-driven allowed and forbidden transitions, stale versions, cross-account access, moderator permissions and CSRF tested; negative reviews treated fairly. |
| Moderation/reports | Only published revisions public; report alone does not hide; concurrent decisions have one winner; one open report enforced; actor/reason audited; independent appeal tested if enabled. |
| Aggregation | Compare count, sum, buckets and mean with direct published query after every transition/edit; test zero, threshold, rounding, concurrency, cache race and reconciliation. |
| API/privacy | Contract, error codes, limits, cursor boundaries, injection, XSS, idempotency and rate limits; public responses/caches exclude private fields. |
| UX | Test 320px, desktop, zoom, keyboard, screen reader, contrast, reduced motion, no-JS, focus, loading, pending, empty, errors and retained drafts. |
| Operations | Fresh/upgrade migration, restore, outbox retry, worker failure, cache removal, query plans, realistic load and alert/runbook drills. |
| Regression | Run existing PHP and JS suites in README; verify dashboard, predictions/API, plans, referrals, wallet and chat previews; build CSS when changed. |

Definition of Done for each enabled feature: approved rules and examples; DB/API/UI delivered as applicable; authorization and privacy controls in place; automated, accessibility and regression tests pass in CI; monitoring and runbooks exercised; documentation updated; product and security approve. Passing tests alone does not authorize release.

## 9. Dependency-aware roadmap

Implementation is authorized. Scope-dependent capabilities remain disabled until their relevant decisions and production foundations are in place.

| Phase | Objectives and deliverables | Dependencies, security, tests and completion |
| --- | --- | --- |
| 1 Discovery | Confirm scope, eligibility, policies, acceptance examples; no DB/API/UI changes. | Product, legal, security and operations review and approve scope and blueprint. |
| 2 Domain/database | Finalize state model, invariants, ERD/schema and migrations §4. | Real identity, migrations and shared audit; privacy/FK review, fresh/upgrade/restore and concurrency tests. |
| 3 Services | Repositories, validation, ownership, transitions, aggregate, audit and outbox transactions. | Phase 2; least privilege, idempotency, race and reconciliation tests. |
| 4 API/integrations | Approved §5 contracts and identity/notification boundaries. | Sessions, CSRF, RBAC, rate limits and jobs; contract, authorization and error tests. |
| 5 UI | Summary, listings, filters, forms and owner states with SSR enhancement. | Stable API and policy; escaping, responsive, no-JS and accessibility tests; no invented data. |
| 6 Admin | Queue, reports, decisions, audit and approved appeal workflow. | Roles, staffing, standards and escalation; privilege, concurrency and audit tests. |
| 7 Readiness | CI, security, accessibility, load and recovery reviews; runbook and staged feature flag. | All enabled acceptance criteria met; product/legal/security/operations sign-off and explicit launch approval. |

Risks: permanent slot may block legitimate later experiences; pre-moderation needs staffing; no current evidence supports a verified-experience badge; aggregate threshold and public names affect transparency/privacy; stale caches or races can expose removed content or corrupt counts. A live feature cannot rely on the fixture account.

## 10. Architectural decision records

All decisions are **proposed**, requiring product and relevant discipline approval.

| ID | Decision / alternative | Trade-off and owner |
| --- | --- | --- |
| R-01 | Application-only first; other subjects deferred. | Focused scope; product approves categories. |
| R-02 | Permanent one-review slot; alternative periodic or subject-specific reviews. | Prevents duplicates but restricts later feedback; product/legal decide resubmission and deletion. |
| R-03 | Human pre-moderation and independent reports; alternative automatic publication or denial. | Requires staffing; operations approves policy and SLAs. |
| R-04 | Immutable revisions and explicit states/audit; alternative mutable row. | Accountability versus storage and erasure complexity; legal approves retention. |
| R-05 | Transactional published-only projection with versioned caching and rebuild; alternative direct aggregation. | Read speed versus reconciliation complexity; engineering validates scale. |
| R-06 | Modular monolith and SSR with progressive enhancement; alternative separate service or JS-only UI. | Reuses patterns but depends on shared production foundations. |
| R-07 | No experience badge or paid gate without evidence/policy. | Honest claims; product/legal approve eligibility and labels. |
| R-08 | Defer testimonials, public SEO, responses, votes and extra categories. | Smaller release; later work needs explicit approval and consent. |

## Preview implementation

The /reviews route now renders a non-interactive preview with unavailable states. It has no review records, ratings, submission, API or moderation. The user authorized implementation; live capabilities remain dependent on the unresolved decisions and infrastructure above.
