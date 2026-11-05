# Implementation plan

**Settings:** See the [Settings blueprint](settings.md) for discovery, account settings and prerequisites. A read-only page preview is implemented; live account settings require the identity and other foundations described there.

**Wallet planning:** See the [Wallet blueprint](wallet.md) for the restricted-credit model, ledger, dependencies and approval gates. It supersedes older wallet/payout sketches below. A non-interactive wallet preview is implemented; live credit operations depend on the documented policy decisions and foundations, and payouts are not approved.

**Referrals update:** See the [Referrals blueprint](referrals.md) for the current assessment, proposed architecture, open business decisions and phased delivery. A non-interactive page preview is implemented; live functionality depends on the unresolved decisions and foundations documented there.

**Plans & Billing planning:** See [the Plans & Billing blueprint](plans-billing.md) for the current assessment, confirmed plan names, open decisions and phased design. It supersedes the generic billing proposals below; implementation requires explicit approval.

**Community chat update:** The [community chat plan](community-chat.md) specifies the proposed shared floating widget, API, moderation and prerequisites. It supersedes the generic chat proposals in this historical plan; the widget shell is available as a non-persistent preview, but live chat is not implemented.

Current data implementation: [data-contract.md](data-contract.md) supersedes earlier fixture counts, fixed percentages, exhausted quota and static explanation examples below. Versioned normalized JSON now drives platform boards, shared account information and Overview statistics. A deterministic CLI generator creates synthetic 85â€“90% winning demo histories; these are explicitly labelled outcome-selected examples. The future repository/database handoff is described there, with a reference schema in `database/schema.sql`.

## Scope and evidence conventions

Build a responsive, server-rendered PHP application with progressive enhancement for the public platform selector, signed-in overview and bookmaker prediction board. Other linked pages are inventory items whose contents and behavior require discovery. No implementation has been done under this plan. The two source documents are [reference analysis](reference-analysis.md) and [search workflow](search-workflow.md).

Evidence keys used below:

- **P** â€” public free-picks page DOM and screenshot: `ev_c0d24a625c5a27bb027feb29806d8bf896184a2a202d778cba3c57838221b396`, `ev_4b51aa53382f19166a88ce893b51a8f177454500b4e300919896927d83936a85`.
- **O** â€” signed-in overview DOM and screenshot: `ev_5f20fa864b1398d58c2155e41e09fa858ee29013a2f22647042cec6f8f384014`, `ev_d9e03f4ad567c1d4c7f2a064d0dfb6536395c8cc0a935d241488da3c3e2e9a5a`.
- **B** â€” SportyBet DOM, accessibility, delivered scripts and screenshot: `ev_a317ed76a01c90e91d787db3d6cc4ecdd7ed43513ca57cc94d6030078062c274`, `ev_edf1df5314d7490fb6b8e03bb0d9e0886f1715cad7a09cf6d059f3423206f33c`.
- **W** â€” later passive network and page snapshot: `ev_9c6671cb615e69ddf9a743bb036478dcf0669d3b47b88f43e73e7590ac8c57cb`; workflow limitations and other captures are documented in [search workflow](search-workflow.md).

**Design required** marks proposed behavior, schema, routes or policy that is not established by the reference. Evidence of a link or script is not evidence that its destination, business rule or server behavior works. Observed prices, statistics, limits and copy are time-sensitive examples, not default business policy.

## Architecture

