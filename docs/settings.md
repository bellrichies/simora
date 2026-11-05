# Settings module architecture and implementation blueprint

**Status: data-driven Settings preview with browser-local editing implemented.** The user has authorized implementation; real account operations depend on the missing foundations and unresolved policies below. Existing approved decisions take precedence. See [implementation plan](implementation-plan.md), [data contract](data-contract.md), [billing](plans-billing.md), [wallet](wallet.md), [referrals](referrals.md), [support](support.md), and [reference analysis](reference-analysis.md).

## Current Settings preview

`/settings` follows the Platforms workspace with compact, legible summary cards, five deep-linkable sections and responsive forms. `SettingsPreviewService` receives `database/fixtures/settings.php`, shared app configuration and the platform catalog. Shared account name/plan remain the default identity; sample email, account reference and join date are explicitly illustrative. Unknown or malformed section inputs fall back to Profile.

Profile supports a local display name, Preferences supports a sample timezone display and a preferred platform/market shortcut, and Notifications supports four local choices. All three have independent Save/Discard controls. The saved platform and market power an actual `/predictions` shortcut; timezone changes format a sample timestamp, not the rest of the app. Summary name, timezone and enabled notification count derive from validated state. Email, currency and sample account metadata are read-only. Security accurately reports missing password/MFA/session services.

`settings.js` stores validated preferences under `simora-settings-preview-v1` in localStorage. Invalid stored enum values fall back to configured defaults; displayed values use textContent or native form values. Save failures explicitly report visit-only changes. Privacy can download these preview preferences as JSON or reset only this storage key. This is not a real account export or deletion workflow; unrelated page storage remains intact. No API, identity mutation or notification delivery is enabled. Without JavaScript, section navigation and sample details remain available and editing fieldsets stay disabled to prevent accidental form submission.

Validation: `node tests/settings-page.cjs` covers saves across navigation, name validation, discard, timezone/shortcut consumers, notifications, export/reset isolation, storage failures, all five sections at 900/390/320px plus desktop, and no-JavaScript fallback. PHP syntax and service checks cover fixture/config/account consistency and section allowlisting.

## 1. Assessment and evidence

| Status | Finding |
| --- | --- |
| Confirmed task scope | Plan an authenticated account settings module; keep user and administrator settings separate; no duplicate financial workflows, wallet transfers or controls without functional consumers. |
| Implemented | The application is a PHP SSR preview with Tailwind, progressive JavaScript, dashboard shell and mobile navigation. `public/index.php` dispatches the prediction API and dashboard controller. PHP services and repository contracts offer reusable boundaries. `config/app.php` fixes Africa/Lagos, NGN and default market; `database/fixtures/account.json` is a shared preview account. Platform data is synthetic. |
| Missing | No real identity persistence, registration, authentication, session/CSRF, settings mutations, notification delivery, consent, audit, queue or lifecycle infrastructure was identified. `database/schema.sql` is an unapplied prediction reference, not a migration history. Plans, wallet, referrals and support are previews with separate future blueprints. No separate approved SRS or ADR directory was found. |
| Proposed | Settings mutations, endpoints, data structures and workflows below remain unimplemented. The `/settings` preview displays fixture status, section navigation and explicitly browser-local preference editing. Never import fixture identity as an actual account. |

**Decision register:** Product and identity must approve profile fields, username/phone, verification, MFA, recovery and deactivation. Analytics must approve actual preference consumers and defaults. Product, communications and legal must decide channels, classification, consent and notices. Billing, finance and referrals must decide subscription cancellation, unsettled payments, wallet credits and reward treatment on closure. Legal must approve jurisdiction, lawful bases, retention, holds, rights procedures and export scope. Security and operations must set step-up duration, rate budgets, session policy, roles, incident response, backup objectives and support identity checks. Unresolved capabilities remain unavailable.

## 2. Functional scope and information architecture

Proposed authenticated `/settings`: **Profile**, **Security**, **Preferences**, **Notifications**, **Privacy & account**. A compact billing summary links to Plans & Billing when live. Wallet and referral settings are limited to supported notification preferences. Support and legal links appear when real destinations exist. Each section loads and saves independently.

