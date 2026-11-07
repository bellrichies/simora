# Phase 10 implementation report — Paystack and subscription credit

Date: 2026-11-06. Authority: [master backend blueprint V4](../SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md), including its Phase 10 provider/scope decision. This is an implementation report, not another backend blueprint.

## Scope and status

The user selected Paystack and explicitly approved subscription-only credit accounting with no payouts. The implementation supports Paystack test-mode hosted checkout, durable signed webhook receipt, independent verification, missed-event reconciliation, fixed-term manual subscription purchase/renewal and balanced subscription accounting. **Local implementation verified; live release blocked.** Payments remain disabled by default; no keys were provisioned, provider API calls made, charges collected, payouts made or customer subscriptions seeded. Tests use explicitly isolated financial contract fixtures, never prediction/model training data.

Live merchant/account approval, tax determination, legal invoice numbering, refunds/cancellation/proration and operational release evidence are unresolved. The adapter rejects live keys/domain; production cannot be enabled with a flag. The sandbox quote authority's zero tax and unissued invoice references are explicitly test-only. Fixed periods remain 24 hours, 14 days and 30 days; all eight markets and approved model/plan allocations remain intact. Existing SSR billing/wallet previews still use their isolated demo repository and never authorize server access or initiate payment.

At phase start, the local tree contained prior uncommitted Phase 7/8/9 work, including migrations 080/081, API/access/simulator files and UI changes. Those changes were retained. The inspected local database had six applied migrations, 42 InnoDB tables and 56 foreign keys. The obsolete AGENTS blueprint path did not exist; AGENTS now points to the user's authoritative V4 file. Older `docs/plans-billing.md` is explicitly marked historical to prevent its proposed parallel schema becoming an alternate backend design.

## Behavior and API diff

All new endpoints use `{data,error,meta}` JSON and `Cache-Control: no-store`. No existing SSR/history response contract changes.

| Endpoint | Behavior |
| --- | --- |
| `POST /api/v1/billing/checkouts` | HTTPS session, CSRF and `billing.checkout`. Accepts exactly string `plan`, `operation` (`purchase` or `renewal`), `request_key`. Amount, currency, owner, duration, price and callback are server controlled. Returns 202 with opaque payment ID, server reference, state, nullable hosted checkout URL and sandbox environment. Same key/terms replays; changed terms conflict. No provider network operation on the HTTP request. Disabled/missing approval returns 503. |
| `POST /api/v1/billing/webhooks/paystack` | No browser session/CSRF. Raw body bound 256 KiB; authenticated timestamped HMAC SHA512 with key rotation and +/-300-second window. Explicit legacy compatibility mode only, without downgrade from invalid modern headers. Durable receipt and canonical outbox commit before 200. Invalid signature 401; invalid structure 422; oversized body 413; unavailable storage/configuration 503. Receipts do not activate subscriptions. |
| `GET /api/v1/billing/invoices` | HTTPS session and `billing.read`; owner-bound latest 100 records, exact minor-unit strings and UTC creation time. `legal_document_issued=false`; no fabricated legal invoice number. This preserves the canonical V4 billing path. |
| `GET /api/v1/wallet` | Same verified owner permission. Subscription-only accounting summary with exact balance string or null when no account exists, `payouts_enabled=false`. Cache mismatch fails closed. |
| `GET /api/v1/wallet/transactions` | Owner-bound latest 100 journals with customer-side debit/credit strings and UTC posting time. |

Wrong methods return 405 with Allow. There is no payout, withdrawal, peer-transfer, generic funding, provider refund, card reuse, auto-renewal or trade execution API. Upgrade/downgrade/proration operations fail closed pending their financial lifecycle decisions. Existing `GET /api/v1/plans` continues advertising `purchase_available=false`; the preserved SSR preview is not a live checkout integration. Canonical `/api/v1/billing/invoices` was reconciled during implementation before final regression testing; no alternate `/api/v1/invoices` route was published.