- PHP 8+ custom OOP MVC, Composer PSR-4 (`App\\` â†’ `src/`), PSR-12, dependency injection and small controllers. A single front controller routes requests to controllers; middleware handles sessions, authentication, CSRF, validation, authorization and errors. Services own business logic and repositories own persistence. Use environment variables via `vlucas/phpdotenv`; keep secrets out of source control.
- MySQL/InnoDB with foreign keys, PDO exceptions, native prepared statements and explicit transactions for multi-row financial or entitlement changes. Inject a shared connection through a connection factory; repositories do not construct connections. Store timestamps in UTC, render configured Africa/Lagos time when required, and use integer minor currency units rather than floating point.
- Render accessible HTML on the server with Tailwind CSS compiled at build time. Add scoped JavaScript/jQuery and AJAX for polling, filters, dialogs and chat where useful. Preserve links and forms as functional fallbacks where practical; escape output, validate input server-side, label controls, manage dialog focus and announce updates. Match screenshots using provisional tokens, then verify actual responsive behavior.
- Serve through HTTPS, configure secure, HttpOnly, SameSite session cookies, regenerate the session ID on login, use password hashing and CSRF tokens on all state-changing web requests including AJAX. Apply ownership/role checks, parameter validation, rate limits and safe error handling; log without credentials or sensitive payloads.
- Define JSON responses for our own AJAX endpoints with a consistent envelope, e.g. `{ "data": ..., "error": null, "meta": ... }` and appropriate HTTP status codes. **Design required:** response shape and endpoint contracts are ours; observed reference responses were not captured. Use cache headers and short-lived cache for public round and aggregate data; background jobs handle ingestion and expensive aggregates with retries and idempotency. Never cache account-specific output publicly.

Suggested structure: `public/index.php` and compiled assets; `src/{Controllers,Middleware,Services,Repositories,Domain,Infrastructure}`; `views/{layouts,components,pages}`; `config/`; `database/migrations/` and seed fixtures; `tests/`; `storage/` outside the public root. Use dependency injection and configuration for data providers and clock so logic is testable.

## Page and route inventory

The first column is a proposed local route; source paths are evidence, not a requirement to expose legacy `.php` URLs. Use explicit redirects or aliases only if compatibility is later required.

| Page / proposed route | Evidence and implementation boundary |
| --- | --- |
| `GET /free-picks` and `?bookmaker=` | Platform selector and SportyBet, BetPawa, BetKing card links observed (P). Selection outcome and public pick data are unknown. |
| `GET /` and `/#how-it-works` | Public navigation observed (P); hero and section content require further inspection. |
| `GET /results`, `/auto-betting`, `/pricing`, `/reviews`, `/faq`, `/leaderboard`, `/contact`, `/responsible-gambling`, `/about`, `/terms`, `/privacy` | Links observed (P); page content and behavior unknown. Provide routes only when requirements and content are supplied. |
| `GET /login`, `POST /login`, `POST /logout` | Sign-in link and dashboard sign-out form observed (P, O, B); credentials, registration and session policy are **design required**. |
| `GET /dashboard` | Overview, platform cards, next kickoff, numbers, market pulse, model form, onboarding and activity observed (O). Underlying calculations are **design required**. |
| `GET /dashboard/bookmakers/{bookmaker}` | SportyBet board and links to BetPawa/BetKing observed (B); other bookmaker layouts and behavior remain unknown. Validate bookmaker against database, never arbitrary paths. |
| `GET /dashboard/plans`, `/wallet`, `/referrals`, `/reviews`, `/support`, `/account`, `/dashboard/notifications`, `/dashboard/kyc`, `/dashboard/payout` | Navigation destinations observed (O, B), not their contents. Scope and forms are **design required**. |
| `GET /dashboard/bookmakers/{bookmaker}/auto-bet` | Setup link observed (B); execution and integration are **design required** and require verified provider access and requirements. |

Proposed same-origin AJAX routes, all **design required**: `GET /api/bookmakers/{bookmaker}/round`, `GET /api/bookmakers/{bookmaker}/results`, `GET /api/predictions?bookmaker=&model=&market=&cursor=`, `GET /api/model-performance`, `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`, `GET /api/chat/messages?room=&cursor=`, `POST /api/chat/messages`, and `GET /api/insights`. Authenticate and authorize as appropriate; CSRF applies to POST. Define pagination, validation, error codes and response fields before implementation. Reference script evidence suggests notifications GET/POST (O, B); observed GETs for results and chat returned JSON and 200 (W), without bodies. None proves these proposed route shapes. There is no evidenced search endpoint or search/filter/result workflow; do not invent parity for it.

