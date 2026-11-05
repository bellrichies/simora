# Simora - Virtual Football Analytics

Auto Trade is available inside each prediction card, using that board's platform, league and initial market. Its form can save a preview draft per platform in the current browser tab; it cannot activate trading, connect accounts or place bets. The [Auto Trade blueprint](docs/auto-trade.md) documents the remaining dependencies and release gates.

Settings: `/settings` provides a read-only preview of the planned sections. [Settings blueprint](docs/settings.md) describes the proposed account settings architecture and prerequisites. Real accounts and settings management are not connected.

A server-rendered PHP dashboard with locally compiled Tailwind CSS. Account, activity, market figures, countdown and plans are local fixtures; no live services are connected.

## Run locally

Requires PHP 8.1 or later. The compiled stylesheet and generated fixtures are included, so from the repository root in PowerShell run:

```powershell
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000/dashboard>. Stop the server with Ctrl+C.

## Change styles

Requires Node.js and npm. After editing `assets/css/app.css`, `tailwind.config.js`, or template classes, run:

```powershell
npm ci
npm run build:css
```

For continuous rebuilding during development, use `npm run watch:css`. The generated `public/assets/app.css` is served by PHP; do not edit it directly.

The platform list includes SportyBet, BetPawa and BetKing. All three use clearly labelled synthetic demo data; no bookmaker integration is connected.

## Overview and navigation

Overview and platform main content share a centered, 1,180px maximum-width container with the same responsive outer gutters. The Overview hero, KPI cards, platform cards and lower panels align within that shared box.

The compact overview groups schedule information by platform, separately from predictions. Synthetic schedule countdowns show Closed for 30 seconds at kickoff and then automatically count down to the next configured slot. All platforms share their schedule source with the platform pages. Market pulse and model form are calculated from stored predictions. Account KPIs, notifications and quota use the account fixture. The reusable platform card supports loading, empty and unavailable states. The desktop sidebar can be collapsed; on smaller screens it opens as a menu. Bootstrap Icons are served locally.

The sidebarâ€™s Platforms link opens the [platform selector](docs/platforms-page.md) at `/platforms`. Choose a platform and league to open the Predictions workspace.

## Platform prediction board

Each platform route (`/sportybet`, `/betpawa`, `/betking`) uses the shared prediction board matching the supplied Simora design. It includes league/week metadata, free-plan usage and an Upgrade demo modal, six market controls (including Under 3.5 after Under 2.5), a full-width Prediction card with its next-kickoff timer and two navigable team-match previews, a fixed-stake simulator, a calculated performance chart, and recent predictions with week/market/status filtering and a Load more button.

Each platform has six synthetic leagues (England, Italy, Spain, Germany, France and Turkey), each with 120 stored settled predictions per market, with an intentionally high-performing synthetic profile (85â€“90% wins), plus calibration history and two upcoming previews per market. These outcome-selected examples are not real model results. Rates, explanations, profit, ROI, charts, metadata and history are derived from the same records. The account fixture currently uses 3/10 analyses. The Upgrade modal changes only the local access preview.

Regenerate the fixtures with a reproducible seed and date:

```powershell
php bin/generate-demo-data.php --seed=20261103 --as-of=2026-11-03
```

Omit `--as-of` for today's UTC date. If PHP is not on PATH, use `C:\xampp\php\php.exe`. See the [data contract](docs/data-contract.md) for source fields, calculations, backend integration and the reference MySQL schema. Configuration is in `config/app.php` and `config/platforms.php`; fixture files live outside the public directory.

Read [the platform board contract](docs/platform-board.md) for the feature mapping, demo assumptions, formulas and backend boundary. No live provider, account entitlement or prediction model is connected.

```powershell
php tests/platform-details.php
node tests/platform-interactions.cjs
node tests/history-load-more.cjs
node tests/mobile-navigation.cjs
```

On phones, the platform page uses scrollable market controls, readable match details and compact history cards with timestamps, team shirts/codes, score/odds/rate summaries and match-details dialogs. The mobile menu supports Close/Escape, focus management and background scroll locking. See the board contract for the responsive behavior and verification limits.

Recent-prediction match cells use paired uniforms and team abbreviations on desktop and mobile. Phone layouts have a smaller match preview, compact market buttons and a single row of history filters. The cyan Pick badge is removed; the information button opens full prediction details.

Desktop recent predictions include separate Date and Kickoff (WAT) columns with full dates and 24-hour Lagos times, including rows added by Load more.

## Planned community chat

The proposed shared, collapsible chat widget and its backend, moderation and rollout prerequisites are documented in [community-chat.md](docs/community-chat.md). A collapsible widget is available as a non-persistent preview; live messaging is not implemented and the current fixture account is not an authenticated user.

## Plans & billing preview

The /plans page lists the confirmed Trial, Standard, Pro and Max plan names. Confirmed prices and billing periods are shown; features, subscription management and payments are not connected; account and billing information is not live. See [the billing blueprint](docs/plans-billing.md) for prerequisites and open decisions.


## Referrals planning

The [Referrals blueprint](docs/referrals.md) documents the proposed user journey, architecture, API, security and delivery phases. `/referrals` provides a non-interactive preview; accounts, referral links, activity and rewards are not connected. Live operation depends on program decisions and the account and billing foundations described in the blueprint.

## Wallet planning

The [Wallet blueprint](docs/wallet.md) describes a proposed restricted subscription-credit module, ledger, integrations and approval gates. The /wallet page is a non-interactive preview; balances, transactions and credit operations are not connected. The overview balance remains fixture data.


## Reviews planning

The [Reviews blueprint](docs/reviews.md) documents proposed scope, moderation, architecture and approval gates. The /reviews route provides a non-interactive preview; no review service is connected.



## Support planning

The [Support blueprint](docs/support.md) documents the proposed help center, ticket workflows, security and implementation gates. `/support` provides a searchable FAQ about the local preview. Ticket submission and live support are not connected; production ticketing depends on the foundations and decisions in the blueprint.
