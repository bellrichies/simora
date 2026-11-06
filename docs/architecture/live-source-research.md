# Scheduled virtual football data source assessment

Research date: 2026-11-06. Scope: Nigerian SportyBet Scheduled Virtuals, betPawa pawaLeague and BetKing Scheduled Leagues. This is a procurement assessment, not source approval or an alternative backend specification; V4 remains authoritative.

## Recommendation

Request an operator-authorized read-only feed or scheduled export from each bookmaker first. This is the strongest route to the actual bookmaker event IDs, offered quotes, suspension times and settlement corrections. A feed from a virtual-game supplier is acceptable only after proving it represents the same event instance and obtaining the bookmaker-specific odds and settlement mapping. Matching league names and team names do not establish identical events, odds or results.

No reviewed primary source establishes a publicly licensed API that covers all three target scheduled products. No exact supplier attribution for these three products was verified. Do not purchase an aggregator plan or implement a connector on the assumption it has this coverage. No price or latency guarantee has been verified.

| Target | Verified primary evidence | Recommended source route | Unresolved |
| --- | --- | --- | --- |
| SportyBet Nigeria Scheduled Virtuals | Its [football help](https://www.sportybet.com/ng/m/help/how-to-play/virtuals/football) describes scheduled virtual football and shared matches/results. | Ask SportyBet's official business/support channel for its data licensing contact and the specific scheduled-game supplier. | Licensed access, supplier, exact league IDs, all six totals selections plus BTTS pair, quote history, correction rules. |
| betPawa Nigeria pawaLeague | The [official product page](https://www.betpawa.ng/pawaleague-virtuals) lists English, Spanish and Italian leagues and totals/BTTS market families. | Ask betPawa Nigeria for a business data agreement or an introduction to its authorized pawaLeague supplier. | Individual 1.5/2.5/3.5 lines are not proven by a generic totals listing; feed access and correction replay are unverified. |
| BetKing Nigeria Scheduled Leagues | The [official scheduled help](https://m.betking.com/en-ng/help-centre/virtuals/virtuals-scheduled-leagues) describes scheduled virtual matchdays. | Ask BetKing/KingMakers for a licensed scheduled-league data feed, not its separate SRL or instant game feed. | Exact supplier, event-instance mapping, all eight selections, quote availability evidence, authoritative result revisions. |

The [BetKing product page](https://m.betking.com/en-ng/virtuals) and older [desktop help](https://www.betking.com/help/general-help/virtual-scheduled-leagues/) differ in league counts and contain different timing descriptions. Treat these pages as discovery evidence; obtain the current feed's actual kickoff timestamps rather than calculating them from a recurring countdown.

## Documented supplier candidate

[Sportradar Unified Odds Feed virtual sports](https://docs.sportradar.com/uof/introduction/key-concepts/virtual-sports) is the strongest technically documented candidate found in this research. It exposes virtual producers, season schedules, event identities, odds and settlements using authenticated access. It also documents [settlement rollback](https://docs.sportradar.com/uof/data-and-features/messages/event/rollback-bet-settlements) and [bet cancellation](https://docs.sportradar.com/uof/data-and-features/messages/event/bet-cancel). Request a coverage demonstration and licensing quote; this does **not** establish that it supplies any of the three target products or reproduces bookmaker-specific prices.

[GoldenRace virtual football](https://goldenrace.com/virtuals/virtual-sports/virtual-football) is another supplier to investigate only if an operator confirms it owns the target game instance. Product availability alone is not proof of target coverage. No verified comparative cost, completeness or latency evidence justifies choosing it over an exact operator feed.

## Access constraints

The [betPawa Nigeria terms](https://www.betpawa.ng/terms), section 6.4, list automated scripts/bots and non-personal use among prohibited activities. [SportyBet terms](https://www.sportybet.com/ng/help?nav=terms-and-conditions) restrict software use and interference by robots. These consumer terms do not grant Simora commercial machine-processing rights. Obtain a separate written agreement rather than treating browser-visible requests as a licensed API. This finding is a reason to use the commercial access route, not a legal determination.

## Questions to send through official channels

Draft only; no message has been sent:

> We are developing Simora, a read-only virtual football analytics application. We seek licensed fixtures, fixed UTC kickoff timestamps, bookmaker-specific pre-event odds and market statuses, results, voids and settlement corrections for your Nigerian scheduled virtual football leagues. Please identify the current game supplier and the available API, push feed or authorized export, including a sandbox and sample payloads. We need stable event/season/round IDs, all Over/Under 1.5, 2.5 and 3.5 selections and BTTS Yes/No, source event timestamps, revision/sequence identifiers, replay and recovery semantics, and correction/void rules. Please specify commercial display, retention, historical storage, derived analytics/model training rights, geographic restrictions, service levels, rate limits and pricing. We are not requesting wagering execution access.

Before accepting a source, compare samples against the exact operator league and event instances, capture our own trusted receipt times, test missed-message recovery and late revisions, and verify every market independently. A retrospective result export does not establish historical pre-event odds availability. Store approval evidence and permitted uses in the canonical source registry only after the agreement and mapping are verified. No source, grant or fabricated odds have been seeded by this research.
