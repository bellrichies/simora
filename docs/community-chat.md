# General community chat widget

## Phase 11 implementation and launch boundary — 2026-11-06

The [master V4 blueprint](SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md) is authoritative; earlier suggested extra event/report tables and unversioned API paths below are historical proposals. Preserve the **shared general community discussion** scope. The user's suggested authenticated support chat and Max priority routing are not approved replacements. Support remains the private ticket workflow.

Optional backend infrastructure now uses only canonical `chat_rooms`, `chat_memberships`, `chat_messages` plus protected shared audit records. It is disabled by default, with no seeded room/messages or transmission from the existing widget. Enabling requires explicit unexpired policy/privacy/moderation/retention references, **named moderation and privacy owners**, reporting/abuse-response and rights/deletion procedures, deliberate permissions and room opening. Proposed 90-day retention remains subject to approval; no automatic retention/erasure schedule is invented.

The server enforces verified authors, explicit current-policy membership consent, independent human premoderation, no author names/IDs in the normal feed, protected reporting without reporter disclosure, staff bans/mutes that rejoining cannot reset, bounded plain text, no rich links/uploads, shared transactional rate limits and audited state changes. Pending/hidden content is excluded from normal snapshots. Snapshot clients must replace their rendered visible window; there is no event-sequence/reconnect protocol or enabled live widget yet. Reports do not automatically hide content or imply a fraud finding.

No direct messages, per-user block list, automatic account punishment, AI responses, external notifications or SLA is implemented. Members can report; deletion/privacy requests use owner-bound support tickets categorized `privacy_security`, restricted to approved privacy staff. Staffed reporting/appeal workflow, per-user blocking design if required, retention/rights execution, edge flood controls, security/load and live integration checks remain launch gates. See [Phase 11 report](architecture/experience-phase.md). Demo controls remain labeled and non-transmitting.

Historical preview assessment: the shared floating widget is local and non-persistent. The gated backend added in Phase 11 is described above; the historical design below is not a claim that those services or launch policies were already operational. Product policy choices marked **Decision needed** remain unresolved before release.

## Goal and scope

Give users one shared place to discuss their experiences with the application. A floating chat button appears at the bottom right on dashboard pages, and opens a collapsible conversation panel without navigating away. Messages belong to a single general room shared across Overview, Predictions and the platform boards; changing platforms does not change the conversation.

The first release is text-only: read, send, report and moderate messages. No direct messages, per-platform rooms, attachments, links with rich previews, reactions, mentions, typing indicators, online presence or AI responses are included. Plain-text URLs may be displayed as text; do not automatically make them clickable in the initial release. Chat is not customer support; keep the existing Support destination available.

## Current application and delivery boundary

The application renders PHP views through `public/index.php` and `views/layout.php`; it uses local JSON fixtures, a fixture account and no real sign-in, persistent users or chat service. Existing chat references in the implementation plan describe proposed or observed reference behavior, not an implemented feature. Do not treat the fixture account as an authenticated user or expose fixture messages as real conversations.

A local UI preview may use clearly identified, non-persistent sample messages and a disabled composer explaining that sign-in and chat are not yet available. Real shared messaging requires persistent accounts, authentication, authorization, CSRF protection, database storage and operational moderation first. The feature must not claim to be live or send messages to a third party in preview mode.

## User experience

### Entry and panel

- Place one floating action button (FAB) at the bottom right in the shared dashboard layout, above page content and outside scrolling containers. Use an existing local Bootstrap Icons chat symbol with the accessible name **Open community chat**; include a visible text label where space permits. The FAB toggles the panel, exposes `aria-expanded` and `aria-controls`, and is not injected more than once per page.
- Default to collapsed on first visit. Opening shows **Community chat**, a brief purpose (“Discuss your experience with Simora”), a messages region, composer and a labelled collapse button. Closing or Escape collapses the panel, stops background polling and returns focus to the FAB. Opening moves focus to the panel heading or composer as appropriate; the non-modal desktop panel does not trap focus.
- Keep the widget anchored and avoid covering existing controls, dialogs, navigation, countdowns or primary actions. Suspend background interaction/polling when an existing modal is open; existing modal focus management takes priority. Respect safe-area insets and virtual keyboards. On narrow screens use a near-full-width, height-constrained panel with its own message scroll area; page controls must remain reachable. Verify 320 px width, zoom and landscape.
- Use the existing dark-green surfaces, borders and accent; maintain readable contrast, visible focus and targets of at least 44 × 44 CSS pixels. Respect reduced-motion preferences. Do not rely on colour alone for connection or send status.
- The FAB and panel state are local UI state. Within a page, collapsing retains an unsent draft; optional session-scoped restoration across navigation must contain only the draft and open state and must clear at sign-out. Do not persist message bodies in local storage. Cross-tab unread counts are not part of the first release.

### Conversation

