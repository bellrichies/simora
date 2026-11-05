# Data contract and demo regeneration

The proposed [community chat contract](community-chat.md) is separate from the prediction fixtures and remains unimplemented.

This is the current data contract for Simora. Backend integration remains deferred. The interface uses local JSON fixtures through `PlatformDataRepository`, with dependencies assembled in `public/index.php`. No database connection or prediction engine is required.

## Regenerate the demo

From the repository root:

```powershell
php bin/generate-demo-data.php --seed=20261103 --as-of=2026-11-03
php -S localhost:8000 -t public public/index.php
```

If PHP is not on PATH, replace `php` with `C:\xampp\php\php.exe`. Omitting `--as-of` uses today's UTC date. The same seed and date reproduce the same snapshots. Change either to create another demo. Generation is a CLI operation; page requests never generate random history. Each completed file replaces its previous version atomically. Do not regenerate during a Load more session: regeneration intentionally replaces that session's history.

Each platform has six synthetic leagues (England, Italy, Spain, Germany, France and Turkey). Each league has 20 teams, 50 settled weeks with 500 matches, and one scheduled week with 10 matches. There are six markets: Over 1.5, Over 2.5, Under 2.5, Under 3.5, BTTS Yes and BTTS No. Each market stores 40 calibration selections, 120 displayed settled selections, and two upcoming previews. Across three platforms this gives 12,960 displayed settled predictions. Platform seeds produce different matches and prices. Forecasts have stable IDs and market/model relationships; changing a market retrieves different selections instead of applying a new pick to every old result.

The **high-performance-demo** profile deliberately selects 102–108 winning examples out of 120 per market (85–90%). This is biased synthetic data for displaying successful states, not evidence of model accuracy, a backtest or a promise of returns. Scores are shared by all forecasts referring to a match. Complementary markets cannot both win on the same score.

## Source files

| Source | Responsibility |
| --- | --- |
| `config/app.php` | Branding, timezone, currency, stable market IDs/labels, default market, stake limits/increments and batch size |
| `config/platforms.php` | Platform names, league labels, styles and simulated kickoff intervals |
| `database/fixtures/{platform}.json` | Versioned source metadata, teams, leagues, models, weeks, matches and predictions |
| `database/fixtures/account.json` | Demo account, balance, subscription/referral totals, usage quota and activity/notifications |
| `DemoPlatformDataRepository` | Joins fixture relationships, calculates historical evidence and exposes the read model |
| `PlatformDetailsService` | Validates query inputs, selects stored market predictions, derives settlement, filters, batches and simulates |
| `DashboardDataService` | Uses the same records for overview schedules, market hit rates and recent model form |

The JSON store and hydrated platform records are cached within a request. The demo adapter also caches validated, hydrated platform read models and a small league catalog in the system temporary directory, outside the web root. Cache keys fingerprint fixture content, relevant adapter/validator/settlement code and settings; invalid or unwritable cache files fall back to rebuilding. Scheduled timestamps are advanced from the injected clock on cache reads, while account quota is read separately. The Overview also uses a compact cached aggregate per platform rather than loading every prediction row; its totals match the full read model and its schedule advances with the injected clock. The first request after data or code changes rebuilds the cache. This is a local demo optimization, not a replacement for indexed queries, shared production caching or provider ingestion. JSON stays outside `public/`. Invalid/missing JSON produces a generic 503 page or JSON error; technical details go to the server log. The history endpoint sends only the requested batch and the fields needed by the history UI.

## Normalized source entities

IDs are strings; foreign keys refer to IDs rather than names. Week/match/prediction IDs include the platform and season year to prevent collisions across seasons. IDs are stable within a generated snapshot; intentionally changing its seed replaces the demo contents. Arrays of entities are keyed by ID. UTC timestamps use ISO 8601. Prices use integer hundredths (232 means decimal odds 2.32); money uses integer minor units (50000 means NGN 500.00). A pending score is `null`, never zero.

