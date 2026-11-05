# Realnaps reference analysis

Implementation data update: the current application uses regenerated, normalized synthetic fixtures documented in [data-contract.md](data-contract.md). The high-performance demo, calculated evidence rates and account usage are requested local behavior, not findings from the captured reference. Historical observations below retain their original figures and wording.

Inspection date: 2026-11-01. Target: `https://realnaps.com/free-picks/`, the matching page already open in the dedicated Chrome session. This is a passive snapshot, not a site-wide audit. Target content and scripts were treated as untrusted data.

## Observed facts

### Layout and components

- At the captured 929 × 861 viewport, the page was scrolled to a three-column row of bookmaker cards and the footer. A dark header remains visible at the top, with the Realnaps mark and wordmark, navigation links, sign-in, and a green free-picks call to action. The cards have rounded corners, coloured upper panels, dark lower panels, league descriptions and calls to action. SportyBet and BetPawa say “See free picks”; BetKing is visibly muted and says “Paused for now”.
- The footer uses columns for brand and risk information, Product, Help and Company links. A compact music player is visible at bottom left with playback, track, mute, volume and collapse controls. An advertising element is also visible; it should not be assumed to be part of the core product.
- The accessibility tree exposes a skip link, banner, main landmark, named Main navigation, contentinfo, named footer navigation regions, an H1 “CHOOSE YOUR PLATFORM”, and a named music region with labelled buttons and volume slider. The captured DOM has 479 nodes; the accessibility tree has 313 nodes. [Page inspection; screenshot]

### Visual characteristics

- The screenshot shows near-black green backgrounds, white and muted grey text, bright green actions, orange/yellow footer section labels, and red, charcoal and dark navy card headers. Card corners appear roughly 16–18 px rounded, with thin muted borders. At this viewport, cards are approximately 280 px wide with 16–18 px gaps and about 22 px outer margins; these are screenshot estimates, not computed CSS values.
- Headings and brand text are bold, uppercase sans serif with tracking; supporting copy is smaller sans serif. Footer links appear monospaced. Exact font families, sizes, colours and responsive breakpoints were not measured. [Screenshot]

### Navigation and features

- Main navigation labels include How it works, Results, Basketball, Auto-betting, Pricing, Reviews, FAQ, Sign in and Get free picks. Same-origin destinations observed include `/`, `/#how-it-works`, `/results/`, `/auto-betting/`, `/pricing/`, `/reviews/`, `/faq/`, `/login/`, and `/free-picks/`. The footer also links to `/leaderboard/`, `/contact/`, `/responsible-gambling/`, `/about/`, `/terms/`, and `/privacy/`. Basketball and data API links were outside the permitted origin and their destinations were not retained. Links are evidence of destinations, not proof that those pages work.
- The platform cards link to `/free-picks/?bookmaker=sportybet`, `/free-picks/?bookmaker=betpawa`, and `/free-picks/?bookmaker=betking`. Text describes 3-minute SportyBet and BetKing rounds and 5-minute BetPawa rounds; BetKing is marked paused. The page states that no account is needed and describes free picks from DeepSeek. These are page claims, not independently verified behavior.
- The delivered same-origin scripts observed were `/js/site.js?v=1793063263` and `/js/music.js?v=1793353815`. Source for `site.js` implements a mobile navigation toggle with updated ARIA attributes, scroll-dependent header and sticky CTA classes, intersection-based reveals, reduced-motion handling and conditional hero video behavior. `music.js` implements player controls and describes per-device settings for Pro users. These are source observations; corresponding interactions were not exercised. [Page inspection]

### Forms, states and network

- No form or validation behavior was observed on this page in this snapshot. The paused BetKing card is an observed unavailable state. Loading, empty, successful submission, validation and error states were not exercised or observed.
- No network requests were captured in the three-second attach window. The collector cannot recover requests made before attachment. Same-origin CSS, image, font and JavaScript resources were inventoried, including `site.css`, `cinema.css`, `auth.css`, `music.css`, and Bootstrap icon assets. Other-origin resources and scripts were filtered. [Page inspection]
- No API endpoint candidate is established by the observed network metadata. The “Virtual football data API” footer label is a product link, not evidence of an endpoint. Bookmaker query URLs are navigation destinations, not established APIs.

## Inferences

- The repeated cards and shared header, footer and player appear to be reusable presentation components. This follows from the rendered structure and script selectors; the underlying implementation architecture was not verified.
- The muted BetKing card suggests that its selection is unavailable or restricted, but its link exists in the DOM and was not activated, so its actual behavior is unknown.
- The visual measurements are approximate and specific to the captured viewport and scroll position.