- Show messages oldest to newest with display name, body and a timestamp with an accessible full date/time. Store timestamps in UTC and display in the configured application time zone, identified as WAT. Preserve line breaks and render message bodies as text, never HTML. Clearly distinguish the user's own messages without exposing private account data.
- On opening, fetch the latest page. “Load earlier messages” fetches older entries while preserving the reader's scroll position. New messages append at the bottom. Auto-scroll only when the user is already near the bottom or has just successfully sent; otherwise show a **New messages** control and preserve reading position.
- The panel has labelled loading, empty, signed-out, connecting, offline/reconnecting, failed-load, failed-send and rate-limit states. Screen readers receive concise status updates through a polite live region; do not announce the entire history or repeatedly announce polling. When collapsed, do not display an invented unread count; add unread counts only after a reliable per-user read model is designed.
- Composer: labelled multiline textbox, character counter, **Send** button, clear error and preserved draft. Proposed limit: 1–1,000 Unicode characters after trimming; enforce consistently on client and server. Enter inserts a newline; the Send button submits, with Ctrl/Cmd+Enter as an optional shortcut. Disable duplicate submission while one request is pending.
- Pending messages are visibly marked **Sending**; success replaces the pending entry with the authoritative server message. Failure retains the draft/message with **Retry** and never implies delivery. Reuse the same idempotency key on retry. A signed-out user can open the panel and see a sign-in prompt; whether anonymous visitors may read is a policy decision, not an assumed current capability.

## Message flow and API proposal

All paths and fields below are proposed application contracts, not existing endpoints. Use same-origin HTTPS, structured JSON `{data, error, meta}`, server-side authorization and `Cache-Control: private, no-store` for chat responses. Use a dedicated controller, service and repository behind interfaces; keep the existing predictions endpoint unchanged. Configure limits and polling centrally.

1. Open: request `GET /api/chat/messages?limit=30` for the latest visible messages, ordered ascending in the response. Include the server-generated cursors for older and newer messages. Render an empty state when there are none.
2. History: `GET /api/chat/messages?before=<opaque-cursor>&limit=30` returns the preceding page. The cursor represents a stable server ordering (monotonic message sequence plus ID where needed), is validated and bounded, and must not allow cross-room access. Cap limit at 50. Return `meta.has_more_before`, `meta.before_cursor` and `meta.after_cursor`.
3. Updates: `GET /api/chat/messages?after=<opaque-cursor>&limit=50` returns subsequent changes in ascending sequence, including visible new messages and moderation removals/tombstones. Return a continuation cursor and a `has_more_after` flag; drain bounded pages before normal polling resumes. A durable event/change sequence is required so removals of older messages are propagated; a message creation cursor alone is insufficient. Clients deduplicate by message ID and event sequence. Reconcile after reconnect and reload; if a cursor has expired, return a documented reset response and reload the latest page.
4. Send: `POST /api/chat/messages` accepts `{body, client_message_id}`, authenticated session and CSRF token. The server derives author and general room; never trust client-supplied identity or role. Validate and persist within a transaction with a unique `(author_id, client_message_id)` constraint. Identical retries return the original message; reuse with a different body returns 409. Return 201 for a new message and 200 for a duplicate. Reconcile the POST result and later polling event by server ID.
5. Report: `POST /api/chat/messages/{id}/reports` accepts a bounded reason code and optional bounded explanation, requires authentication and CSRF, and responds without exposing reporter identity to other users. Prevent duplicate open reports from the same account/message; do not reveal report counts in the public feed.

A message representation contains `id`, `author: {id, display_name}`, `body`, `created_at` and a server-derived `is_own`. Event representations contain `sequence`, `type` and a message or removal identifier. Deleted or moderated content must not reappear in ordinary history or updates. Public responses never include email, IP address, moderation notes or raw reports.

Use 400 for invalid requests/cursors, 401 for unauthenticated requests where authentication is required, 403 for forbidden actions or suspended accounts, 404 for inaccessible messages, 409 for idempotency conflicts, 422 for invalid message content, 429 with `Retry-After` for rate limiting, and 503 for temporary service failure. Error bodies contain safe user-facing codes/messages, not stack traces or raw SQL.

## Update strategy and performance

Start with short polling while the panel is open and the page is visible: propose every 10 seconds, with at most one in-flight request, an immediate catch-up when returning online or visible, and exponential backoff with jitter after errors (cap at 60 seconds). Pause when collapsed, hidden or offline; abort in-flight reads on collapse/navigation. Never poll on unrelated pages when the panel is closed. Use cursor-based indexed queries with bounded batches and render only a bounded recent window; loading older history must not degrade page performance.

Preserve the existing fast server-rendered initial page: render the FAB and small panel shell without loading chat history, initialise the widget script with `defer`, and fetch messages only when opened. No external realtime dependency is required for the initial release. If measured usage justifies SSE or WebSockets later, retain the same authorization, event ordering, reconnect, backpressure and moderation guarantees. Set and measure latency/load targets against deployment capacity before release; polling frequency and limits are configurable, not contractual promises of real-time delivery.

## Architecture and data