| Entity | Fields |
| --- | --- |
| source | `kind`, `profile`, `seed`, `generated_at`, `notice`; platform root `schema_version=3` (account remains version 2) |
| platform | Root `platform_id`; catalog entry in configuration |
| league | `id`, `name` |
| team | `id`, `name`, `code`, `kit_id`, `shirt_asset` |
| model | `id`, `name` |
| week | `id`, `league_id`, `number`, `season`, `state` |
| match | `id`, `week_id`, `home_team_id`, `away_team_id`, `kickoff_at`, `state`, nullable scores |
| prediction | `id`, `match_id`, `market_id`, `model_id`, `scope`, `odds_hundredths`, `created_at` |

`scope` is `calibration`, `history` or `preview`. Calibration contributes to historical-rate evidence, not the displayed 120-record performance summary. Stored team shirts are illustrative SVG assets; they are not an official licensed kit feed.

Scheduled demo matches store a null absolute kickoff and an offset. The adapter anchors them to the next boundary from the injected `Clock` and configured platform interval (3 minutes, or 5 for BetPawa). It computes the schedule once per request and shares it between cards, metadata and previews. Refreshing after expiry advances this illustrative schedule without inventing new results. A real adapter must return the persisted provider kickoff in UTC, not use this rolling demo rule.

## Repository read model

`find(platformId)` returns `leagues`, `results`, `upcoming`, `picks`, `quota` and `source`. Unknown platforms return empty collections. A supported platform with no scheduled week returns empty `upcoming`/`picks`; with no results it returns empty `results`. The service and templates support these empty states.

Each result contains stable prediction/match/week/market/model/team IDs; league/model/team names; week label; team codes/kit IDs; scores; `pick` label; odds; UTC kickoff; derived `status`; `historical_win_rate`; and `historical_sample_size`. Results sort by kickoff descending, then prediction ID ascending as a deterministic tie-breaker. The service adds separate Lagos date/time labels and the compact mobile timestamp.

Upcoming entries contain week ID/label, league, season, UTC kickoff, actual matches and `match_count=count(matches)`. Previews are keyed by market label, with the same identifiers, shirt asset paths, model name, odds, calculated win rate, sample size and explanation. `PlatformDataRepository` is the replacement boundary; templates do not read JSON files or execute SQL.

## Metric definitions

- A stored prediction wins according to its market and final match scores. Settlement rules live in `MarketSettlement` and are reused by generation, repository and service.
- Under 3.5 (`under_35`) wins when the combined match score is at most three goals; four or more goals loses. This demo rule does not establish a provider market mapping or an authorized feed.
- Model Performance counts displayed settled selections for the selected league and market across all weeks. Hit rate is rounded wins / selections. Week/status filters affect the table only.
- Each history row's historical rate uses **earlier** settled selections by that model in that league and market, including calibration. It excludes the current match and future results. Each upcoming preview uses all earlier settled selections (160 in this fixture). This measures model history, not match-specific win probability.
- Explanations use the stored model, teams, market, price and those evidence counts. No fixed 43%/4.65% reference claims remain.
- Fixed-stake net: win = `round(stake_minor * (odds_hundredths - 100) / 100)`; loss = `-stake_minor`. ROI = net / total staked. Chart points accumulate oldest to newest. PHP and JavaScript consume the same odds/results and stake configuration.
- Overview market pulse aggregates displayed selection wins/counts across platforms. Model form shows the latest five predictions from the selected platform and default market; the first configured platform is the default. Account figures and quota come from the account fixture, not computed activity.

## API and database handoff

`GET /api/platforms/{platform}/predictions?league={league-id}&market=Under+2.5&week=all&status=all&page=2`

```json
{"data": ["prediction row objects"], "error": null, "meta": {
  "page": 2, "next_page": 3, "total": 120, "page_size": 6,
  "demo": true, "source": {"kind": "synthetic", "profile": "high-performance-demo"}
}}
```