Trusted CLI handlers: `php bin/payment-job.php checkout <internal-payment-id>`, `webhook <receipt-id>` or `reconcile <internal-payment-id>`. Initialize claims the existing reference once before the network call. A timeout/crash after that claim requires reconciliation; retry never silently generates another checkout/reference. The user retries the same checkout request key to read a subsequently stored hosted URL. Verification happens outside the financial transaction; its evidence is checked again for identity, provider account/environment, exact amount/currency, customer email hash, payment time within invoice validity and freshness inside the transaction. Transaction IDs retain unsigned 64-bit decimal strings.

Only independently verified success commits payment, paid invoice, subscription, immutable confirmation event, funding/application journals, audit, in-app notification and outbox together. Customer-row and ordered account locks serialize competing handlers; event/advisory locks and database uniqueness support replay. Pending/failed lookups never fulfill a success notice and remain reconciliation candidates. Unknown references, mismatched/stale evidence, duplicate charges and subscription conflicts are quarantined. Signed unrelated events, including transfer events, are recorded as ignored without financial effects. Reconciliation can observe `reversed`, record an inbound review observation and put the payment into review; this removes its derived entitlement proof without initiating a refund or altering posted accounting. Paystack dispute/refund notification workflows and their policy-specific resolutions are not claimed complete.

Access now requires a confirmation event linked to a successful payment and paid owner/plan-matched invoice. Production repository defaults require live evidence. Sandbox evidence is permitted only through explicitly sandbox-scoped test repository instances, never by HTTP parameters. This intentionally closes the former event-text-only activation seam; existing manually inserted confirmation text is insufficient and requires an audited reconciliation rather than automatic conversion.

## Migration and foreign keys

`090_payments_wallet_gated.sql` depends on `081_approved_access_policy` and `030_source_provenance`. It reuses the canonical outbox and existing subscription/identity/plan tables. No duplicate tables or parallel payment-attempt/quote/provider-event schema was introduced. New tables: `invoices`, `payments`, `payment_webhooks`, `refunds`, `wallet_accounts`, `wallet_journals`, `wallet_postings`, `wallet_balances`, `wallet_adjustments`.

The local apply reported prior migrations already applied and 090 applied. Verification at **2026-11-06T16:43:20Z**: MySQL **8.4.13**, **51 InnoDB tables**, **78 FKs**, **eight markets**; physical tables/FK columns and all seven ledger checksums match migration files. Fresh isolated installs and idempotent reruns are tested. MySQL DDL is not transactional; failed/partial application is a manual recovery condition, never silently replayed.

22 added foreign keys:

| Relationship | Constraint(s) |
| --- | --- |
| Invoice to user and pinned plan | `fk_invoice_user`, `fk_invoice_plan` |
| Invoice to owner-matched and plan-matched subscription | `fk_invoice_subscription_owner`, `fk_invoice_subscription_plan` |
| Payment to owner-matched invoice; invoice back to its exact owner/invoice/payment | `fk_payment_invoice_owner`, `fk_invoice_paid_payment` |
| Webhook to payment | `fk_webhook_payment` |
| Inbound refund review observation to payment and receipt | `fk_refund_payment`, `fk_refund_webhook` |
| System confirmation event to payment | `fk_subscription_event_payment` |
| Existing outbox to payment or receipt | `fk_outbox_payment`, `fk_outbox_payment_webhook` |
| Customer credit account to user | `fk_wallet_account_user` |
| Journal to user, exact owner/invoice/payment tuple, invoice and reversed journal | `fk_wallet_journal_user`, `fk_wallet_journal_payment_owner`, `fk_wallet_journal_invoice_owner`, `fk_wallet_journal_reversal` |
| Posting to journal and account | `fk_wallet_posting_journal`, `fk_wallet_posting_account` |
| Reconciliation cache to account | `fk_wallet_balance_account` |
| Adjustment to reversal journal and authorized actor | `fk_wallet_adjustment_journal`, `fk_wallet_adjustment_actor` |

