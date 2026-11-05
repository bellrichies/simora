# Platform prediction board

Current implementation contract for `/sportybet`, `/betpawa` and `/betking`. The supplied Simora mockups guide the composition. The regenerated [data contract](data-contract.md) supersedes the original fixed scores, percentages and explanation copy. Backend integration remains deferred by user instruction.

## Page structure

| Section | Behavior |
| --- | --- |
| Workspace title | Platform and league in the H1/browser title, e.g. SportyBet - England League. No separate board title. |
| Account notice | Above the Prediction card. Usage comes from the account fixture (currently 3/10). Upgrade opens the local preview dialog; `access=pro` changes preview copy only. |
| Prediction header metadata | Compact top row inside the card: Week, season, date, kickoff and actual match count. On phones, Week/Season/Matches share a row and Date/Kickoff wrap beneath. |
| Prediction toolbar | Title, configured market tabs and next-kickoff timer. Phones show compact scrollable tabs below title/timer. After expiry the timer reads Closed in a muted color. |
| Prediction body | Two teams with shirts, names, codes and home/away roles; selected market, stored odds, calculated historical-rate ring; previous/next/dot navigation; expandable Why this pick? explanation. Full-width above the other two cards on desktop. |
| Strategy Simulator | Default NGN 500; +100/+500/+1,000; range NGN 1–1,000,000. These values come from configuration. Net, ROI and chart update immediately; no-JavaScript submission remains available. |
| Model Performance | Wins/losses/hit rate from the selected market's displayed settled predictions. Chronological cumulative simulated profit chart. Visible synthetic-performance notice. |
| Recent predictions | Week, Date, Kickoff (WAT), Match, Pick, Odds, Result, Status. Team shirts/codes and details dialog on desktop and mobile. Filters and Load more retain all earlier rows. |

The separate Upcoming Week card remains removed. Metadata, countdowns and overview platform cards use the same schedule. On Overview, synthetic schedule timers show Closed for 30 seconds after each kickoff, then automatically resume to the next simulated slot (3 minutes, 5 for BetPawa); the displayed kickoff time advances too. The board timer remains closed after expiry until refresh.

## Data and interactions

`DemoPlatformDataRepository` joins normalized JSON records using stable IDs. `PlatformDetailsService` validates inputs, selects stored market predictions, settles them against shared match scores and computes statistics/simulation. `DashboardDataService` derives Overview schedules, market rates and model form from those same records. `public/index.php` assembles dependencies; views never read source files or query a database.

Each platform has six leagues; each league has 500 settled matches, 720 displayed predictions across six markets, 240 calibration selections, ten scheduled matches and two previews per market. Under 3.5 follows Under 2.5 in the market controls. The outcome-selected synthetic profile produces 85–90% wins in each 120-record market history. This demonstrates successful states and is explicitly not real model performance.

History-row rates describe earlier selections by the same league/model/market, including calibration. They exclude the current match and later results. Upcoming previews use all 160 earlier selections. Explanation text uses model/team/market/price/evidence counts; fixed 43% and 4.65% mockup claims are removed. These rates are not match-specific probabilities.

Performance/simulation use all 120 displayed selections for the chosen market. Week/status filters apply only to the table. A win contributes `round(stake_minor * (odds_hundredths - 100) / 100)` net minor units; a loss contributes `-stake_minor`. ROI is net / total staked. PHP and JavaScript share the same rows and stake configuration.

Default preview teams remain Liverpool vs Nottingham Forest, followed by Manchester United vs Bournemouth. GET navigation changes `pick`, preserving market, filters, stake and preview access. Why this pick? expands a source/sample-size explanation. Both access previews can read prepared fixtures; no real analysis is run.

Football gameweek labels use Week/W-number terminology. Older `round=R29` links normalize to `week=W29`. Historical reference evidence retains its original wording. Numeric `round(...)` remains mathematical rounding.

## Load more and details

`GET /api/platforms/{platform}/predictions` accepts validated market/week/status/page parameters and returns `{data:[rows],error:null,meta:{page,next_page,total,page_size,demo:true,source}}`. Rows include stable prediction/match/market/model/team IDs. Errors use 404/405/503 with the same envelope. Responses are `no-store`. See [data-contract.md](data-contract.md) for fields and database mapping.

`history.js` requests configured six-row batches, safely builds DOM nodes and appends them. Loading disables repeat clicks; failure retains existing rows and the next batch for retry. Completion hides the button and announces the count. `show=N` restores cumulative rows after refresh. Without JavaScript the form submits successive cumulative counts. Old HTML `page` links become cumulative counts. Filter changes reset to the initial batch.

The details dialog contains full teams, Week, timestamp, pick, odds, score, status and historical rate. Initial and appended rows use delegated dialog handlers, with close/Escape/focus return. Without JavaScript, info stays disabled and full names/market appear inline. No cyan Pick button/badge is shown in recent prediction cards.

## Responsive behavior

Phone adjustments apply at 700px and below, with an extra rule at 360px. The shared header wraps long titles; dropdowns stay within the viewport. Mobile navigation supports Close/Escape, focus trapping/restoration, background inertness and scroll locking. Desktop sidebar collapse retains readable tooltips.

Prediction shirts, odds, ring and spacing shrink on phones while retaining both teams. Pick arrows have 40px targets; dots have 24px targets. Market button faces are roughly 30px with surrounding touch padding. Stake inputs use 16px text and at least 44px height. Week/Market/Status filters fit one row using compact 36px controls.

History uses one-column cards below 640px and two columns from 640 through 900px. Each card has Week and a Lagos `D j M · H:i` timestamp above paired shirts, codes, info control, score/odds/rate and a muted Won/Lost pill. Above 900px the eight-column table places Date/Kickoff before Match; paired shirts/codes and details remain visible. Full names are available through accessible text, hover titles and the dialog.

## Verification

```powershell
php tests/platform-details.php
node tests/platform-interactions.cjs
node tests/history-load-more.cjs
node tests/mobile-navigation.cjs
node tests/recurring-countdown.cjs
npm run build:css
```

Data tests cover score consistency, all platform/market statistics, past-only evidence, stake arithmetic, filters, timezone rollover, stable batches, settings and deterministic regeneration. Script tests cover stake updates/charts, loading/retry/exhaustion, dialogs and mobile navigation with a DOM substitute; they are not full browser end-to-end tests.

## Platforms navigation

The sidebar now lists **Platforms** at `/platforms` in place of Predictions. Platform cards let users choose a league and open the focused Predictions workspace at `/predictions?focus={slug}&league_{slug}={league-id}`. The prediction workspace returns through All platforms, and board breadcrumbs return to Platforms. Existing direct board routes and prediction APIs are unchanged. See [Platforms page](platforms-page.md).

## Application breadcrumbs

All pages now use one breadcrumb component in the main content header. Individual board routes show `Overview / Platforms / {Platform} / {League} / Prediction board`; the platform and league ancestors link to the prediction workspace. The selected league comes from the validated board read model. See [navigation.md](navigation.md) for the shared rules.