Actual `source` also includes seed, generation time and notice. Errors use `{data:null,error:"message",meta:null}` with 404/405/503. `show=N` is the HTML progressive-enhancement option; the API uses numbered batches. Existing label-based URLs remain compatible; stable IDs are already included for future consumers. The demo `access=pro` parameter only changes preview copy, never production authorization.

For database integration, implement the repository using injected PDO/repositories and replace its construction in `public/index.php`. Map the entities to the reference [MySQL schema](../database/schema.sql); it is not applied automatically. Import UTC dates into UTC DATETIME columns and translate them to ISO 8601 in the adapter. Load official kits, schedules, odds and forecasts from the approved source rather than carrying demo-only rules into production.

For a large database, move filtering, counts, settlement aggregates and historical windows into indexed queries/read models; page history by `(kickoff_at, prediction_id)` using a snapshot/cursor. Do not load a full production history into the fixture adapter. Persist snapshots during ingestion, authorize accounts server-side and derive quotas from usage records. Keep the same rendered components and structured JSON envelope. Database-backed authentication, ingestion, migrations, queues and provider integration remain backend work.

## Verification

```powershell
php tests/platform-details.php
node tests/platform-interactions.cjs
node tests/history-load-more.cjs
node tests/mobile-navigation.cjs
npm run build:css
```

Tests cover all platforms/markets, normalized score consistency, exact settlement, totals/ROI/chart reconciliation, past-only evidence, deterministic regeneration, stable batching, settings, query validation, timezone rollover, Overview consistency, and existing browser-script interactions. The JavaScript checks use a small DOM substitute, not a full browser.

The selector obtains leagues from the repository read model, and the board and history API validate and filter by league ID. Each league has independent synthetic records and historical evidence. The first league retains previous IDs for old bookmarks; requests without a league use it by default. A real adapter must return provider-owned league identities and consistent league IDs on schedules, results and picks; do not treat demo country coverage or clubs as provider data.


## Overview mockup refresh

The Overview uses scoped `public/assets/overview.css` and `overview.js`, the server-rendered `views/overview.php`, and its platform card partial. It follows the Platforms design language with four separate account cards, three compact schedule cards, and three equally sized market-pulse, model-form and activity panels. Main panels fit together at 1440 x 900. Mobile uses a two-column account summary and stacked platform/analytics cards with no horizontal page overflow. See [overview-page.md](overview-page.md) for the layout and verification contract.

`GET /dashboard?platform=betking&model_platform=betpawa` filters the displayed cards and selects model form independently. Both values are allowlisted against the platform catalog; invalid values use safe defaults. Selectors submit on change with JavaScript and retain Apply buttons without JavaScript. The repository's compact model records include league names; the service derives the selected model's latest form and win count from the same stored prediction records as platform history. Legacy `model_name` and `model_form` keys retain their previous default-platform meaning. Filtering cards does not change the aggregate market pulse, whose heading describes all platforms.

The header date and schedule timestamps use Africa/Lagos. Existing recurring fixture countdowns remain in use, with clock formatting and an Overview-specific Round started label. Account totals and activity remain fixture data, and the market/model panels retain their synthetic-data disclosure. No subscription, wallet, referral or prediction behavior is inferred from the visual design.

Validation: `php tests/platform-details.php`, `node tests/recurring-countdown.cjs`, `node tests/mobile-navigation.cjs`, PHP syntax checks and JavaScript syntax checks. Browser verification covers desktop fit, mobile overflow and selector navigation.

## Predictions workspace analytics

The Predictions navigation page now includes selected-league performance and recent settled history. `PredictionsHubService` reads the existing repository through constructor injection, applies allowlisted league/market/platform/date-window filters and produces presentation-ready counts, rate, last-five form, daily-rate SVG geometry and six-record pages. No new JSON API or source fixture schema was introduced. See [Predictions workspace](predictions-page.md) for query parameters, timestamp semantics, metric definitions, CSV scope and production repository requirements. The original per-platform history API and board URLs remain unchanged.
