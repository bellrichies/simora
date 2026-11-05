# Overview workspace

`GET /dashboard` (also `/` and `/overview`) follows the Platforms page's visual pattern: compact two-tone heading, shared Overview breadcrumb, synthetic-data notice, consistent card surfaces and borders, lime actions and native filter controls.

## Page structure

| Section | Layout and source |
| --- | --- |
| Account at a glance | Four independent cards for plan, wallet balance, active subscriptions and referrals, supplied by the shared account fixture. Includes existing plan/referral links and an Account settings link. |
| Explore platforms | Three equal schedule cards with platform identity, locally served SVG league flag, selected league, next week, WAT kickoff and recurring countdown. Full-width lime actions retain the existing direct board destinations. All platforms opens the platform directory. |
| Analytics workspace | Three matching panels for market pulse, model form and recent activity. Market rates and model outcomes come from the repository; activity comes from the account fixture. |

The date uses Africa/Lagos. The demo notice identifies account, schedule and performance examples. Activity displays its stored time label rather than adding a hardcoded Just now timestamp.

The platform filter and model selector retain independent GET parameters and submit on change with JavaScript. Apply buttons remain available without JavaScript. Filtering visible platform cards does not alter the all-platform market pulse. Missing schedules and results keep their existing unavailable/empty states. Recurring fixture countdowns retain their current intervals, Round started state and schedule rollover.

At 1440 x 900 the main sections fit in the desktop viewport. Tablet account cards use two columns; the analytics panels use two columns with activity below. On phones the account cards remain in two columns, while platform and analytics panels stack. The shared chat launcher uses a compact icon on desktop. Flags are decorative SVGs, with adjacent country names for accessibility.

## Verification

- `php tests/platform-details.php`: repository and Overview data consistency.
- `node tests/recurring-countdown.cjs`: countdown closure and schedule rollover.
- `node tests/mobile-navigation.cjs`: shared mobile navigation behavior.
- `node tests/platforms-page.cjs`: account/platform/analytics card layout, desktop fit, loaded flags, independent filter navigation, existing board destinations, mobile overflow and global breadcrumbs. Desktop/mobile screenshots are written to the system temporary directory.
- PHP syntax checks for `views/overview.php` and `views/components/platform-card.php`; JavaScript syntax check for `public/assets/overview.js`.
