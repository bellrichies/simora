# V1 access policy ? approved reconciliation

Approved by the user on 2026-11-06 in the reply approving the reconciled policy and specifying allocations/comparison semantics. Approval reference: `user-approved:simora-access-v1:2026-11-06`. The earlier pending proposal is superseded by this record. This policy supplements the authoritative V4 architecture; it is not another backend blueprint.

| Feature | Trial | Standard | Pro | Max |
| --- | --- | --- | --- | --- |
| Price, NGN | 2,000 | 30,000 | 50,000 | 80,000 |
| Fixed validity | 24 hours | 14 days | 30 days | 30 days |
| Models | spe | spe, mlpe | spe, mlpe, beve | spe, mlpe, beve |
| Markets | over_15, under_35 | over_15, under_35, over_25, btts_yes | All eight canonical markets | All eight canonical markets |
| Analyses | Unlimited per subscription | Unlimited per subscription | Unlimited per subscription | Unlimited per subscription |
| Simulator runs | Unlimited | Unlimited | Unlimited | Unlimited |
| Outcomes per simulation | 50 | 120 | 250 | 1,000 |
| Prediction history | 3 days | 14 days | 90 days | All retained |
| Saved strategies | 0 | 3 | Unlimited | Unlimited |
| CSV exports/day | 0 | 2 | 10 | 50 |
| Comparison | None | Basic | Full | Full |
| Comparison history | None | Last 14 days | All retained | All retained |
| Advanced analytics | No | No | Yes | Yes |
| Inert automation configurations | 0 | 0 | 2 | 5 |
| Future concurrent trading sessions | 0 | 0 | 1 | 2 |
| Live execution | Disabled | Disabled | Disabled | Disabled |

Daily metered quotas reset at 00:00 Africa/Lagos with no rollover; timestamps remain UTC. Unlimited analyses have no daily quota/reset. Expiry disables premium access immediately. Trial is available once per verified account. Failed and duplicate requests consume no additional quota. Unknown paid capabilities default to denied.

Basic comparison allows calibrated probability (only when valid), historical win rate/outcomes, average recorded odds, realized ROI/net profit, equity charts, risk/drawdown, streaks, calibration and market ranking within the permitted model/market scope. Full adds model agreement, custom dates and report export, across all three models/eight markets and retained history. Pro's 90-day raw prediction history and retained Full comparison history are separate permissions; Full comparison does not silently remove the raw-history limit.

Financial performance must use settled publications, declared cohorts, authentic recorded odds and sample sizes. Risk-adjusted rankings still require sufficient historical evidence; permission is not evidence of model eligibility or profitability. Entitlements never bypass source/model quality, publication, privacy or retention gates.

Approved comparison keys are `comparison.basic`, `comparison.full`, `comparison.export`. CSV report exports share the plan's daily CSV export allowance. This shared-budget reconciliation avoids creating an unbounded comparison-export route beside the approved CSV limit. Unused daily capacity is not rolled over or reset merely by upgrading; the new approved ceiling applies to today's already-consumed count.

Renewal renews applicable entitlements. Upgrade takes effect after verified payment; downgrade takes effect at the current paid period end. These rules do not activate payment code: a trusted provider-confirmation handler must perform the appropriate audited subscription transition. Trial eligibility must be checked before charging/activation as well as when access is consumed.

Automation configuration permission is for inert stored configuration only. The future session counts do not enable execution or concurrent live sessions. Every plan's execution remains disabled pending independently approved provider, security, legal, consent and risk gates.

The catalog is persisted as versioned approved snapshots by additive migration `081_approved_access_policy`; old `month` price metadata remains intact, while new Pro/Max rows use `thirty_days`. No customer subscription/payment/account role is created by that migration. Server code is versioned as `simora.access.v1`; v1 snapshots are immutable in the application and unexpected edits fail closed. Later admin policy changes require an approved new policy version and additive publication.

Implementation evidence, actual tests, API changes, schema/FK report, remaining dependencies and rollback are in [the approved-policy implementation report](approved-access-policy-implementation.md). Earlier source/model/publication, secure identity issuance and payment release gates remain in force. This approval does not declare unsupported saved-strategy/configuration storage, admin UI, report exporters or a payment integration operational.