| Category | Proposed behavior, ownership and constraints |
| --- | --- |
| Profile | Identity owns display name, optional first/last name and read-only creation date. Proposed display name is trimmed and 1–80 Unicode characters; optional names are at most 80. Email is read-only in ordinary editing and changes through verification. Username, phone, country, avatar and completion score require approval and real consumers. |
| Security | Identity owns password, email verification, recovery and sessions. Require step-up for password/email changes and revocation. MFA, phone verification, security activity and device details are conditional on identity capabilities and approved policy. |
| Preferences | Proposed IANA timezone, default `Africa/Lagos`; nullable preferred platform and league from the active catalog, with league belonging to platform. Consider default market only when the board consumes it. Theme, density, language, landing page and saved configurations are deferred until supported. |
| Analytics | Preferences affect presentation and filters only, never authoritative results, settlement or probabilities. Explicit selections override saved defaults for the current interaction. On unavailable catalog values, use an approved fallback and explain it; retention/clearing policy needs approval. |
| Notifications | Show only implemented category/channel combinations. Security and essential transactional communications are classified by the server; optional promotional email is proposed off by default pending policy. Legal must determine lawful bases and mandatory classification. |
| Privacy | Purpose-specific consent, published notices and retention information, plus export/deletion requests and status. Deactivation is conditional. No public visibility toggle without a public-profile capability. |
| Billing, wallet, referrals | Billing owns subscription, payment methods, invoices and cancellation. Wallet owns credit; referrals owns rewards and attribution. Link to live module workflows; no balance mutation here. Closure effects need approved rules. |

Precedence: current explicit UI selection > supported saved preference > published global default > safe application fallback. Null means inherit. Global defaults are versioned and administrator-controlled; user preferences never override entitlement or security policy. Store UTC and use timezone only for display.

## 3. UI and user journeys

Reuse the centered dashboard shell, breadcrumb, dark/green theme and compact controls. Desktop uses a concise identity header, vertical section navigation and one content panel. Mobile uses accessible, deep-linkable section navigation and stacked forms without horizontal overflow. Use semantic headings, labels and fieldsets, visible focus, adequate contrast, keyboard access, 200% zoom and WCAG 2.2 AA as a review target. Avoid oversized headers and excessive cards.

Profile editing distinguishes editable and verified fields, validates inline and on submit, preserves input on failure, announces success, and provides cancel and unsaved-change protection. Each section has independent loading and saving states. Skeletons are labeled; empty, unsupported and unavailable states explain what is possible. A network failure preserves edits; 401 leads to sign-in, 403 gives a safe explanation, 429 shows retry guidance, and stale updates offer reload or deliberate merge. Dialogs manage focus, Escape where safe and return focus. Server-rendered forms provide a fallback where practical.

Email change requires step-up, then a one-use verification link to the proposed address and a notice to the old address. The existing address remains authoritative until confirmation atomically rechecks expiry, ownership, uniqueness and account state. Notify both addresses after commit. Password changes verify the current credential or approved step-up, update the hash and session policy, and notify the user. Session lists are owner-scoped and mark the current session; revocation explains consequences. Export and deletion are tracked requests, not promises of immediate destructive action. Sensitive confirmations describe consequences and require step-up.

## 4. Domain, services and persistence

Identity owns profiles, credentials, verification and sessions. Settings owns supported presentation preferences; notification delivery owns enforcement; privacy owns consent evidence and rights requests. Billing, wallet, referrals and support retain their authoritative records. Controllers validate syntax, derive the actor from the session, call application services and map typed errors. Inject repositories, clock, authorization and transaction manager; use prepared PDO statements. Extend existing identity services when available rather than duplicate them. Suggested responsibilities: profile, preferences, notification policy, privacy requests, sessions and lifecycle orchestration. No external call occurs inside a database transaction.

Proposed MySQL/InnoDB entities, following identity and catalog foundations:

| Entity | Relationships and constraints |
| --- | --- |
| Identity-owned `users`, `user_profiles` | Unique normalized verified email; one profile per user, FK and version. Identity owns normalization, credentials and account status. |
| `user_preferences` | PK/FK user ID; nullable timezone, platform, league and approved market; version and updated UTC timestamp. Validate active catalog and platform/league relationship transactionally. Catalog retirement FK policy needs approval. |
| `notification_preferences` | User FK, registered category, channel, enabled, version, updated time; unique `(user_id,category,channel)` and user index. Mandatory delivery has no mutable opt-out row. |
| `consent_events` | Append-only ID, user FK, purpose, decision, notice version, lawful-basis reference, source and UTC time; index `(user_id,purpose,recorded_at,id)`. Withdrawal is a new event; any current projection is rebuildable. |
| `email_change_requests` | Opaque ID, user FK, proposed address, token hash, expiry, consumed/revoked time and version; index user/state and expiry; enforce one active request under a lock/guard. Never store plaintext tokens. |
| Identity-owned `user_sessions` | Opaque ID, user FK, secure session reference or token hash, created/last-seen/expiry/revoked times and minimal metadata; index `(user_id,revoked_at,expires_at)`. No raw secret. |
| `privacy_requests` | Opaque ID, owner FK, type, status, timestamps, verification, handler, reason code, version and protected artifact reference; indexes `(user_id,created_at,id)` and `(status,created_at,id)`. |
| Shared audit/outbox | Actor, target, action, result, UTC time and correlation; minimal event ID/type/version and delivery state. Unique consumer/event inbox for deduplication. Exclude secrets and unnecessary PII. |