## Reusable interface components

- Public header, responsive navigation, platform cards, footer and music player (P); dashboard sidebar, top bar, account control, notification bell and overview cards (O, B).
- Prediction board shell, countdown and round metadata, model and market radio groups, match/pick cards, base-win input and increment controls, current-bet summary, history rows, performance table and simulator panel (B). Make each independently renderable with explicit loading, empty, locked and failure states. Script-defined messages are evidence of possible states, not evidence that they were displayed.
- Premium dialog with plan cards and close/upgrade controls (B); keyboard focus management and responsive layout are **design required**. Display live configured pricing and entitlements, not captured account-specific values.
- Chat panel and room selector, assistant control, music controls, common alert/toast, loading skeleton, pagination and empty state (B, W). Chat interaction and assistant outputs need further observation; notification empty message is script evidence (O).
- Tailwind theme: near-black/deep-green surfaces, pale text, bright-green primary controls, muted borders and gold accents are screenshot-derived starting points (P, O, B). Font families, exact tokens, breakpoints and mobile appearance remain unknown.

## Proposed database schema

All schema and lifecycle rules below are **design required**, not recovered reference internals. Use `BIGINT UNSIGNED` primary keys, `created_at`/`updated_at` UTC timestamps where applicable, explicit foreign keys and indexes for access patterns. Separate personal information from public data. Migrations must be reversible where safe; do not hard-delete financial audit records.

| Tables and key fields | Relationships / constraints |
| --- | --- |
| `users` (email, password_hash, status), `user_profiles` (user_id, display_name), `password_reset_tokens` (user_id, token_hash, expires_at, used_at) | Unique normalized email; profile and reset tokens reference users. Session data managed securely, with a DB session store only if deployment needs it. |
| `bookmakers` (slug, name, status), `leagues` (bookmaker_id, name), `rounds` (league_id, external_id, season, week, kickoff_at, status), `teams`, `matches` (round_id, home_team_id, away_team_id, kickoff_at, status), `match_results` (match_id, scores, settled_at, source_version) | Unique bookmaker slug and provider-scoped round ID; index rounds by league/kickoff and matches by round. Provider identity, feed rights and result correction rules require design. |
| `models` (slug, name, status), `markets` (slug, label), `predictions` (round_id, match_id, model_id, market_id, selection, odds_decimal, status, generated_at), `prediction_results` (prediction_id, outcome, settled_at) | Define unique identity and settlement versioning with provider requirements. Index by round/model/market and settled time. Odds use DECIMAL and validation. |
| `plans` (code, price_minor, currency, duration_seconds, status), `plan_entitlements` (plan_id, key, value), `subscriptions` (user_id, plan_id, starts_at, ends_at, status), `pick_usage` (user_id or approved anonymous subject, period_start, count) | Index active subscriptions and usage period. Quotas, reset time, anonymous identity and entitlement concurrency are unresolved; enforce atomically once agreed. |
| `payment_transactions` (user_id, plan_id, provider, provider_reference, amount_minor, currency, status), `payment_events` (transaction_id, provider_event_id, payload_digest, received_at) | Unique provider references/event IDs; verify callbacks, use transactions and idempotency. Payment credentials and raw card details must not be stored. |
| `wallet_accounts` (user_id, currency), `wallet_entries` (account_id, amount_minor, type, reference, created_at), `payout_requests` (account_id, amount_minor, status) | Design an immutable ledger and reconciliation before enabling balances or payouts; unique idempotency references, authorization and locking required. |
| `notifications` (user_id, type, payload, read_at), `chat_rooms` (bookmaker_id, name), `chat_messages` (room_id, user_id, body, status, created_at), `chat_reactions` (message_id, user_id, type) | Index notification user/read and room/message cursor; unique reaction per user/message/type. Moderation, retention and access rules require design. |
| `auto_bet_configs` (user_id, bookmaker_id, status, limits), `bet_attempts` (config_id, prediction_id, idempotency_key, status, provider_reference), `audit_events` (actor_id, action, subject, timestamp, metadata) | Never store bookmaker credentials without an approved secure integration design. Auto-betting execution, limits and provider reconciliation require explicit requirements and verified authorization. |
| `referrals`, `support_tickets`, `reviews`, `kyc_cases` | Placeholder domains only; fields, retention, privacy and workflows require requirements before migrations. Avoid storing identity documents until a reviewed storage and access design exists. |

