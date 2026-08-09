# HANDOVER — Live accounting defects found during the country-defaults spec review (2026-08-08/09)

> **For:** the main fixes session. Every item below is a defect in the CURRENT production codebase, discovered and Codex-verified (file:line) during the 9-round adversarial review of `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design.md`. None of these depend on that feature shipping — they are live today for tenants seeded from the current chart-of-accounts seeders. Full evidence trail: `docs/superpowers/specs/reviews/2026-08-08-country-defaults-spec-review.md` (rounds 1–9).
>
> Paths relative to `apps/api` unless noted. `GLS` = `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`, `AS` = `app/Modules/Accounting/Application/Services/AccountingService.php`.
>
> **Owner ruling 2026-08-09:** these are folded into the main fixes lane; the country-defaults spec stays template-scoped and lists G+H below as a named precondition for its per-country certification claim.

## How accounts are resolved (context)

Business code resolves GL accounts via `SystemAccountPurpose` (`Account::findByPurposeOrFail`, throws `RuntimeException` → 500 on HTTP paths) or nullable `findByPurpose`. Charts are seeded per company country by `TunisiaChartOfAccountsSeeder` / `FranceChartOfAccountsSeeder` / `GenericChartOfAccountsSeeder`. A purpose missing from the seeded chart = every company of that country hits the failure mode below. TN and Generic are nearly complete; **France is missing five purposes + one code**. Backfills must follow the purpose-first collision-aware pattern of `database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php` and be unattended-safe (origin/dev push auto-deploys `tenants:migrate`).

## A. FR: `CostOfGoodsSold` missing — silent missing COGS on every FR product invoice

- **Chart:** `FranceChartOfAccountsSeeder.php:124-339` assigns no `CostOfGoodsSold` purpose (TN/Generic have it).
- **Mechanism:** `PostCOGSOnInvoice.php:43-80` resolves it for any invoice with physical product lines and positive COGS (`GLS:1688-1721`). The listener **catches and logs** (`PostCOGSOnInvoice.php:106-112`) — the invoice posts, COGS entries are silently absent. Books-integrity defect, not a crash.
- **Also:** `GLS:4349` `hasInventoryWriteOffAccounts` returns false → POS scrap write-offs silently disabled for FR.
- **Fix:** tenant backfill migration mapping `CostOfGoodsSold` to the proper PCG account (607-family/603 per accountant guidance) for FR companies; then assess historical FR invoices posted without COGS (period-dependent; may need accountant sign-off rather than retro-posting).

## B. FR: `GeneralExpense` missing — 500 on expense posting without category account

- **Chart:** absent from FR (TN/Generic have it).
- **Mechanism:** expense posting falls back to the throwing `GeneralExpense` lookup whenever the expense's category has no `account_id` (`GLS:3913-3938`).
- **Fix:** backfill (PCG 6288 or per accountant); trivial delta.

## C. TN + FR: `SalesDiscount` missing — POS account-charge GL projection dead-letters on transaction discount

*(Corrected per Codex round-10 fact-check — this is an async projection failure, NOT an HTTP 500.)*

- **Chart:** absent from BOTH TN and FR; Generic has it (`GenericChartOfAccountsSeeder.php:203-212`).
- **Mechanism:** device-authored POS events are committed by ingestion FIRST, with projection jobs queued after commit (`app/Modules/Fiscal/Application/Services/OutboxIngestor.php:228-241,960-977`). `TreasuryAccountChargeBridge` (`:59-101`) then performs the posting whose unprechecked throwing `SalesDiscount` lookup fires for any positive transaction discount (`GLS:3807-3827`). The throwing projector is **retried and eventually dead-lettered** (`app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:391-421,433-448`) — the receipt/printable event exists while its accounting projection is silently absent.
- **Fix:** backfill TN (709-family PCN) + FR (709 PCG) **and** recover/replay the dead-lettered account-charge projections after the account exists — seeding alone does not repair already-dead-lettered events.

## D. FR: `CustomerAdvance` missing — 500 on ordinary payment allocation

- **Chart:** absent from FR (TN `TunisiaChartOfAccountsSeeder.php:178-186`, Generic have it).
- **Mechanism:** `app/Modules/Treasury/Application/Services/PaymentAllocationService.php:307-365` posts a customer advance for an order allocation or excess payment; `GLS:396-417` throws on the missing purpose. Plain authenticated Treasury route — no module gate (`app/Modules/Treasury/Presentation/routes.php:35,253-259`).
- **Fix:** backfill FR `CustomerAdvance` (PCG 4191).

## E. FR: `SupplierAdvance` missing — 500 on supplier prepayment refund

- **Chart:** absent from FR.
- **Mechanism:** prepayment-refund route `app/Modules/Treasury/Presentation/routes.php:214-217` → `PaymentRefundController.php:231-262` (`refundPrepayment` action) → `app/Modules/Treasury/Domain/Services/VendorRefundService.php:145-186` → unprechecked throwing lookup `GLS:496-517`.
- **Fix:** backfill FR `SupplierAdvance` (PCG 4091).

## F. FR: literal code `624` absent — expense categories silently OMITTED (couples with B)

*(Corrected per Codex round-10 fact-check — on the current FR chart the categories are skipped, not collapsed.)*