Use typed columns and a validated category registry rather than an unstructured settings blob. Migrate additively after foundational identity: preferences, delivery registry, consent/requests, then optional verification/session features. Backfill real accounts only with inherited defaults, never fixture accounts. Verify counts and restore before cleanup; do not cascade-delete financial or legal records. Use versioned compare-and-swap; commit related settings, audit and outbox atomically. Distributed services later require an explicit saga and reconciliation.

## 5. Security and authorization

Use HTTPS and server sessions as the proposed web default: Secure, HttpOnly, SameSite cookies, rotation on login, expiration, CSRF tokens and Origin checks for mutations. JWT/refresh-token design is deferred until a distinct client requires it. Derive owner from authenticated session for every `/me` operation; scope object queries by owner and return 404 for inaccessible IDs. Allowlist mutable fields and reject unknown properties. Administrative operations require explicit separate permissions and audit; staff cannot view passwords, raw tokens, MFA secrets or exports by default.

Step-up is an action-scoped, short-lived server-held proof bound to account and session; method and lifetime require security approval. Apply to email/password changes, session revocation, MFA changes and lifecycle requests. Use PHP `password_hash` with reviewed Argon2id parameters where supported, `password_verify` and rehash; never store reversible credentials. Generate high-entropy, scoped, expiring one-use tokens, store only hashes, and use configured trusted HTTPS origins. Apply shared per-account and network limits to reads, mutations and especially verification, with budgets set and monitored before release. Generic responses prevent enumeration. Validate and escape output, avoid secrets in logs and security events, and test mass assignment, IDOR, fixation, CSRF and replay. Avatar uploads require a separate approved MIME/signature, size, dimensions, re-encoding and storage design.

Security changes commit audit and notification intent with the update. Workers retry idempotently; delivery failure never rolls back committed security changes. Session revocation must be checked against central state on each authenticated request or meet a documented bounded propagation guarantee.

## 6. Proposed REST contract

All routes below are unimplemented and conditional on their owning feature. HTTPS, session authentication and ownership apply to every route; mutations also require CSRF and Origin checks. New JSON uses `{ "data": ..., "error": null, "meta": { "request_id": "opaque" } }`; error is `{ "data": null, "error": { "code": "VALIDATION_ERROR", "message": "Please check the fields.", "details": {} }, "meta": { "request_id": "opaque" } }`. Existing prediction responses remain unchanged. Use ISO 8601 UTC, opaque IDs, bounded bodies and `Cache-Control: private, no-store`.

Versioned reads return strong ETags; updates require `If-Match`, with 428 if missing and 412 if stale. POST commands require a bounded `Idempotency-Key` scoped to actor and operation, persisted with canonical body hash and outcome: identical replay returns the prior result, changed body or in-progress operation returns 409. Single-use verification cannot repeat side effects. Rate-limit all routes per user and sensitive actions also per network; budgets are approval-gated. Mutations and sensitive accesses emit the indicated audit event.