## Services and responsibilities

- `AuthService`, `SessionService`, `CsrfService`: session lifecycle, password verification and request protection. `UserRepository` persists users; authentication and account recovery policy are **design required**.
- `BookmakerService`, `RoundService`, `ResultsIngestionService` and provider adapters: read and normalize rounds/results, handle stale/missing/corrected data; repositories persist normalized data. The actual upstream source and schedule are unknown.
- `PredictionService`, `PerformanceService`, `StakeCalculatorService` and `SimulationService`: filter predictions, calculate history and present stakes and comparisons. Mathematical rules, precision, bankroll handling and settlement semantics are **design required**; never represent simulation as a guaranteed return.
- `EntitlementService`, `QuotaService`, `PlanService` and `PaymentService`: determine access, count usage atomically, manage payment and subscription lifecycle. Prices and free allowance observed in B are examples; policy, reset behavior and payment provider need confirmation. Verified provider callbacks, idempotency and reconciliation precede activation.
- `NotificationService`, `ChatService`, `InsightService`, `MusicPreferenceService`: deliver notification state, room messaging, summaries and playback preferences. Moderation, refresh cadence and assistant logic are **design required**.
- `AutoBetService`, `WalletService`, `PayoutService`, `ReferralService` and `SupportService`: interface boundaries only until integrations and rules are specified. Background jobs perform ingestion, settlement, notification dispatch and reconciliation with retry limits and auditability; sensitive actions require authorization and idempotency.

## Feature-parity checklist

All items are planned and unchecked. â€œObservedâ€ refers to the evidence listed, not implementation completion.

- [ ] Public platform selector, navigation, paused BetKing presentation, footer and music controls (P). Card destinations observed; selection behavior unknown.
- [ ] Responsive menu and labelled landmarks (P, O); mobile design and interactions need verification.
- [ ] Signed-in overview, next kickoff, bookmaker cards, numbers, market pulse, model form, onboarding and activity (O); metrics and update rules **design required**.
- [ ] Dashboard navigation, account, notifications and sign-out controls (O, B); notification GET/POST appears in delivered source, actual behavior unverified.
- [ ] SportyBet round metadata, countdown, model and market choices and prediction display (B); feed, eligibility and prediction generation **design required**.
- [ ] Base-win controls, current-bet summary, history, explanations, performance and simulator (B); formulas and result data **design required**.
- [ ] Free-limit dialog, plan cards and upgrade link (B); quota, prices, purchase and entitlement rules **design required**.
- [ ] Loading/empty/locked messages described in scripts (B); successful and error flows need product requirements and live verification.
- [ ] Chat room, message entry and assistant control (B, W); chat GET observed with 200 JSON (W), but its schema and messaging semantics are unknown.
- [ ] Results refresh: GET results JSON observed with 200 (W); source, payload, cadence and interpretation **design required**.
- [ ] Auto-betting setup link (B); provider integration, safeguards and execution **design required**, not established by link text.
- [ ] Linked billing, wallet, referrals, reviews, support, settings and other pages (P, O, B): contents and behavior require discovery.
- [ ] Search, filter and open-result workflow: **unverified**, not established by the observation attempts (workflow document); do not claim parity until captured.

## Phases and acceptance