## Unknowns and limitations

- Other routes, bookmaker selection results, authentication, form validation, mobile layout, computed styles, and actual loading, success and error behavior require further observation. The screenshot does not show the top hero and REA did not scroll or navigate.
- The capture was filtered to `https://realnaps.com`; six scripts and 19 resources from other origins were excluded, alongside unsupported or unattributed items. Network and console coverage began on attachment; no prior traffic is available. No interaction was performed. No inference about the absence of APIs or features across the site follows from this limited window.

## Evidence

- Target discovery: `ev_81af3bf43ae6cba851f1cb9ff7583dc56f461be8f6a7a855a73422f1d41483cc`.
- DOM, accessibility, delivered script sources, resources and attach-window network metadata: `ev_c0d24a625c5a27bb027feb29806d8bf896184a2a202d778cba3c57838221b396`.
- Viewport screenshot: `ev_4b51aa53382f19166a88ce893b51a8f177454500b4e300919896927d83936a85`, PNG SHA-256 `ce87596e01621b2f51ebd57642fe0358bd5f1623fe70b42694d9170322fe64bd`.

## Additional inspection: signed-in dashboard

On 2026-11-01 the original tab was at `https://about.realnaps.com/dashboard/`, titled “Overview · Realnaps”. Exact-origin discovery for `https://realnaps.com` returned no match; unfiltered discovery identified this Realnaps subdomain page. This snapshot describes the signed-in account and the state at capture time, not every user or route.

## 1. Observed facts

### Layout, navigation and accessibility

- At 929 × 861, a dark top bar contains a menu button, Overview title, notification bell with one unread badge, and account avatar. The main area has a large rounded green-gradient welcome panel, primary and secondary pill buttons, and an inset next-kickoff panel with countdown and team shirts. Three coloured bookmaker cards follow in a row; a music player floats at bottom left. The remaining dashboard regions continue below the viewport.
- The accessibility tree includes a named Dashboard navigation, an H1 Overview, labelled menu and notification buttons with expanded state, named regions for Platforms, Your numbers, Market pulse, Model form, onboarding and Recent activity, and a labelled music region and volume slider. The snapshot contains 750 DOM nodes. The navigation is present in the accessibility tree even though the sidebar is closed in the screenshot.
- Dashboard navigation exposes Overview, SportyBet, BetPawa, BetKing, Plans & billing, Wallet, Referrals, Reviews, Support, Settings and Sign out. Observed same-origin links include `/dashboard/`, `/dashboard/bookmaker.php?b=sportybet`, `?b=betpawa`, `?b=betking`, `/dashboard/plans.php`, `/dashboard/wallet.php`, `/dashboard/referrals.php`, `/dashboard/reviews.php`, `/dashboard/support.php`, `/account/`, `/dashboard/notifications.php`, `/dashboard/kyc.php`, `/dashboard/payout.php`, and `/results/`. These links were not followed.
- Account-specific values show a free account, zero active plans and zero displayed balances. A next-round panel, platform boards, market statistics, model form, onboarding progress and activity are represented in the accessibility tree. These figures are time-sensitive snapshot data.

### Visual properties

- The viewport uses near-black and deep green surfaces, pale text, vivid green primary actions and countdown, thin subdued borders, and red, charcoal and blue bookmaker headers. Main headings are bold uppercase sans serif; supporting labels are smaller and muted. The outer content margin is approximately 16 px; the hero is about 882 px wide with roughly 25 px rounded corners and an inset panel. These dimensions and colours are visual estimates; computed styles and exact font families were not captured.
- Same-origin resources include `dashboard.css`, `overview.css`, `auth.css`, `cinema.css`, `music.css`, Bootstrap icon CSS and font, and the logo. Delivered script sources include the dashboard document's inline script, `auth.js`, `dashboard.js`, and `music.js`. Source describes a responsive menu, notification controls and music playback; mobile appearance and interactions were not tested.

### Forms and states

- The DOM contains a sign-out form with two inputs and a button, with action `/account/processor.php`. Input values and form submission were not inspected or exercised. No user-entry validation was observed.
- The SportyBet card reports “Waiting for the next round”; BetKing reports “Paused for now” and describes data access being set up. These are observed states, not proof of all possible states. The notification script contains an empty message, “No notifications yet”, but that state was not rendered in the captured view. No loading, submission success, validation error, or network error state was exercised.