| Method and route | Request, response and special validation | Audit |
| --- | --- | --- |
| GET `/api/me/profile` | 200 `{display_name,first_name,last_name,email,email_verified,created_at,version}`. | Sensitive read per policy |
| PATCH `/api/me/profile` | Allowlisted profile fields, `If-Match`; 200 updated, 422 invalid; no email field. | `profile.updated` |
| POST `/api/me/email-change-requests` | `{email,step_up_proof}`; 202 generic, key required; verification intent. | `email_change.requested` |
| POST `/api/me/email-change-confirmations` | `{token}`; 200 generic or 422 expired/invalid; single use, initiating user bound; cross-device flow needs identity design. | `email_change.confirmed` |
| POST `/api/me/password-changes` | `{current_password,new_password,step_up_proof}`; 204; enforce password and session policy. | `password.changed` |
| GET `/api/me/sessions` | 200 bounded owner list with opaque ID, current marker, times and coarse client label if available. | Access per policy |
| DELETE `/api/me/sessions/{id}` | Step-up; 204 for owned session including already revoked; 404 inaccessible. Revoking current session signs out. | `session.revoked` |
| POST `/api/me/sessions/revoke-others` | Step-up and key; 200 `{revoked_count}`; retain current session. | `sessions.revoked` |
| GET `/api/me/preferences` | 200 `{timezone,platform_id,league_id,market_id,version,defaults_version}`; null inherits. | None |
| PATCH `/api/me/preferences` | Allowlisted subset and `If-Match`; validate IANA zone, active IDs and league/platform relation; 200 updated. | `preferences.updated` |
| POST `/api/me/preferences/reset` | `{fields:[...]}`, `If-Match` and key; clear overrides; 200 resolved values. | `preferences.reset` |
| GET `/api/me/notification-preferences` | 200 supported matrix, classification, effective values and version. | None |
| PUT `/api/me/notification-preferences` | Complete optional matrix and `If-Match`; reject unsupported or mandatory changes; 200. | `notifications.updated` |
| GET `/api/me/consents` | 200 registered purposes, latest decisions and notice versions. | Access per policy |
| POST `/api/me/consent-events` | `{purpose,decision,notice_version}` and key; explicit registered purpose; 201 current result. | `consent.changed` |
| POST `/api/me/privacy-requests` | `{type:"export"|"deletion",step_up_proof}` and key; 202 `{id,status}`; one active request per type/owner under lock. | `privacy.requested` |
| GET `/api/me/privacy-requests/{id}` | 200 owner-scoped status; export download needs separate authenticated, short-lived authorization. | Access per policy |

Common errors: 400 malformed/unknown fields, 401 unauthenticated, 403 forbidden/CSRF/step-up, 404 absent or inaccessible, 409 state/idempotency conflict, 412 stale, 422 invalid input, 428 missing precondition, 429 with `Retry-After`, 503 unavailable. MFA, phone, avatar, deactivation, security activity and admin APIs require separate approved contracts. No user route can mutate global configuration.

## 7. Notifications, lifecycle and governance

Producers emit minimal stable IDs through a shared outbox. Delivery resolves current category policy, verified destination and consent immediately before sending, including retries; consumers deduplicate per event/channel and record safe outcomes. Mandatory messages bypass optional preferences only when classification and lawful basis are approved. Withdrawal suppresses queued optional messages not yet handed to the provider; provider handoff requires documented handling. If policy is unavailable, fail closed for optional marketing and retry/alert for essential notices. Preference caches are private, short-lived and invalidated by user/version after commit; never publicly cache accounts or use cache for authorization.

Proposed transitions: `active → deactivation_requested → deactivated → active` only with approved reactivation checks; `active/deactivated → deletion_requested → review → approved → processing → completed`, or `rejected/cancelled` with reason. Suspension remains identity/admin-owned. Before deletion, check billing cancellation and pending payments, wallet reservations/credit policy, referral obligations, support records, holds and retention. Revoke sessions at the approved transition; remove or anonymize data on an approved schedule while restricting minimal legitimately retained records. A hold or unresolved transaction goes to review and does not automatically deny statutory rights. Export is queued, assembled from authorized sources, reviewed for third-party data, encrypted and delivered through authenticated expiring download; artifacts are destroyed per approved schedule. Provide a support identity-proofing fallback for users unable to sign in.

Separate administrative permissions for verification view, security events, restrictions, privacy review, export handling, configuration draft/publish and audit. Record actor, target, reason and result; review access. Version published defaults/options and assign an owner; users cannot modify them. Dual control and emergency access need policy. Monitor request age, delivery failures, queue lag, denied access, conflicts and abnormal verification attempts; approve thresholds, incident owners, RPO/RTO and restore drills.

## 8. Privacy and data protection