- **Presentation:** shared layout partial and scoped CSS; small, isolated JavaScript module controls panel state, polling, optimistic send, pagination and accessibility. Use DOM APIs/`textContent`; never interpolate messages into HTML. Progressive enhancement supplies an explanatory fallback or an accessible chat page when scripts are unavailable; a functioning non-JavaScript read/send flow is required before claiming full progressive enhancement.
- **Application:** router and authentication/CSRF middleware; thin `ChatController`; `ChatService` for validation, authorization, ordering, idempotency and moderation; repository interfaces with database-backed implementations injected at composition. Shared account display data must not be a source of authority.
- **Storage:** add migrations for a general room, messages, change events, reports and moderation/audit actions after an actual user/auth schema exists. Use MySQL/InnoDB, foreign keys and UTC timestamps. Suggested indexes: messages `(room_id, sequence)`, events `(room_id, sequence)`, unique author/client idempotency key, and reports `(message_id, reporter_id, state)` with a design that enforces one open report. Use transactions for message/event creation and moderation/event updates. Use stable, opaque IDs at the API boundary; monotonically increasing internal event sequences support catch-up.
- Include migration rollback guidance and retention/deletion procedures. `database/schema.sql` is a reference prediction schema, not an existing deployed database; do not silently add live chat tables to it as if they are implemented. A database-backed adapter should paginate in SQL and not load full history into memory.

## Safety, privacy and operations

Validate authentication and account status on every request; authorize report and moderation operations server-side. Apply CSRF to session-authenticated writes, secure/HttpOnly/SameSite cookies, origin checks where appropriate, input size limits, output escaping and per-user/IP rate limits with a shared store in multi-instance deployments. Suggested starting limits such as 10 messages per minute and 5 reports per hour require tuning and cannot replace abuse controls. Record metadata needed for abuse response without logging message bodies, credentials or unnecessary personal data.

Before enabling public chat, establish community rules, reporting triage and a moderator workflow. Authorized moderators can hide/remove messages and restrict abusive accounts; every action is audited with actor, reason and time, and visibility changes emit events for open clients. Limit access to reports and moderation logs. Plan for spam, harassment, unsafe links, sensitive personal information and removal requests. Do not promise moderation coverage or response times until staffing is agreed.

**Decision needed before release:** minimum account/age eligibility; whether signed-out visitors can read; community rules and enforcement; retention periods for messages, reports and audit records; deletion and account-removal behavior; moderator roles, staffing and escalation; rate limits; legal/privacy notices. Use least-privilege access and document privacy disclosures appropriate to the deployment. Keep chat data out of public caches and avoid storing API secrets in source control.

## Implementation sequence

1. **Product and operational decisions:** approve policy choices, roles, retention and moderation ownership; settle UX copy and access rules.
2. **Foundation:** implement or integrate real accounts/session auth, CSRF, database connection and migrations; set up authorization, logging and shared rate limiting.
3. **Backend:** implement repository/service/controller, message/event transactions, cursor pagination, idempotent send, reporting and moderator controls; document OpenAPI or equivalent contracts.
4. **Widget:** add responsive FAB/panel, accessible interaction and states, polling/reconnect, scroll behavior, safe text rendering and request cancellation. Keep the UI usable while other dashboard controls are open.
5. **Verification and rollout:** run automated and browser tests, accessibility and security reviews, migration and load tests, then enable behind a configuration flag for a limited rollout with monitoring and an immediate disable path.

## Acceptance and verification

- FAB appears once on dashboard pages, opens/collapses by mouse and keyboard, restores focus, respects existing dialogs and is usable at mobile/desktop sizes. The panel does not obscure essential controls or produce horizontal overflow.
- Authorized users in separate sessions see the same general-room messages. Reload, navigation, pagination, background/foreground transitions and reconnect preserve correct order without duplicates or missed updates; moderation removals propagate. Only authorized users can send/report.
- Validation handles empty, whitespace, oversized, Unicode and malicious HTML/script input; output remains inert. Duplicate submissions/retries produce one message, including lost-response and concurrent retries. Rate limits and CSRF failures return appropriate errors and do not lose drafts.
- Failed requests retain user input; polling stops while hidden/collapsed/offline, resumes with bounded backoff, and remains single-flight. New messages do not forcibly scroll a reader viewing history. Screen-reader announcements are concise.
- Test repository cursor boundaries and deletion events, service authorization/idempotency, API envelopes and rate limits, UI state/keyboard/scroll behavior and responsive browser layouts. Include two-session end-to-end tests, accessibility checks, migration/rollback tests and measured load tests. Verify no chat content is stored in public caches or exposed via logs.

## Implemented preview

The shared layout includes the floating button and collapsible panel through `views/components/community-chat.php`, with `public/assets/community-chat.js` and scoped styles. It opens and closes by keyboard, returns focus on collapse and yields to existing dialogs. The disabled composer clearly identifies messaging as unavailable. No message requests, polling or storage are performed. Run `node tests/community-chat.cjs` for interaction checks. Live chat still requires the foundation, backend and operational decisions described above.