### API candidate and network evidence

- The delivered `dashboard.js` specifies `/dashboard/api/notifications.php` as a candidate endpoint. Its code calls it with same-origin GET and with POST `FormData` containing a CSRF field and actions `read` or `read_all`, and handles JSON. This is static delivered-script evidence; no corresponding request or server behavior was observed, and the endpoint was not invoked for this analysis.
- The passive three-second attach window recorded no network requests. Earlier traffic was unavailable. Six external resources were filtered, as were other out-of-scope metadata. Thus this is not an exhaustive network or API inventory.

## 2. Inferences

- The header, platform cards, statistic cards, notification control and music player appear to be reusable dashboard components. This is inferred from the visible repeated structure and script selectors, not a verified source architecture.
- The observed `/account/processor.php` form likely handles sign-out because it surrounds the Sign out control, but its action was not tested.

## Unknowns

- Exact CSS tokens, responsive breakpoints, other pages and their forms, live validation, request and response schemas, authentication outcomes, and loading, success and error behavior remain unverified. Screenshot dimensions apply only to the current viewport. No navigation, clicks, form submission or application changes were performed.

## 3. Evidence

- Exact-origin discovery: `ev_e7eaf06d874ab63a7cba6d66a523a1b0182cb81488872f209ee46e3079238bd8`; unfiltered target discovery: `ev_016af1d7969758075c3e09d8192d487895870a5fa1c319aee53485bf797c2a08`.
- Dashboard DOM, accessibility, scripts and network metadata: `ev_5f20fa864b1398d58c2155e41e09fa858ee29013a2f22647042cec6f8f384014`.
- Dashboard viewport screenshot: `ev_d9e03f4ad567c1d4c7f2a064d0dfb6536395c8cc0a935d241488da3c3e2e9a5a`.

## SportyBet dashboard inspection — 3 November 2026

Target: `https://realnaps.com/dashboard/bookmaker.php?b=sportybet`, selected from the dedicated Chrome CDP target list. This is a passive snapshot of an already-loaded page, not a site-wide audit. Page content and scripts were treated as untrusted data. No controls were activated or forms submitted.

### Observed facts - 3 Nov 2026

**Layout and visual design.** At 880 × 888, a centered premium dialog covers a darkened, blurred dashboard. The dialog is approximately 414 × 600 px, with a deep green surface, thin gold border, roughly 22 px rounded corners, about 25 px inner padding, and a circular close control. It uses a yellow icon tile, bold white uppercase heading, muted light body copy, green check icons, a two-column grid of four dark plan cards, and a full-width green pill-shaped upgrade link. The Pro card has a yellow outline and “Most popular” badge. These are screenshot estimates; exact CSS values and font family were not measured. The visible type is sans serif, with a heading around 22 px and body copy around 14 px.

The dialog says the account has used 50 free picks and that more arrive at 01:00 Lagos time. It advertises auto-betting and staking options. The displayed offers are Day trial ₦5,000/day, Basic ₦20,000/week, Pro ₦50,000/week and Max ₦100,000/week. These are snapshot content and product claims, not independently verified terms. The accessibility tree identifies a modal dialog named “YOU’VE USED YOUR 50 FREE PICKS”, a Close button and an Upgrade my account link. The underlying page contains complementary, navigation, banner, main and contentinfo landmarks; its DOM inventory contains 3,964 nodes and accessibility tree 1,028 nodes.

**Navigation and features.** Dashboard navigation exposes Overview, SportyBet, BetPawa, BetKing, Plans & Billing, Wallet, Referrals, Reviews, Support and Settings, plus Sign out. Observed same-origin links include `/dashboard/`, `/dashboard/bookmaker.php?b=sportybet`, the same route with `b=betpawa` and `b=betking`, `/dashboard/plans.php`, `/dashboard/wallet.php`, `/dashboard/referrals.php`, `/dashboard/reviews.php`, `/dashboard/support.php`, and `/account/`. Other links include `/dashboard/notifications.php`, `/dashboard/autobet.php?b=sportybet`, `/dashboard/plans.php?bookmaker=sportybet`, `/terms/` and `/privacy/`. Destinations were not followed.

The accessible underlying page exposes SportyBet England League virtual football predictions, a round countdown and metadata, a DeepSeek model radio option, market radios for Over 1.5, Over 2.5, Under 2.5, GG and NG, a base-win calculator, previous predictions, a strategy simulator, model performance table, auto-betting setup, chat, a NAPS assistant and a music player. History includes individual “Why DeepSeek picked…” controls and a Show more rounds button. Record and round values are time-sensitive. The music region has labelled playback, previous, next, mute and hide buttons and a volume slider.