1. **Resolve requirements and evidence gaps.** Inspect relevant views and states, obtain authorized data/provider and payment integration requirements, define roles, quota rules, currency, retention, interaction contracts and acceptance examples. Confirm whether search is in scope. Record observed facts separately from decisions.
2. **Foundation.** Set up Composer, routing, DI, migrations, PDO repositories, configuration, layouts, Tailwind build, session/CSRF middleware and structured error handling. Establish CI for Composer validation, coding standards, static analysis, tests and asset build. Acceptance: clean install and migration on a disposable database, authenticated and CSRF-protected form flows verified.
3. **Public pages and dashboard shell.** Implement platform selector, navigation, responsive shell, overview and music controls with accessible semantics and fixture data. Compare screenshots at known viewport sizes; verify keyboard and small-screen layouts. Clearly label placeholder data.
4. **Prediction board and history.** Build round/result adapters, persistence, board, calculator, history and performance views, with loading, empty, stale and failure states. Test filtering, pagination, settlement corrections and calculation examples against approved fixtures; compare visual behavior with further reference captures.
5. **Accounts, plans and notifications.** Add entitlements, quotas, notifications and plan display after rules are approved; integrate payment only with a configured provider, verified callbacks and reconciliation. Test CSRF, authorization, quota races, duplicate events and failure recovery.
6. **Chat, insights and integrations.** Add moderated chat and insights after requirements; build auto-betting and wallet/payout only after provider, security and financial workflows are defined. Exercise idempotency, concurrency, limits and external failure handling before enabling them.
7. **Release readiness.** Run accessibility and responsive checks, end-to-end journeys, performance and security checks, backup/restore and deployment rollback tests. Configure logs, monitoring, queue health, secrets, HTTPS, caches and CI/CD deployment for the target environment. Review every checklist item against evidence; mark unknown behavior as an intentional product decision, never observed parity.

## Bet8Ja preview (historical; removed)

Bet8Ja was previously added as a local preview. It has since been removed from the active catalog, cards, routes and fixtures.

## Simora interface update

The application is branded Simora, with Virtual Football Analytics as its subtitle. The compact overview places upcoming schedule details in each platform card, separately from predictions. Current platform data is local fixture data; no live round feed is connected. Cards support loading, empty, unavailable and populated round states. The sidebar uses locally served Bootstrap Icons and supports a collapsed desktop layout and a mobile menu. Historical reference observations retain the original site name.

## Shared platform board implementation

The local `/sportybet`, `/betpawa` and `/betking` routes share `views/platform.php`, using the shell and fixture data in `DashboardController`. The supplied Simora football predictions dashboard image is the visual guide for the compact metadata strip, market controls, prediction state, upcoming round, simulator, performance and history. Existing REA observations of the reference board remain documented separately; the supplied design does not establish live behavior, provider data, quota, or pricing. The page intentionally labels illustrative content and leaves unconnected data unavailable. Filters are validated against allowlists on the server, and can be submitted without JavaScript; the stake control changes only a local example amount. The board stacks on narrow screens and keeps the table horizontally scrollable. Actual ingestion, prediction and settlement, simulation formulas, entitlements and provider integrations remain future work.

### Platform board implementation notes

The shared page is rendered by `views/platform.php` for `/sportybet`, `/betpawa` and `/betking`; `DashboardController` selects the platform and validates market, round and status query parameters against allowlists. The supplied Simora image guides the layout; REA board evidence B supports the presence of round details, market selection, prediction, history, performance and simulator concepts, but does not establish the image's sample data or backend rules. SportyBet rows and match names are illustrative fixtures, and the performance trend is decorative rather than calculated. Other platforms show an unavailable history state. The server-rendered GET filters work without JavaScript; JavaScript enhances selection and local stake controls. Responsive layouts stack the panels and allow horizontal table scrolling.