The [Nigeria Data Protection Act 2023](https://ndpc.gov.ng/wp-content/uploads/2024/03/Nigeria_Data_Protection_Act_2023.pdf) sets obligations and rights where applicable; its consent provisions require affirmative consent and allow withdrawal, and notice provisions address purpose, lawful basis and retention. The [NDPC 2025 General Application and Implementation Directive](https://ndpc.gov.ng/wp-content/uploads/2025/07/NDP-ACT-GAID-2025-MARCH-20TH.pdf) provides implementation guidance. Legal counsel must determine current applicability, territorial scope, rights deadlines, lawful bases, retention, transfers, breach duties and registration obligations. Do not assume consent is the basis for every processing purpose or that all records must be erased. Technical recommendations: collect minimum necessary data, document purposes and retention, protect keys and backups, restrict access, review processors, and test export, deletion and restoration without reintroducing erased data. No statutory retention period is assumed.

## 9. Testing and acceptance

| Capability | Acceptance and definition of done |
| --- | --- |
| Profile/email | Allowed edits persist across sessions; invalid and unknown fields cause no change; stale updates return 412. Expired, duplicate and replayed tokens cannot change identity; old email remains until verified; audit and notification intent commit with change. |
| Security | Wrong password/step-up cannot mutate state; cross-user session access fails; revoked sessions cannot authorize beyond the approved propagation bound. CSRF, throttling, fixation, replay and secret-redaction tests pass. |
| Preferences/analytics | Values persist across two sessions; invalid platform/league combination fails; retired values follow approved fallback. Every displayed preference changes a real consumer without altering historical/model facts. Concurrent updates produce a recoverable conflict. |
| Notifications/consent | Supported matrix round-trips; mandatory categories cannot be disabled; approved defaults and withdrawal apply to queued retries; duplicate events cause no duplicate logical delivery. Consent evidence retains decision and notice version. |
| Lifecycle/admin | Requests are owner-scoped, idempotent and status-visible; holds and financial/referral dependencies are evaluated without erasing ledger. Only approved roles review; sensitive actions audit. Restore and re-erasure are exercised. |
| UI/integration | Keyboard, screen reader, focus, labels, contrast, zoom, mobile/desktop, loading, errors and unsaved changes verified. End-to-end journeys and billing/wallet/referral/support/prediction regression tests pass. |

Automate unit validation and authorization, MySQL integration tests for constraints, concurrent writes and outbox atomicity, API contract tests, browser accessibility and end-to-end flows. Exercise provider failure, queue retry, migration rollback and backup restore. Release requires reviewed scope, all applicable acceptance criteria, operational owners, privacy/security reviews, monitoring and staged rollback; unsupported capabilities stay hidden.

## 10. Phased implementation roadmap

Security, privacy and accessibility run throughout; live capabilities require their documented dependencies and policy decisions.

| Phase | Objectives and engineering work | Dependencies, deliverables and completion gate |
| --- | --- | --- |
| 1 Discovery | Validate evidence, scope, journeys and decision register with product, finance, legal, security and operations. | No DB/API/UI change. DoD: signed scope, owners and unresolved feature gates. |
| 2 Domain/IA | Approve navigation, ownership, defaults, entities, migration plan and threat model; review prototypes. | Phase 1. Designs only. DoD: reviewed invariants, data map, API and accessible UX. |
| 3 Backend | Implement approved identity integration, repositories, preferences, concurrency, audit/outbox and migrations. | Identity and DB foundations. DoD: transaction, race and authorization tests pass. |
| 4 API/integrations | Implement approved owner-scoped contracts and real catalog, notification, billing and support boundaries. | Phase 3, session/CSRF and producer readiness. DoD: contracts, idempotency and integration tests pass. |
| 5 UI | Implement approved responsive forms, SSR fallback and supported controls, with loading and conflict recovery. | Phase 4 and UX approval. DoD: browser, accessibility and end-to-end tests pass. |
| 6 Security/privacy | Deliver approved verification, step-up, session, consent and rights workflows in parallel with earlier phases. | Identity, queue, legal and policy decisions. DoD: threat review and privacy workflow drills pass. |
| 7 Readiness | Finish docs, monitoring, load/restore tests, incident runbooks and staged release plan. | All enabled feature gates. DoD: acceptance matrix, regressions, operational thresholds and explicit launch authorization. |

## 11. Proposed architectural decisions

| ID | Decision and trade-off |
| --- | --- |
| S-01 | Reuse identity and existing domain owners; a parallel account or billing store risks conflicting truth, but rollout depends on shared foundations. |
| S-02 | Typed relational preferences and a bounded category/channel table; arbitrary JSON is flexible but weakens validation and discoverability. |
| S-03 | Session/CSRF for SSR web; defer JWT until a distinct client and token lifecycle justify it. |
| S-04 | Conditional versioned updates and idempotent commands; last-write-wins is simpler but can silently lose cross-device changes. |
| S-05 | Purpose-specific consent evidence and send-time enforcement; a single marketing boolean cannot cover lawful bases or queued delivery. |
| S-06 | Reviewed rights-request state machines and no cascading financial deletion; actual retention and exceptions require legal approval. |
| S-07 | Modular in-process services with transactional outbox; a broker adds unnecessary operations before demonstrated need. Distribution needs saga and reconciliation. |