- **Mechanism:** `database/seeders/ExpenseCategorySeeder.php:37-69` maps categories to literal codes `613/615/616/624/626`; when a code is missing it resolves a **nullable** `GeneralExpense` fallback and, if that is ALSO null, **skips the category row entirely** (`:54-69`). France lacks both literal `624` AND the `GeneralExpense` purpose (`FranceChartOfAccountsSeeder.php:124-339,243-252`), so on the France-default `ParapharmacySeeder` path (`:141-162,630-650`) the Transport category is silently **omitted** — as is the intentionally null-mapped "Fournitures & Divers" category.
- **Fix (coordinate with B):** BOTH backfills are needed — PCG `624` ("Transports de biens et transports collectifs du personnel") repairs Transport; the `GeneralExpense` backfill (item B) repairs the null-mapped category. Tests: pin the current missing-category state first, then prove the combined backfills create all expected categories. The loud-failure seeder change is specced in the country-defaults lane §5.2 — coordinate to avoid double work.

## G. Tenant tax API: `is_stamp_duty` configurable for non-timbre countries — no capability check

- **Mechanism:** any tenant user with `taxation.tax_configurations.manage` (`app/Modules/Taxation/routes.php:16-31`) can create/update country-scoped configurations with `applies_to=DOCUMENT_TOTAL`, `is_active`, `is_stamp_duty` — **no check that the country actually has timbre** (`app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php:70-117,127-155`). The web UI exposes the toggle (`apps/web/src/components/organisms/TaxConfigFormModal/TaxConfigFormModal.tsx:258-270`); `tests/Feature/Taxation/TaxConfigurationManagementTest.php:139-157` proves persistence.
- **Consequence:** an FR (non-timbre) company can acquire an active stamp configuration; combined with H, ordinary documents then flow into stamp-duty GL accounting.
- **Fix:** capability guard in store/update (reject `is_stamp_duty=true` — and arguably any `DOCUMENT_TOTAL` conversion to stamp — for countries not timbre-capable), plus UI alignment. Country capability source of truth: today only `CountryTaxConfigurationRegistry` (`app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php:10-31`) + seeders (`TunisiaTaxConfigurationSeeder.php:86-145` sets timbre; `FranceTaxConfigurationSeeder.php:50-76` sets `is_stamp_duty=false`). The country-defaults spec introduces a central versioned `CountryAccountingCapabilities` registry (v1 timbre = `{TN}`) — reuse it here rather than inventing a second authority.
- **Tests:** FR store+update negatives, TN positive.

## H. `stamp_duty_amount` aggregates ALL document-level taxes — generic doc taxes enter the stamp GL path

- **Mechanism:** `app/Modules/Taxation/Domain/Services/TaxCalculationService.php:273-299` sums **every** applicable `DOCUMENT_TOTAL` configuration into `documentTaxTotal` **without filtering `is_stamp_duty`**. Credit-note confirmation persists that whole value as `stamp_duty_amount` (`app/Modules/Document/Presentation/Controllers/CreditNoteController.php:284-305`; same in `CreditNoteService.php:96-116`). GL then treats any positive value as real timbre and performs the throwing `PurchaseStampDuty`/`SalesStampDutyPayable` lookups (`GLS:261-291`).
- **Consequence:** a non-stamp document-level tax is booked as stamp duty (misclassification on TN-shaped charts; throwing lookup / wrong-account risk elsewhere). Compounds with G.
- **Fix:** compute/persist `stamp_duty_amount` from `is_stamp_duty=true` configurations ONLY; decide the treatment of generic `DOCUMENT_TOTAL` taxes (own named total + GL design, or reject until designed). End-to-end test: non-stamp `DOCUMENT_TOTAL` tax on a credit note never enters the stamp-purpose path.
- **Note:** fiscal-adjacent — route through the fiscal/treasury reviewer gates.

## I. Tolerance alert can be lost while the GL entry is skipped

- **Mechanism:** `app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:441-459,524-543` prechecks tolerance purposes and returns without posting; but `recordTolerancePurposeMissingAlertSafely()` (`:567-583`) catches ALL persistence failures of the `audit_events` write, logs, and continues. Reachable state: **no GL entry AND no durable record** — silent books gap.
- **Fix:** make the durable alert fail-closed (transactional outbox / retry) or fail the projection acknowledgment when the alert cannot be persisted.

## J. Dead code: `UninvoicedDeliveryNoteService` has zero production callers

- `app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php` — no caller in `app/`, `routes/`, `config/`, `database/`, `bootstrap/` (feature tests call it directly: `tests/Feature/Compliance/UninvoicedDNReportTest.php`, `UninvoicedDeliveryNoteScalingTest.php`). The `UninvoicedRevenue` purpose is consequently unreachable. Wire the period-end accrual it implements, or remove the service.

## Suggested sequencing

1. **C, D, E** (ordinary-flow failures; TN is the launch country — C affects TN) — one backfill wave. Note the failure modes differ: **D and E are request-path HTTP 500s; C is a silently dead-lettered accounting projection** and carries its own recovery obligation — after the backfill, inventory dead-lettered account-charge events and replay them (`fiscal:retry-projections`, `app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php`), then validate the replayed GL entries.
2. **A, B, F** (FR-only; silent-books or fallback defects) — second wave, with accountant input on account numbers.
3. **G + H** together (tax surface; fiscal-reviewer gated; precondition for the country-defaults certification claim).
4. **I** (treasury hardening), **J** (cleanup) — opportunistic.

All chart backfills: idempotent, purpose-first, collision-aware, unattended-safe under auto-deploy; `permission:cache-reset` not needed (no permissions touched).
