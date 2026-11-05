# Platforms page

The visible sidebar entry is **Platforms**, linking to `GET /platforms`. The page lists configured platforms with their repository-provided leagues and schedule summaries. Each native GET form lets the user select a league and open the Predictions workspace using `/predictions?focus={platform-slug}&league_{platform-slug}={league-id}`. Selection works without JavaScript, and mobile cards stack without page overflow.

The Predictions round preview and strategy lab remain at `/predictions`; they are accessed from Platforms rather than a separate sidebar entry. Their All platforms link returns to `/platforms`. Platforms remains visually active on the workspace and individual platform boards, with `aria-current="page"` only when visiting `/platforms`. Existing direct prediction URLs, board routes and `/api/platforms/{platform}/predictions` endpoints remain unchanged. Board breadcrumbs now point back to Platforms.

`DashboardController` obtains the existing hub read model for both routes. `views/platforms.php` and scoped `public/assets/platforms.css` render the selection page; `views/predictions.php` retains the strategy lab. Schedules remain synthetic, timestamped in Africa/Lagos; missing leagues and schedules have explicit empty/unavailable states. Both page routes accept GET/HEAD and reject mutation methods with 405.

Validation: PHP syntax checks, `php tests/predictions-hub.php`, `node tests/mobile-navigation.cjs`, plus HTTP checks for page titles, sidebar labels, board breadcrumbs and platform-to-predictions form targets.

## Performance dashboard

The Platforms page includes a compact performance overview and a recent settled predictions table. The market and 7/30/90-day controls apply to performance and history; the platform control filters the history table. Apply buttons provide a no-JavaScript path. The cards show the selected league for each platform, settled counts, win/loss totals, hit rate, daily hit-rate trend and the five most recent outcomes. League selections are supplied through the page query parameters; each platform card can update its selected league in the overview or open that league in Predictions. The current market, period and other league selections are retained. All figures and schedules are synthetic, with the reporting timestamp shown in WAT.

The table paginates six results at a time and keeps the active filters in its navigation. Export CSV downloads the currently visible page and requires JavaScript. An empty state appears when no settled results match. On small screens the cards stack and the table scrolls horizontally.

Result dates are match kickoff times in WAT; the fixtures do not provide a separate settlement timestamp. The current dataset is an outcome-selected synthetic demonstration, not a live feed or an unbiased measure of model accuracy. Connecting real provider data requires an authorized repository adapter as described in [data-contract.md](data-contract.md).

## Mockup alignment and league flags

League selectors use local SVG flag assets under `public/assets/flags/` instead of emoji. This avoids Windows displaying regional indicator letters in place of flags. Flags are decorative because each option includes its country name. The selected option shows a checkmark; the full-width button names the selected league. With JavaScript, league, market, period and platform changes submit their native GET forms immediately. Without JavaScript, Update overview and Apply remain available.

Desktop spacing follows the reference composition: three selector cards, three performance cards with shaded trends, then six compact settled rows. The shared chat launcher collapses to an icon on desktop to avoid covering table content. Metrics continue to come from repository records rather than the reference image's fixed figures.

Run `node tests/platforms-page.cjs` for headless browser checks of flag loading, league/window navigation, the 1440 ? 900 desktop layout, and 390px mobile overflow. It uses an installed Playwright Chromium cache on Windows, or the executable specified by `CHROME_BIN`. Screenshots are written to the system temporary directory.