Before replacing fixtures, implement the provider adapter and persistence described above, specify result and settlement semantics, and replace the decorative trend with a calculated, accessible series. Quota, authentication, pricing, simulation and betting execution remain unimplemented. The local page is a preview, not evidence of production feature parity. A fresh REA target discovery on 3 November 2026 could not connect to the supplied CDP endpoint; existing retained observations remain the evidence for this work.

## Platform details: current implementation and integration gap

The shared platform details page now uses `PlatformDetailsService` and a `PlatformDataRepository` interface. The default repository returns no data because no approved source, database or API is configured. Search, competition filtering, sorting and pagination are server-side capabilities for validated result records; the UI avoids presenting illustrative figures as real. The overview retains separately labelled fixtures. Upcoming rounds, predictions, analytics, authentication, persistence and ingestion are not implemented and need approved provider and product specifications. See the README for the internal adapter contract and test command. This is not production-ready or a verified integration.

## Platform details demo data update

The user approved synthetic data until backend work begins. `DemoPlatformDataRepository` supplies synthetic competitions, result records and upcoming rounds to the existing `PlatformDetailsService`. The page labels the data and example selection as demo content. Filters and pagination work on those records; goals per match and over-2.5 percentages are calculated from all matching records. Example countdowns use simulated three-minute slots and require a refresh after kickoff. This is an in-memory preview, not verified coverage, a live feed, a prediction model or production integration. Replace the repository behind the interface after provider and business rules are approved.

## Current platform board specification

The user requested that the platform page follow the supplied prediction-board image strictly. The earlier competition/analytics card layout has been replaced by the round header, metadata, quota notice, market selector, prediction lock, upcoming fixtures, fixed-stake simulator, historical performance chart and recent prediction table. The current implementation and test contract is [platform-board.md](platform-board.md); it supersedes the generic details-page and demo-data descriptions above. This remains a local demonstration; backend integration is deferred by user instruction.

## Week terminology update

The current Simora design uses Week throughout metadata, schedule cards, history columns and filters (Week 30 / W29). Demo records and query parameters use `week`, with backward compatibility for older `round=R29` bookmarks. See [platform-board.md](platform-board.md). Earlier roadmap/schema proposals and captured source observations retain Round as their historical/domain terminology; this update does not create or migrate a database.

## Upcoming-match prediction body

The latest user mockup supersedes the Prediction card's locked/empty body with a read-only match preview: team shirts, names/codes and home/away roles, market, odds, historical win rate, two-pick navigation and Why this pick? explanation. The existing header, countdown, market controls, simulator and history remain. These are explicit demo records provided by `DemoPlatformDataRepository` and selected by `PlatformDetailsService`; the source and interaction contract is documented in [platform-board.md](platform-board.md).

## Recent predictions Load more

The platform card now appends history in six-record batches through a Load more button. The demo-only `GET /api/platforms/{platform}/predictions` endpoint returns structured JSON using the same service and filters as the page. Loading/retry/exhausted states and repeated-click protection are covered by `tests/history-load-more.cjs`; cumulative rendering without JavaScript is covered by service tests. See [platform-board.md](platform-board.md) for the current API and workflow contract.

## Compact mobile history cards

The user-provided mobile history mockup now guides the recent-predictions cards: Week/date header, Pick tag, paired kits, team codes, score/odds/historical-rate summary, info dialog and result badge. The demo repository/API provide the additional metadata and initial/loaded rows share the same visual contract. One- and two-column card layouts apply up to 900px; see [platform-board.md](platform-board.md) for current details.

## Reviews planning update

The [Reviews blueprint](reviews.md) proposes scope, architecture and delivery gates. The Reviews route now provides a non-interactive preview. Live functionality remains dependent on the unresolved policy decisions and foundations in the blueprint.

**Support planning:** See the [Support blueprint](support.md) for the proposed help center, ticket lifecycle, security, integrations and delivery gates. Implementation has started with a searchable local-preview FAQ at `/support`; production ticketing depends on the foundations and policy decisions described in the blueprint.