Composite FKs preserve ownership. Outbox CHECK requires exactly one ingestion batch, payment or webhook owner; ingestion's existing FK is retained. Subscription event actors may be null only when linked to a payment. Invoice totals must exactly equal subtotal+tax, bounded at 1,000,000,000 minor units. Posted lines have exactly one positive debit/credit side. Customer/control accounts have constrained ownership, journal event keys are unique, reversals reference one original journal, and balance rows are caches. All added FKs use restrictive deletion; history is retained.

Each direct verified purchase posts a funding journal and an application journal, each with two equal opposite integer postings; net customer credit is zero. No arbitrary user-supplied financial amount reaches the ledger. Corrections require `billing.credit.adjust`, HTTPS/session/CSRF, request and approval references; they append full reversal journals/adjustments and cannot debit unavailable customer credit. No adjustment HTTP endpoint or default permission assignment is provided. Service code never edits/deletes posted journals/postings. The current broad local app DML role and lack of aggregate DB enforcement remain explicit live release blockers, not claims of database-level append-only security.

## Changed paths in this phase

- Authority/configuration: `AGENTS.md`, `.env.example`, `config/payments.php`, `config/billing.php`, `docs/SIMORA_MASTER_BACKEND_BLUEPRINT_V4.md`, historical marker in `docs/plans-billing.md`, this report, `IMPLEMENTATION_STATUS.md`.
- Schema/tooling: `database/migrations/090_payments_wallet_gated.sql`, `src/Infrastructure/MigrationRunner.php`, `bin/verify-database.php`, `bin/verify-payments.php`, `bin/payment-job.php`, `bin/test-payments.ps1`, suite parameter in `bin/test-access.ps1`.
- Contracts: `src/Contracts/PaymentProvider.php`, `PaystackTransport.php`, `InvoiceQuoteAuthority.php`.
- Domain: `src/Domain/Payments/PaymentException.php`, `PaymentApproval.php`, `ApprovedInvoiceQuote.php`, `ProviderEvidence.php`, `VerifiedWebhook.php`.
- Provider/DI: `src/Infrastructure/PaystackProvider.php`, `CurlPaystackTransport.php`, `PaymentServiceFactory.php`, `SandboxInvoiceQuoteAuthority.php`, `UnavailablePaymentProvider.php`, `UnavailableInvoiceQuoteAuthority.php`.
- Transactions/access/accounting: `src/Repositories/PaymentRepository.php`, strengthened `AccessRepository.php`, `src/Services/PaymentLifecycleService.php`, `SubscriptionCreditLedger.php`, extracted `SessionAuthorization.php`, delegation in `AccessService.php`.
- Routing/SSR: `src/Http/PaymentController.php`, `public/index.php`, `src/DashboardController.php` (explicit demo preview configuration only).
- Verification: `phpunit.xml.dist`, `tests/PaystackProviderTest.php`, `PaymentsIntegrationTest.php`, `Support/PaystackTestTransport.php`, `payment-race-worker.php`, updated trusted fixtures in `AccessIntegrationTest.php`, additional HTTP checks in `LegacyRoutesTest.php`.

## Tests actually run and outputs

```text
php vendor/phpunit/phpunit/phpunit
OK (190 tests, 786 assertions)

powershell -NoProfile -File bin/test-payments.ps1
OK (17 tests, 87 assertions)

powershell -NoProfile -File bin/test-access.ps1
OK (20 tests, 107 assertions)
```

The default suite includes provider HMAC rotation/raw bytes/timestamp replay/explicit legacy/downgrade rejection, unsigned 64-bit IDs, exact amounts, hosted URL validation, provider-operation allowlist, existing model/simulator tests and real SSR/history HTTP regressions. Isolated MySQL tests cover replay/conflict-safe capture, exact atomic activation/credit/notification, production denial of sandbox proof, evidence amount/currency/identity/ID/time rejection, pending retry, renewal boundaries, one verified Trial, owner/CSRF restrictions, missed-webhook reconciliation, reversal review, accounting-cache failure rollback, one-time checkout claim, internal append-only correction and two independent concurrent workers. Scratch databases and their temporary grants are cleaned by the harness.