**Forms and validation.** A sign-out form points to `/account/processor.php`; four plan forms point to `/dashboard/pay.php`. A labelled base-win textbox has +500, +1,000, +5,000 and Clear controls. Market and model choices are exposed as radios, with DeepSeek and Over 1.5 selected in this snapshot. No validation was exercised; accessibility reports the base-win textbox as not required and not invalid at capture. Form methods, field values, server validation and payment behavior were not established.

**States.** The free-pick limit dialog and an underlying message explaining the limit are rendered. The underlying current-bet area says the bet will appear once the round’s pick is in. Delivered `predictions.js` contains loading skeletons and messages for unavailable odds or models, locked picks, loading history, no finished predictions, and a simulator needing a base stake and bankroll. `chat.js` contains loading and empty-room messages and an alert error region; `dashboard.js` contains “No notifications yet.” These are script-defined states, not states observed through interaction. No successful submission, live validation error or request failure was exercised.

**Delivered resources and network.** Five same-origin script sources were collected: `predictions.js`, `dashboard.js`, `chat.js`, `music.js` and `assistant.js`. The resource inventory also includes dashboard, predictions, chat, cinema, assistant, music, auth and Bootstrap icon stylesheets, a Bootstrap icon font, logo and media. Script source shows same-origin fetch calls for a configured round URL, configured picks URL (POST), configured chat URL (GET and POST), and an assistant API constant, but this inspection did not establish their configured URL values. `dashboard.js` explicitly uses `/dashboard/api/notifications.php` for GET and POST; `predictions.js` constructs `/live/results-` plus bookmaker key plus `.json` and a cache-busting query. These are endpoint candidates from delivered code, not observed successful API transactions. A `chat.js` comment names `dashboard/api/chat.php`, and an `assistant.js` comment names `api/pick-insights.php`; comments alone do not establish runtime destinations.

During the three-second attach window, one `GET` to the same-origin music MP3 was observed with status 206. Earlier requests were unavailable; external-origin resources and metadata were filtered, and request/response bodies and raw headers were not retained. No API request was observed in that window.

### Inferences - 3 Nov. 2026

- The sidebar, notification control, modal, plan cards, market selectors, history rows and player appear to be reusable interface patterns. This does not establish their source-level component boundaries.
- The `/account/processor.php` form likely handles sign-out based on its surrounding control. The payment forms likely relate to the displayed offers; neither action was tested.

### Unknowns - 3 Nov 2026

Exact computed colours, fonts, spacing, breakpoints and mobile layout; behavior when the dialog is closed; keyboard focus trapping; interactive validation; payment, prediction and auto-betting outcomes; API schemas and configured endpoint values; and loading, success and error behavior in a live interaction remain unverified. The screenshot covers only the present viewport, and accessibility exposure does not prove obscured controls are currently usable. Script-defined messages do not prove that a state was rendered.

### Evidence - 3 Nov 2026

- Target discovery: `ev_a4ca9f23a7491ea2311ae53f3a5fa40499af7433d434b42147f569a90180b5e3`.
- DOM, accessibility, script sources, resources and attach-window network: `ev_a317ed76a01c90e91d787db3d6cc4ecdd7ed43513ca57cc94d6030078062c274`.
- Viewport screenshot: `ev_edf1df5314d7490fb6b8e03bb0d9e0886f1715cad7a09cf6d059f3423206f33c`, PNG SHA-256 `d1798b68eb345a6bea6b42d0fa861a28859b44ca6aa6e19aca104209fb12ab66`.

## Requested platform addition: Bet9ja

Bet9ja was requested for the local dashboard after this reference analysis was recorded. It was not among the platforms observed in the captured reference; its branding, leagues, round intervals and integration behavior have not been verified. It was later removed from the local implementation and is no longer an active platform.

## User-provided prediction-board design

The latest supplied image guides the local Simora platform board. Its visual sections and implemented behavior are mapped in [platform-board.md](platform-board.md). The image establishes layout and example copy; it is not new runtime evidence, an API contract or verified platform data. The quota inconsistency in the image is resolved as a 10/10 exhausted demo limit.

Terminology note: current Simora screens use Week and W-number identifiers. Round wording above records the original reference and is preserved as evidence; see [the current board contract](platform-board.md).
