# Predictions workspace

`GET /predictions` uses the Platforms page design: a compact heading and demo notice, platform/league/market controls, three matching desktop cards (upcoming pick, strategy lab, simulation report), and a full-width settled-results ledger. All schedules, picks, odds and results are synthetic demo data; historical performance is not a forecast.

The platform and league selectors choose the focused board, and the market tabs update its upcoming pick, round information and settled ledger. Controls use GET forms and work without JavaScript. The featured pick shows team kits, decimal odds, the historical demo hit rate and an expandable explanation. Missing picks and results have empty states. The All platforms link returns to `/platforms` with the current league selections and hub filters. The sidebar exposes Platforms instead of Predictions; platform/league cards open this page at `/predictions?focus={slug}&league_{slug}={league-id}`. Direct prediction and individual board URLs remain available.

The strategy lab replays up to the latest 100 settled selections for the focused platform, league and market, oldest first, using each selection's recorded decimal odds. Users can select flat, martingale or recovery staking, a starting bankroll, base stake, sample size and next-market odds. Stakes are capped at the available bankroll; replay stops when it is exhausted. Martingale doubles the previous stake following an unrecovered loss. Recovery targets accumulated losses plus the base-stake profit at the next odds. The report shows net profit/loss, ending bankroll, return on starting bankroll, peak-to-trough bankroll drawdown, cumulative profit/loss and an illustrative next stake. This is a local browser calculation, requires JavaScript, and neither places bets nor guarantees recovery. Inputs are bounded and validated by the browser. The methodology control explains the replay.

The recent-predictions ledger shows kickoff in WAT, week, match, market, recorded odds, score and outcome. Its outcome selector filters the visible rows, and CSV export downloads those visible rows, marked synthetic and protected against spreadsheet formula prefixes. The table scrolls within its panel. At tablet widths the prediction and strategy cards share a row and the report spans the next row. On phones all three cards stack and the table scrolls horizontally. Native controls, keyboard focus, semantic table headings and status announcements are retained.

The existing hub service still validates legacy market, time-window, platform and league filters and retains its aggregate and pagination read model for compatibility. The focused preview adds `focus` (a configured platform slug), defaults to the first platform, and uses that platform's `league_<slug>` and configured `market` ID. Unknown values fall back safely. The preview ledger includes at most 100 settled records through the current time, independently of the legacy time-window and history filters. The default market is the configured default. No new endpoint, account entitlement, provider connection or database mutation is introduced.

The fixture repository and injected clock remain the data source. Production use requires authoritative provider data, settlement timestamps, server-side bounded queries and appropriate access controls. Synthetic outcome-selected examples must not be presented as real model evidence.

Verification: `php tests/predictions-hub.php`, `php tests/platform-details.php`, `node tests/mobile-navigation.cjs`, `php -l views/predictions.php`, `php -l src/Services/PredictionsHubService.php`, and `node --check public/assets/predictions.js`.

## Platforms navigation

The visible sidebar destination is **Platforms** at `/platforms`. Predictions is a nested workspace reached from that page. Platforms remains highlighted on the prediction workspace and individual boards; only `/platforms` marks the menu link as the current page. See [Platforms page](platforms-page.md).

## Shared navigation hierarchy

The main content header uses the application-wide breadcrumb component described in [navigation.md](navigation.md). The prediction workspace shows `Overview / Platforms / SportyBet / England League`, using the validated focused platform and selected league. Platform and league changes update the breadcrumb, browser title, topbar title and prediction card together. Individual boards extend the path with `Prediction board`. Native GET forms retain other platforms' league selections when switching markets or focus.

Browser verification: `node tests/platforms-page.cjs` covers the Platforms-to-Predictions form navigation, three-card desktop alignment, automatic simulation/chart rendering, switching platform, mobile overflow and shared breadcrumbs across application routes. Screenshots are written to the system temporary directory.