Scoped PSR-12: exit 0, no errors/warnings. PHP syntax checks passed for all 35 added/affected PHP files. `git diff --check` passed after removing an extra documentation EOF blank line; Git's existing LF/CRLF notices remain informational. `php tests/migration-checksum.php`, `tests/mysql-prerequisites.php`, `tests/identity.php`, `tests/billing.php` all printed their respective passed messages. `php bin/verify-payments.php`: all six invariant counts zero; local payment/journal counts both zero, payouts false. Empty-main-database verification is not a loaded-ledger or production certification. The isolated suite verifies nonempty balanced journals and rollback separately.

Intermediate failures were corrected and rerun: a PHPUnit helper name collided with final `TestCase::count`; a system notification passed null into an actor-int audit signature; activation used the PHP clock while entitlement checks use DB UTC. Renaming the helper, allowing a payment/system audit actor, and using the DB clock for subscription starts resolved them. Formatting warnings were corrected. No product threshold or signature check was weakened to pass tests.

Not run: actual Paystack sandbox/live network checkout/webhook delivery, real merchant/account authentication, Redis delivery/retry/dead-letter deployment, scheduled reconciliation, TLS login/session issuance, live invoice/tax/refund/proration behavior, DB privilege hardening, backup restore, load/security certification or hosted CI/deployment. No claim that these are operational.

## Remaining gates and restart/rollback

1. Obtain and validate the approved merchant test account and test keys/signing secrets through environment-managed secrets. Configure HTTPS callback and webhook URLs; do not place credentials in fixtures/docs/logs. `config/payments.php` and `.env.example` list required keys; `SIMORA_PAYMENTS_ENABLED=false` remains the deployed default. An explicit unexpired sandbox test window, PHP cURL/TLS support, verified sessions and deliberate RBAC grants are required to exercise it. No role is silently granted billing privileges.
2. Integrate canonical MySQL outbox with Redis delivery, bounded retries, dead-letter handling and scheduled reconciliation. CLI commands are real idempotent handlers, not a deployed dispatcher. Payment HTTP endpoints never acknowledge undurable state. Unknown initialization outcomes retain the same reference for operator reconciliation; do not clear the one-time claim to force a second charge.
3. Obtain production tax/legal invoice, refund/cancellation/proration and merchant approvals; review charge expiry, disputes and manual subscription-change resolution. Provide/test the corresponding live quote authority and provider mode in a separately authorized continuation. Production remains hard disabled. Automatic renewal and proration were not invented from plan metadata.
4. Harden money-table privileges and append-only/aggregate ledger enforcement without granting SUPER or changing global MySQL trust settings. Review read-only invariant reports, owner isolation, session/TLS behavior and threat/race/load results. No failed test fixture is imported into application accounts.

Operational rollback: set `SIMORA_PAYMENTS_ENABLED=false`, stop payment consumers and preserve all receipts, references, outbox events, invoices, subscriptions, journals, postings, adjustments and audit. Revert only Phase 10 code hunks while retaining schema-aware runner/verifier and compatible access-proof checks; do not roll back to event-text activation. Preserve all earlier uncommitted Phase 7/8/9 changes. Prefer an additive corrective migration; never edit/delete applied 090 or use destructive down migration against financial history. A DB reversal requires an audited backup/restore or explicit corrective plan, not automated DDL rollback. Quarantined charges and cache mismatches require review; do not fabricate success or silently repair balances.

Restart: inspect Git and the seven-entry migration ledger/checksums, run `php bin/migrate.php 090_payments_wallet_gated` idempotently with configured migration actor/build, `php bin/verify-database.php`, `php bin/verify-payments.php`, both isolated harnesses and default PHPUnit. Resume only a separately requested Phase 10 integration/release continuation once its dependencies are supplied. Phase 11 and all trading/payout work remain unstarted.
