# Application navigation

Every rendered application page includes exactly one breadcrumb above its main content header, supplied by `views/components/breadcrumbs.php` from the shared layout. Page templates do not supply their own breadcrumb markup.

| Destination | Full hierarchy |
| --- | --- |
| Overview | Overview (current) |
| Top-level pages | Overview / Platforms, Plans & billing, Wallet, Referrals, Reviews, Support, Settings, Notifications or Results |
| Predictions workspace | Overview / Platforms / selected platform / selected league |
| Individual prediction board | Overview / Platforms / selected platform / selected league / Prediction board |
| Unknown route | Overview / Page not found |

Breadcrumb labels and URLs come from validated route metadata and service read models. They never echo raw query input. Ancestors are links; the final label has `aria-current="page"`. The Platforms ancestor retains the current platform's selected league. Platform ancestors open the platform prediction workspace; league ancestors on boards open that league in Predictions. This is a stable page hierarchy, independent of browser history, so direct links show the same complete path.

The nav uses a semantic ordered list, an accessible Breadcrumb label, decorative CSS slash separators, keyboard focus indicators and wrapping on phones. Existing page-specific breadcrumbs were removed from Wallet, Referrals, Reviews, Support, Settings and platform boards.

Verification: `node tests/platforms-page.cjs` checks a single breadcrumb and current-page marker across all application routes, the selected prediction platform/league, and responsive navigation. `npm run build:css` regenerates shared styles from `assets/css/app.css`.
