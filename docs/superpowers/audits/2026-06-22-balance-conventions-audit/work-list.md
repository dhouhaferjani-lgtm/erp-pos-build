# Ordered Work List — Balance And Event-Sourcing Hardening

Generated from Phase 0 audit on 2026-06-22 against HEAD `5cf94a1f04731258704792b7aa07f714e9c718c3`.

## H-1 — Production invoice/credit-note AR lines omit `partner_id`

status: DONE
severity: HIGH  
source: seed backlog + `05-ar-ap-production-wiring.md`

Claim: The canonical production invoice/credit-note writer is `AccountingService`, reached through `InvoicePostedListener`, and its AR lines omit `partner_id`, so partner AR subledger queries ignore posted document AR.

Evidence:
- `apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:29`
- `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:144`
- `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:260`
- `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:41`

Acceptance criteria:
- Production invoice posting writes the customer receivable line with `partner_id`.
- Production credit-note posting writes the AR reversal line with `partner_id`.
- Partner balance refresh sees the posted AR movements.
- Customer receivable reconciliation reports no partnerless production AR rows for these paths.

Test plan:
- Extend production-flow invoice/credit-note feature tests.
- Run `php artisan test --filter InvoiceAndCreditNoteGLIntegrationTest`.

Scope boundary: `AccountingService` invoice/credit-note GL creation and directly related tests only.

Outcome:
- Added production-path assertions for invoice and credit-note AR `partner_id`.
- Added guard assertions that revenue/VAT lines remain partnerless.
- Tagged only the customer receivable AR lines in `AccountingService`.
- Verification: `php artisan test tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`; `php artisan test --filter PartnerBalanceServiceTest`; `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Application/Services/AccountingService.php`; `./vendor/bin/pint --test app/Modules/Accounting/Application/Services/AccountingService.php tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`.
- Reviews saved in `docs/superpowers/reviews/2026-06-22-h1-ar-partner-id-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h1-ar-partner-id-opus-fallback-review.md`; true Opus review remains pending.

## H-2 — Non-POS AR/AP/advance/clearing journal entries have no production posting cycle

status: DONE
severity: HIGH  
source: seed backlog + `06-draft-post-lifecycle.md`

Claim: Several non-POS AR/AP/advance/clearing workflows create Draft entries and refresh balances, but no production posting cycle makes those entries Posted; `PartnerBalanceService` only counts Posted entries.

Evidence:
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:291`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:580`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:753`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1322`
- `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:46`

Acceptance criteria:
- Confirmed production draft-creating paths either post immediately or feed a real accounting-cycle poster.
- Balance refresh happens only after posting, or is event-driven from posting.
- Manual journal posting uses the same posting semantics or emits equivalent events.

Test plan:
- Add targeted tests for the smallest confirmed flow first.
- Run the corresponding feature test plus `PartnerBalanceServiceTest`.

Scope boundary: Process one flow at a time; do not rewrite the entire accounting lifecycle in one patch.

Outcome so far:
- H-2.1 DONE — production customer payment-received paths now post when a real actor is supplied and refresh balances only after posting.
- `GeneralLedgerService::createPaymentReceivedJournalEntry()` preserves the existing positional description parameter, accepts optional `user` and `currencyCode`, posts with explicit currency when supplied, and leaves legacy no-user callers draft-only for later H-2 slices.
- `PaymentAllocationService` and `PaymentController` pass the actor and payment currency for live customer payment-received GL entries.
- `PaymentController::storeMultiple()` now creates posted customer-payment GL entries for manual and automatic excess invoice allocations when the payment repository has an accounting account.
- H-2.2 DONE — live customer advance/prepayment GL entries now post when created with a real actor and refresh partner credit balance only after posting.
- `GeneralLedgerService::createCustomerAdvanceJournalEntry()` accepts optional `currencyCode`, falls back to the company's stored currency when omitted, and shares the after-commit posting/balance helper with customer payment-received entries when called inside an outer transaction.
- Customer-advance callers in `PaymentAllocationService` and `PaymentController` pass payment currency for live advance entries.
- H-2.3 DONE — B2B payment-tolerance GL entries now post when a real actor is available. `CloseInvoiceWithToleranceService` passes its `closedBy` actor and invoice currency to `GeneralLedgerService::createPaymentToleranceJournalEntry()`, and `PaymentAllocationService` forwards its resolved command actor and payment currency through `PaymentToleranceService::applyTolerance()`. Actorless legacy/replay calls still create draft entries.
- H-2.4 DONE — supplier-advance refund reversals now post when `VendorRefundService::refundPrepayment()` has a real actor. `reverseSupplierAdvanceJournalEntry()` accepts an optional actor id and currency, validates the actor before creating the journal entry, and posts after commit through the canonical posting path. Actorless legacy calls still create draft entries.
- H-2.5 DONE — customer-advance clearing entries created during sales-order-to-invoice prepayment transfer now post when conversion has an actor. `DocumentConversionController::convertOrderToInvoice()` passes `actor_user_id`, `SalesOrderToInvoiceConverter` forwards it to `clearCustomerAdvanceToReceivable()`, and the GL helper validates the actor before creating the clearing entry. Actorless service conversions still create draft entries.
- Verification: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/MultiPaymentTest.php tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/TreasuryEventsTest.php`; `php artisan test --filter PartnerBalanceServiceTest`; `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php`; `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php tests/Feature/Accounting/GLIntegrationTest.php`.
- H-2.3 verification: `php artisan test tests/Feature/Modules/Document/CloseInvoiceWithToleranceEndpointTest.php tests/Unit/Treasury/CloseInvoiceWithToleranceServiceTest.php tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php tests/Unit/Treasury/PaymentAllocationServiceToleranceContractTest.php tests/Unit/Treasury/PaymentToleranceServiceTest.php`.
- H-2.4 verification: `php artisan test tests/Feature/Treasury/VendorPrepaymentRefundTest.php tests/Feature/Treasury/VendorRefundScalingTest.php`.
- H-2.5 verification: `php artisan test tests/Feature/Document/DocumentConversionScenarioTest.php tests/Feature/Accounting/GLIntegrationTest.php --filter 'prepayment|customer_advance_clearing|it_posts_prepayment_application|services_only_orders'`.
- Reviews saved in `docs/superpowers/reviews/2026-06-22-h2-payment-received-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-payment-received-posting-opus-fallback-review.md`; true Opus review remains pending.
- Customer-advance reviews saved in `docs/superpowers/reviews/2026-06-22-h2-customer-advance-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-customer-advance-posting-opus-fallback-review.md`; true Opus review remains pending.
- Payment-tolerance reviews saved in `docs/superpowers/reviews/2026-06-22-h2-payment-tolerance-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-payment-tolerance-posting-opus-fallback-review.md`; true Opus review remains pending.
- Supplier-advance refund reviews saved in `docs/superpowers/reviews/2026-06-22-h2-supplier-advance-refund-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-supplier-advance-refund-posting-opus-fallback-review.md`; true Opus review remains pending.
- Customer-advance clearing reviews saved in `docs/superpowers/reviews/2026-06-22-h2-customer-advance-clearing-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-customer-advance-clearing-posting-opus-fallback-review.md`; true Opus review remains pending.
- Final H-2 scan found AR/AP/advance/clearing production paths covered. Remaining non-partner operational draft creators are tracked separately under H-7.

## H-7 — Operational non-partner GL writers remain draft-only

status: IN PROGRESS
severity: HIGH
source: H-2 completeness scan, `GeneralLedgerService` source-type scan

Claim: Outside the AR/AP/advance/clearing scope, some production operational GL writers still create Draft journal entries with no posting cycle, including expenses, voucher ledger entries, COGS, and inventory write-offs.

Evidence:
- `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:116` calls `GeneralLedgerService::createFromExpense()` with a real actor.
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1540` creates `source_type = expense` as Draft.
- `apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php:73` calls `createCOGSEntry()`.
- `apps/api/app/Modules/Voucher/Application/Services/*` call `createVoucherLedgerEntry()`.
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:959`, `:1067`, `:1610` create `cogs`, `voucher_ledger`, and `batch_write_off` entries as Draft.

Acceptance criteria:
- Confirmed production operational writers either post immediately with a real actor/system actor or are explicitly classified/deferred with rationale.
- Posting continues to use canonical `postEntry()` semantics and `JournalEntryPosted` event timing.
- Tests cover each fixed path.

Test plan:
- Process one writer at a time.
- Start with expenses because `ExpenseService::post()` already supplies a real `User`.

Scope boundary: Non-partner operational GL lifecycle only; do not change AR/AP/advance/clearing behavior already completed in H-2.

Outcome so far:
- H-7.1 DONE — posted expenses now create posted `expense` journal entries. `GeneralLedgerService::createFromExpense()` posts through the canonical after-commit helper using the supplied user and expense currency. Red was observed first via `ExpenseService::post()` leaving the entry Draft.
- H-7.1 verification: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php --filter test_posting_expense_posts_expense_journal_entry`; `php artisan test tests/Feature/Accounting/GLIntegrationTest.php`.
- H-7.2 DONE — voucher ledger GL entries now post through the ledger event's required `user_id`. `createVoucherLedgerEntry()` validates the ledger user before creating the journal entry and posts through the canonical helper with ledger currency. Red was observed first via voucher issuance leaving the linked GL entry Draft.
- H-7.2 verification: `php artisan test tests/Feature/Voucher/VoucherIssuanceServiceTest.php --filter test_issue_from_refund_creates_voucher_ledger_and_g_l_entry`; `php artisan test tests/Feature/Voucher/VoucherIssuanceServiceTest.php tests/Feature/Voucher/VoucherRedemptionServiceTest.php --filter 'voucher_ledger|g_l_entry|rounding|redeem'`.

## H-3 — Supplier invoice/payment AP production path is unwired

status: DONE
severity: HIGH  
source: seed backlog + `07-supplier-ap-production-path.md`

Claim: Supplier AP GL writers exist only as service methods with test callers; normal production purchase-order/payment flows do not call them, so `payable_balance` has readers but no live supplier document/payment writer.

Evidence:
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:402`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:487`
- `apps/api/tests/Feature/Accounting/GLIntegrationTest.php:322`
- `apps/api/tests/Feature/Accounting/GLIntegrationTest.php:367`
- `apps/api/app/Modules/Document/Presentation/routes.php:210`
- `apps/api/app/Providers/EventServiceProvider.php:51`

Acceptance criteria:
- Supplier invoice and supplier payment production actions create posted, partner-tagged SupplierPayable lines.
- `partners.payable_balance` updates.
- Source linkage and idempotency are covered.

Test plan:
- Add production API/service feature tests for supplier invoice/payment AP posting.

Scope boundary: Supplier payable only; do not bundle supplier advance/refund policy unless the re-verification step proves it is required.

Outcome so far:
- H-3.1 DONE — `PaymentController::store()` now treats purchase-order allocations as outgoing supplier payments, posts a `supplier_payment` journal entry through `GeneralLedgerService::createSupplierPaymentJournalEntry()`, tags the SupplierPayable line with the supplier partner, posts the entry, refreshes partner payable balance after posting, and decreases the selected repository balance.
- Purchase-order payments cannot be mixed with customer document payments in one call.
- Purchase-order supplier payments that would leave unallocated excess are rejected until a supplier-advance policy/writer exists.
- Verification: `php artisan test --filter 'purchase_order_payment'`; `php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/TreasuryEventsTest.php`; `php artisan test --filter PartnerBalanceServiceTest`; `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php`; `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php tests/Feature/Treasury/PaymentTest.php`.
- Reviews saved in `docs/superpowers/reviews/2026-06-22-h3-supplier-payment-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h3-supplier-payment-posting-opus-fallback-review.md`; true Opus review remains pending.
- H-3.2 DONE — `PurchaseOrderConfirmed` now has a production accounting listener that creates a posted `supplier_invoice` GL entry for confirmed purchase orders, debits PurchaseExpenses/VAT Deductible as applicable, credits partner-tagged SupplierPayable, refreshes payable balance after posting, and skips duplicate source entries on repeated event delivery.
- Verification: `php artisan test tests/Unit/Document/PurchaseOrderServiceTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/PaymentTest.php`; `php artisan test --filter PartnerBalanceServiceTest`; `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Listeners/PurchaseOrderConfirmedListener.php app/Providers/EventServiceProvider.php`; `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Listeners/PurchaseOrderConfirmedListener.php app/Providers/EventServiceProvider.php tests/Unit/Document/PurchaseOrderServiceTest.php`.
- Supplier payment extension note: `PaymentController::storeMultiple()` and `PaymentAllocationService` still need a separate supplier-payment policy/coverage item if purchase-order allocations should flow through those surfaces; they are not the confirmed production AP path covered by H-3.
- Supplier invoice reviews saved in `docs/superpowers/reviews/2026-06-22-h3-supplier-invoice-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h3-supplier-invoice-posting-opus-fallback-review.md`; true Opus review remains pending.

## H-4 — `GeneralLedgerService::postEntry()` does not reject unbalanced entries

status: DONE
severity: HIGH  
source: seed backlog re-verified in `06-draft-post-lifecycle.md`

Claim: `postEntry()` sums total debit and total credit for the event but never compares them before setting `Posted`.

Evidence:
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1111`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1121`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1133`

Acceptance criteria:
- `postEntry()` rejects unbalanced entries before mutating status/hash fields.
- Existing valid posting paths remain green.
- Error type/message is test-covered.

Test plan:
- Add a failing test in `GeneralLedgerService`/journal posting coverage for unbalanced draft posting.
- Run targeted accounting tests.

Scope boundary: `postEntry()` validation only.

Outcome:
- `GeneralLedgerService::postEntry()` now loads journal lines and compares total debit/credit at the selected currency scale before any status, hash, posted timestamp, or actor mutation.
- Unbalanced draft entries throw `InvalidArgumentException` with a covered `Cannot post unbalanced journal entry` message and do not dispatch `JournalEntryPosted`.
- Existing valid posting paths remain green.
- The posting hash test fixture was corrected from an internally inconsistent `total=120.00`/line total `100.00` setup to a balanced invoice so it continues to exercise successful posting under the new guard.

Verification:
- Red observed first: `php artisan test --filter test_posting_unbalanced_journal_entry_is_rejected_without_mutation` failed because the unbalanced draft posted.
- Green after implementation: `php artisan test --filter test_posting_unbalanced_journal_entry_is_rejected_without_mutation` passed 1 test, 7 assertions.
- `php artisan test --filter test_posting_journal_entry_adds_hash` passed 1 test, 4 assertions.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Accounting/DocumentGLIntegrationTest.php tests/Feature/Accounting/GeneralLedgerHashServiceTest.php` passed 40 tests, 160 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` passed.
- `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Accounting/GLIntegrationTest.php` passed.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-h4-post-entry-balance-guard-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-h4-post-entry-balance-guard-opus-fallback-review.md`

## H-5 — Treasury allocation precision mismatch

status: DONE
severity: HIGH  
source: `09-unwired-precision-status.md`

Claim: `payment_allocations.amount` schema/model precision is inconsistent with the money precision contract.

Evidence:
- `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:171`
- `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:106`
- `apps/api/app/Modules/Treasury/Domain/PaymentAllocation.php:45`

Acceptance criteria:
- Schema, casts, validation, and tests agree on currency scale.
- PostgreSQL verification documents actual column scale.

Test plan:
- Add PG schema/metadata test or migration assertion.
- Add treasury allocation test with 3-decimal money.

Scope boundary: Treasury allocation money columns; do not change quantity scale.

Outcome:
- Added tenant migration `2026_06_22_120000_widen_payment_allocations_amount_to_scale_3.php` to widen `payment_allocations.amount` to `NUMERIC(15, 3)` on PostgreSQL.
- Changed `PaymentAllocation::$casts['amount']` to `decimal:3`; `tolerance_writeoff` remains `decimal:4`.
- Tightened smart-payment allocation money validation from 4 decimals to 3 decimals, including `payment_amount` and manual allocation amounts.
- Normalized `PaymentAllocationService` preview/result money outputs to currency scale while preserving four-decimal tolerance writeoffs.
- Updated direct allocation cast expectations in Treasury/Fiscal tests to 3-decimal money.

Verification:
- Red observed first: `php artisan test tests/Feature/Treasury/PaymentAllocationPrecisionTest.php` failed because `PaymentAllocation::amount` returned `12.3450` instead of `12.345`.
- Red observed first: `php artisan test --filter it_rejects_manual_allocation_amount_above_currency_scale` failed because smart-payment manual allocation accepted `99.9999`.
- Green after implementation: `php artisan test tests/Feature/Treasury/PaymentAllocationPrecisionTest.php` passed 1 test, 1 PostgreSQL-only schema test skipped under SQLite.
- Real PostgreSQL verification passed against isolated `autoerp_h5_test`: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_h5_test DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret php artisan test tests/Feature/Treasury/PaymentAllocationPrecisionTest.php` passed 2 tests, 4 assertions, including `information_schema.columns` precision/scale assertion.
- `php artisan test tests/Feature/Treasury/PaymentAllocationPrecisionTest.php tests/Feature/Treasury/SmartPaymentIntegrationTest.php tests/Unit/Treasury/PaymentAllocationServiceTest.php tests/Unit/Treasury/PaymentAllocationServiceTolerancePersistenceTest.php tests/Feature/Treasury/MultiPaymentTest.php tests/Feature/Treasury/PaymentRefundTest.php tests/Feature/Treasury/VendorPrepaymentRefundTest.php tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php` passed 67 tests, 1 SQLite-skipped PostgreSQL schema assertion, 298 assertions.
- `php artisan test tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/TreasuryEventsTest.php tests/Feature/Treasury/PaymentRegistrationFlowTest.php tests/Feature/Document/CreditNoteAllocationTest.php tests/Unit/Document/PaymentStatusCalculationTest.php tests/Unit/Document/DocumentCacheValidationTest.php` passed 48 tests, 6 PostgreSQL-only skips, 147 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury/Domain/PaymentAllocation.php app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php` passed.
- `./vendor/bin/pint --test` passed on changed app, migration, and test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-h5-payment-allocation-precision-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-h5-payment-allocation-precision-opus-fallback-review.md`

## H-6 — Partner balance/credit money fields still use scale 4

status: DONE
severity: HIGH  
source: seed backlog M-4 + `09-unwired-precision-status.md`

Claim: Partner balance and credit-limit columns/casts/validation are scale 4 while the currency contract expects scale 3 or a documented exception.

Evidence:
- `apps/api/database/migrations/tenant/2025_12_06_100001_add_balance_fields_to_partners.php:18`
- `apps/api/database/migrations/tenant/2026_03_11_600000_add_b2b_fields_to_partners.php:18`
- `apps/api/app/Modules/Partner/Domain/Partner.php:150`
- `apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:79`

Acceptance criteria:
- Decide and enforce either scale 3 or a documented 4dp cache exception.
- Schema, casts, request validation, and tests match the decision.

Test plan:
- Add FormRequest and schema tests.
- Run partner balance tests.

Scope boundary: Partner money fields only.

Outcome:
- Chose scale 3 for partner money fields; no 4-decimal cache exception was documented because these are ordinary currency money values, not quantities or tolerance metrics.
- Added tenant migration `2026_06_22_130000_align_partner_money_fields_to_scale_3.php` to alter `partners.receivable_balance`, `credit_balance`, `payable_balance`, and `credit_limit` to `NUMERIC(15, 3)` on PostgreSQL.
- Changed `Partner` casts for the four fields to `decimal:3`, and aligned `hasActiveCreditLimit()`, customer `net_balance`, and partner balance/liability cache calculations to scale 3.
- Tightened create/update partner `credit_limit` validation to reject more than 3 decimal places.
- Updated partner/POS/API and accounting expectations to the 3-decimal partner-money contract.

Verification:
- Red observed first: `php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php` failed because partner money casts returned `100.1250` instead of `100.125`.
- Red observed first: `php artisan test --filter 'partner_.*credit_limit' tests/Feature/Service/IngressPrecisionTest.php` failed because 4-decimal partner credit limits were accepted.
- Green after implementation: `php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php` passed 1 test, 1 PostgreSQL-only schema assertion skipped under SQLite.
- Real PostgreSQL verification passed against isolated `autoerp_h6_test`: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_h6_test DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php` passed 2 tests, 13 assertions, including `information_schema.columns` precision/scale assertions.
- `php artisan test --filter 'partner_.*credit_limit' tests/Feature/Service/IngressPrecisionTest.php` passed 3 tests, 5 assertions.
- `php artisan test tests/Feature/Accounting/PartnerBalanceServiceTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Unit/Document/PurchaseOrderServiceTest.php tests/Feature/Partner/B2BPartnerTest.php tests/Feature/Partner/PartnerBalanceListTest.php tests/Feature/POS/PosCustomerSyncControllerTest.php` passed 76 tests, 311 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Partner/Domain/Partner.php app/Modules/Accounting/Application/Services/PartnerBalanceService.php app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php app/Modules/Partner/Presentation/Requests/UpdatePartnerRequest.php` passed.
- `./vendor/bin/pint` passed after fixing import order in `PartnerMoneyPrecisionTest`.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-h6-partner-money-precision-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-h6-partner-money-precision-opus-fallback-review.md`

## M-1 — Manual journal create/post bypasses accounting audit events

status: DONE
severity: MEDIUM  
source: `08-event-sourcing-coverage.md`

Claim: `JournalEntryController` creates and posts entries directly without `JournalEntryCreated`/`JournalEntryPosted`, while service posting emits events.

Evidence:
- `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:85`
- `apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:154`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1133`

Acceptance criteria:
- Manual create/post routes dispatch equivalent audit events or route through the service.
- Compliance subscriber coverage is explicit.

Test plan:
- Add feature tests for manual journal create/post audit/event behavior.

Scope boundary: Manual journal endpoints only.

Outcome:
- Manual journal creation now marks entries as `source_type=manual` with self-linked `source_id`, then dispatches the existing immutable `JournalEntryCreated` event after the route transaction completes.
- Manual journal posting now delegates to `GeneralLedgerService::postEntry()`, reusing canonical balanced-entry validation, company-scoped hash-chain calculation, fiscal hash mutation, actor stamping, and `JournalEntryPosted` dispatch.
- `DomainEventSubscriber` explicitly subscribes to `JournalEntryCreated` and `JournalEntryPosted`, persisting both as `JournalEntry` aggregate audit rows.

Verification:
- Red observed first: `php artisan test --filter 'manual_journal_entry_.*dispatches_audit_event' tests/Feature/Accounting/CreateJournalEntryTest.php` failed because neither manual create nor manual post dispatched journal audit events.
- Red observed first: `php artisan test --filter 'journal_entry_.*event_creates_audit_entry' tests/Feature/Compliance/DomainEventSubscriberTest.php` failed because no `audit_events` rows were persisted for journal entry events.
- Green after implementation: `php artisan test tests/Feature/Accounting/CreateJournalEntryTest.php` passed 10 tests, 22 assertions.
- `php artisan test tests/Feature/Compliance/DomainEventSubscriberTest.php` passed 12 tests, 69 assertions.
- `php artisan test tests/Feature/Accounting/AccountingEventsTest.php tests/Feature/Accounting/AccountingEventsExtendedTest.php tests/Feature/Accounting/JournalEntryHashChainMigrationTest.php tests/Feature/Accounting/JournalEntryImmutabilityTest.php` passed 46 tests, 73 assertions.
- `php artisan test --filter 'posting_journal_entry_adds_hash|posting_unbalanced_journal_entry_is_rejected_without_mutation' tests/Feature/Accounting/GLIntegrationTest.php` passed 2 tests, 11 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php app/Modules/Compliance/Listeners/DomainEventSubscriber.php` passed.
- `./vendor/bin/pint` passed on touched app/test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m1-manual-journal-audit-events-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m1-manual-journal-audit-events-opus-fallback-review.md`

## M-2 — Financial mutations lack fiscal/audit event policy

status: DONE
severity: MEDIUM  
source: `08-event-sourcing-coverage.md`

Claim: Refunds, payment allocations, and bank reconciliation mutate financial state without an explicit fiscal/audit event policy or matching projection.

Evidence:
- `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:132`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:342`
- `apps/api/app/Modules/Treasury/Application/Services/BankReconciliationService.php:208`
- `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:943`

Acceptance criteria:
- Each mutation is classified as fiscal, audit-only, ledger-only, or reserved.
- Tests enforce the classification.

Test plan:
- Add fiscal/audit coverage matrix tests.

Scope boundary: Classification and testable enforcement first; implement new fiscal event versions only after policy is explicit.

Outcome:
- Classified the scoped treasury financial mutation events as `audit-only`: `PaymentRefunded`, `PaymentReversed`, `PaymentAllocated`, and `ReconciliationCompleted`.
- Added test enforcement that every scoped audit-only mutation event is present in `DomainEventSubscriber::subscribe()`.
- Confirmed refund/reversal audit subscription already existed.
- Added `DomainEventSubscriber` handlers and subscription entries for `PaymentAllocated` and `ReconciliationCompleted`, persisting them as `Payment` and `BankReconciliation` aggregate audit rows.
- Did not introduce fiscal event versions or projectors in this item; full fiscal policy remains M-3.

Verification:
- Red observed first: `php artisan test --filter 'financial_mutation_event_policy|payment_allocated_event_creates_audit_entry|reconciliation_completed_event_creates_audit_entry' tests/Feature/Compliance/DomainEventSubscriberTest.php` failed because `PaymentAllocated` and `ReconciliationCompleted` were not subscribed and produced no audit rows.
- Green after implementation: `php artisan test --filter 'financial_mutation_event_policy|payment_allocated_event_creates_audit_entry|reconciliation_completed_event_creates_audit_entry' tests/Feature/Compliance/DomainEventSubscriberTest.php` passed 3 tests, 20 assertions.
- `php artisan test tests/Feature/Compliance/DomainEventSubscriberTest.php` passed 15 tests, 89 assertions.
- `php artisan test tests/Feature/Treasury/TreasuryEventsTest.php tests/Feature/Treasury/TreasuryEventDispatchTest.php tests/Feature/Treasury/TreasuryEventsExtendedTest.php` passed 43 tests, 123 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Compliance/Listeners/DomainEventSubscriber.php` passed.
- `./vendor/bin/pint` passed on touched files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m2-financial-mutation-audit-policy-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m2-financial-mutation-audit-policy-opus-fallback-review.md`

## M-3 — Fiscal event enum/validator/projector coverage needs a matrix

status: DONE
severity: MEDIUM  
source: `08-event-sourcing-coverage.md`

Claim: Reserved and implemented fiscal events are not covered by a single matrix proving enum, registry, validator, projector, and reader policy.

Evidence:
- `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:13`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:53`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:393`
- `apps/api/tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php:89`

Acceptance criteria:
- A test enumerates every `FiscalEventType` and asserts its policy.
- Implemented events cannot silently lack a payload/validator policy.

Test plan:
- Run `php artisan test --filter FiscalEventPayloadRegistryTest`.

Scope boundary: Test/policy coverage first, no event renames.

Outcome:
- Added `FiscalEventCoveragePolicy`, an explicit matrix over every `FiscalEventType`.
- Classified current fiscal events as `projected`, `audit-only`, or `reserved-unreachable`.
- Added tests that fail if a new enum case lacks policy, a reserved case resolves a payload DTO, an implemented case lacks payload registry/validator coverage, projected policy drifts from registered projectors, or canonical reader policy points at a missing reader method.
- Did not rename, restructure, or add fiscal events.

Verification:
- Red observed first: `php artisan test tests/Unit/Fiscal/FiscalEventCoveragePolicyTest.php` failed because `FiscalEventCoveragePolicy` did not exist.
- Green after implementation: `php artisan test tests/Unit/Fiscal/FiscalEventCoveragePolicyTest.php` passed 4 tests, 122 assertions.
- `php artisan test --filter FiscalEventPayloadRegistryTest` passed 23 tests, 153 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings from suite discovery.
- `php artisan test tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php tests/Feature/Fiscal/FiscalEventProjectionDispatcherTest.php tests/Feature/Fiscal/FiscalEventProjectionsTableTest.php` passed 128 tests, 3 PostgreSQL-only skips, 288 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php` passed.
- `./vendor/bin/pint` passed on touched files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m3-fiscal-event-coverage-policy-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m3-fiscal-event-coverage-policy-opus-fallback-review.md`

## M-4 — Balance refresh is synchronous and often outside posting transaction

status: DONE
severity: MEDIUM  
source: seed backlog M-1 + `06-draft-post-lifecycle.md`

Claim: `refreshPartnerBalance()` is called by GL creation methods even when entries are Draft or outside a durable posting transaction.

Evidence:
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:120`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:254`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:322`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:476`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:542`

Acceptance criteria:
- Balance refresh is driven by `JournalEntryPosted` or a transactionally equivalent mechanism.
- Draft creation does not imply stale/meaningless balance refresh.

Test plan:
- Add event/listener tests around `JournalEntryPosted`.

Scope boundary: Address after H-2/H-4 clarify posting semantics.

Outcome:
- Added `RefreshPartnerBalanceOnJournalEntryPosted` and registered it for `JournalEntryPosted`.
- `GeneralLedgerService::postEntry()` now drives partner cache refresh through the posted event for every posted entry with partner-tagged lines.
- Removed direct partner balance refresh from draft-only GL builders so draft invoice, credit-note, generic payment, supplier advance reversal, tolerance, and customer-advance clearing creation does not mutate cached balances.
- Existing immediately-posted customer advance, customer payment-received, supplier invoice, and supplier payment helpers now rely on the posted event instead of a second direct refresh.
- Production invoice/credit-note GL writers in `AccountingService` still create already-posted entries directly, but now wrap journal header/line/hash/audit-event/balance-refresh work in one transaction. Balance-refresh failure rolls back the posted journal rows.
- Kept POS account charge direct refresh because it is already transactionally equivalent and covered by rollback-on-refresh-failure tests.

Verification:
- Red observed first: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php --filter test_posting_partner_journal_entry_refreshes_partner_balance` failed with cached receivable still `0.000`.
- Red observed first: `php artisan test tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php --filter test_invoice_gl_creation_rolls_back_when_partner_balance_refresh_fails` failed because the posted journal entry remained after refresh failure.
- Green after implementation: M-4 GL regression filter passed 2 tests, 4 assertions.
- Green after implementation: invoice/credit-note rollback filter passed 2 tests, 8 assertions.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php` passed 19 tests, 79 assertions.
- `php artisan test tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php tests/Feature/Accounting/InvoiceGLIntegrationTest.php tests/Feature/Accounting/CreditNoteGLIntegrationTest.php` passed 24 tests, 157 assertions.
- `php artisan test tests/Feature/Accounting/DocumentGLIntegrationTest.php tests/Feature/Document/DocumentPostingServiceTest.php` passed 22 tests, 96 assertions.
- `php artisan test tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` passed 9 tests, 72 assertions.
- `php artisan test tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` passed 11 tests, 47 assertions.
- `php artisan test tests/Feature/Treasury/PaymentTest.php` passed 16 tests, 49 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Listeners/RefreshPartnerBalanceOnJournalEntryPosted.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Application/Services/AccountingService.php app/Providers/EventServiceProvider.php` passed.
- `./vendor/bin/pint --test` passed on touched app/test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m4-posted-event-balance-refresh-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m4-posted-event-balance-refresh-opus-fallback-review.md`

## M-5 — Partner non-negative balance DB constraints are missing or unverified

status: DONE
severity: MEDIUM  
source: seed backlog M-2

Claim: `credit_balance` and `payable_balance` should have PG CHECK constraints consistent with the locked non-negative magnitude convention.

Evidence:
- Prior fix documented convention in `docs/superpowers/reviews/2026-06-20-partner-credit-balance-sign-codex-review.md`.
- Current Phase 0 did not find a confirming constraint migration.

Acceptance criteria:
- PG CHECK constraints exist for non-negative cached liability magnitudes.
- Domain guard/tests reject negative writes.
- Real-PG verification is documented.

Test plan:
- Add tenant migration and PG-only schema/constraint tests.

Scope boundary: Cached partner balance columns only.

Outcome:
- Added tenant migration `2026_06_22_140000_add_partner_non_negative_balance_constraints.php`.
- Migration normalizes stale negative cached `credit_balance`/`payable_balance` values to zero, clears `balance_updated_at`, and adds PostgreSQL CHECK constraints `partners_credit_balance_non_negative` and `partners_payable_balance_non_negative`.
- Added a `Partner` model saving guard that rejects negative cached liability magnitudes before persistence.
- Extended `PartnerMoneyPrecisionTest` with domain guard assertions, PG constraint metadata assertions, and direct negative-write rejection checks for both constrained columns.

Verification:
- Red observed first: `php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php --filter '/test_partner_(credit|payable)_balance_rejects_negative_cached_magnitude/'` failed because negative cached liability values saved.
- Green after implementation: same filter passed 2 tests, 4 assertions.
- SQLite/default `php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php` passed 3 tests, 4 PostgreSQL-only skips, 8 assertions.
- Real PostgreSQL verification passed against isolated `autoerp_m5_test`: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_m5_test DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret php artisan test tests/Feature/Partner/PartnerMoneyPrecisionTest.php` passed 7 tests, 22 assertions, including `pg_constraint` metadata and direct CHECK rejection checks.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `php artisan test tests/Feature/Partner/CreatePartnerTest.php tests/Feature/Partner/UpdatePartnerTest.php tests/Feature/Partner/PartnerBalanceListTest.php` passed 37 tests, 128 assertions.
- `php artisan test tests/Feature/Partner/RecordCustomerDepositTest.php tests/Feature/Partner/B2BPartnerTest.php tests/Feature/Partner/ListPartnersTest.php` passed 33 tests, 147 assertions.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` passed 28 tests, 151 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Partner/Domain/Partner.php` passed.
- `./vendor/bin/pint --test` passed on touched app, migration, and test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m5-partner-non-negative-balance-constraints-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m5-partner-non-negative-balance-constraints-opus-fallback-review.md`

## M-6 — Scheduled subledger reconciliation alert job is missing

status: DONE
severity: MEDIUM  
source: seed backlog M-3

Claim: `reconcileSubledger()` exists but no scheduled per-tenant/company/purpose alert job was confirmed.

Evidence:
- Reconciliation method counts partnerless entries: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:191`.

Acceptance criteria:
- Scheduled job scans configured companies/purposes and reports discrepancies including `entries_without_partner`.

Test plan:
- Add console/job test with seeded discrepancy.

Scope boundary: Alerting only, not automatic repair.

Outcome:
- Added `accounting:check-subledger-reconciliation`, a scheduled per-tenant command that scans active companies and configured subledger purposes.
- Default scan purposes are `customer_receivable`, `customer_advance`, and `supplier_payable`; `--purpose=*` can narrow/configure the scan.
- Discrepancies are logged with structured tenant/company/purpose context and printed with `entries_without_partner`, control balance, subledger total, difference, and account code.
- The command returns failure when discrepancies are found and does not mutate or repair balances.
- Registered the command in `AccountingServiceProvider` and scheduled it daily at 02:30 with `withoutOverlapping()` and `runInBackground()`.

Verification:
- Red observed first: `php artisan test tests/Feature/Accounting/SubledgerReconciliationCommandTest.php` failed with `CommandNotFoundException` for `accounting:check-subledger-reconciliation`.
- Green after implementation: `php artisan test tests/Feature/Accounting/SubledgerReconciliationCommandTest.php` passed 2 tests, 6 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php` passed 19 tests, 79 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Presentation/Console/CheckSubledgerReconciliationCommand.php app/Modules/Accounting/Providers/AccountingServiceProvider.php routes/console.php` passed.
- `./vendor/bin/pint --test` passed on touched command, provider, scheduler, and test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m6-subledger-reconciliation-alert-command-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m6-subledger-reconciliation-alert-command-opus-fallback-review.md`

## M-7 — Both-type partner net balance ignores payable balance

status: DONE
severity: MEDIUM  
source: seed backlog M-5

Claim: Backend and web net-balance formulas emphasize receivable minus credit and may ignore `payable_balance` for `both` partners.

Evidence:
- Backend controller select formula: `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:96`.
- Backend model accessor starts at `apps/api/app/Modules/Partner/Domain/Partner.php:274`.
- Web helper: `apps/web/src/features/partners/PartnerListPage.tsx:65`.

Acceptance criteria:
- `both` partner net-balance semantics are explicit and consistent across backend/web.
- Tests cover receivable, credit, payable, and mixed both-type cases.

Test plan:
- Add backend model/controller tests and web helper tests.

Scope boundary: Display/calculation semantics only.

Outcome:
- Defined net-balance semantics consistently: customer position is `receivable_balance - credit_balance`; supplier position is `payable_balance`; `both` partner net exposure is `receivable_balance - credit_balance - payable_balance`.
- Updated the backend model accessor, partner index SQL sort expression, and frontend list helper to use those semantics.
- Moved the frontend helper into `partnerNetBalance.ts` so tests can cover the calculation without exporting non-component helpers from the page component.

Verification:
- Red observed first: `php artisan test tests/Unit/Partner/PartnerEntityTest.php --filter test_both_partner_net_balance_offsets_payable_against_customer_position` failed because the old accessor returned `900.000` instead of `650.000`.
- Green after implementation: `php artisan test tests/Unit/Partner/PartnerEntityTest.php --filter test_both_partner_net_balance_offsets_payable_against_customer_position` passed 1 test, 1 assertion.
- `php artisan test tests/Feature/Partner/PartnerBalanceListTest.php --filter test_sort_by_net_balance_offsets_both_partner_payable_balance` passed 1 test, 3 assertions.
- `php artisan test tests/Unit/Partner/PartnerEntityTest.php tests/Feature/Partner/PartnerBalanceListTest.php` passed 22 tests, 78 assertions.
- `pnpm --filter @autoerp/web test -- src/features/partners/partners.test.tsx` passed 42 tests. The run emitted pre-existing `--localstorage-file` and `/partners/1` route warnings.
- `./vendor/bin/phpstan analyse app/Modules/Partner/Domain/Partner.php app/Modules/Partner/Presentation/Controllers/PartnerController.php --level=8` passed.
- `pnpm --filter @autoerp/web typecheck` passed.
- `pnpm --filter @autoerp/web exec eslint src/features/partners/PartnerListPage.tsx src/features/partners/partnerNetBalance.ts src/features/partners/partners.test.tsx` passed with 0 errors and existing warnings.
- `./vendor/bin/pint --test` passed on touched backend files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m7-both-partner-net-balance-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m7-both-partner-net-balance-opus-fallback-review.md`

## M-8 — Uncapped advance clearing

status: DONE
severity: MEDIUM  
source: seed backlog M-7

Claim: `clearCustomerAdvanceToReceivable()` clears an arbitrary amount without checking actual available customer advance.

Evidence:
- Method starts at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:727`.

Acceptance criteria:
- Clearing amount cannot exceed available customer advance unless an explicit override path exists.
- Over-clear behavior is tested.

Test plan:
- Add failing test that attempts to clear more than available advance.

Scope boundary: Customer advance clearing only.

Outcome:
- `clearCustomerAdvanceToReceivable()` now rejects non-positive clearing amounts and amounts above the currently available customer advance.
- Available advance is calculated from posted customer-advance liability magnitude, minus existing draft `prepayment_application` clearings for the same company, partner, and customer-advance account.
- Validation and draft journal creation now run inside one transaction after locking the partner row, so same-partner clearings serialize on PostgreSQL.
- `PartnerBalanceService` balance helper docs now advertise `numeric-string` returns used by the bcmath guard.

Verification:
- Red observed first: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php --filter test_customer_advance_clearing_cannot_exceed_available_advance` failed because a `60.000` clearing was created against only `40.000` posted advance.
- Additional red observed: duplicate draft clearings were allowed against the same `40.000` advance, and `0.000` clearing created a journal entry.
- Green after implementation: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php --filter 'customer_advance_clearing'` passed 4 tests, 15 assertions.
- `php artisan test tests/Feature/Accounting/GLIntegrationTest.php` passed 23 tests, 94 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 18 tests, 35 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Accounting/Application/Services/PartnerBalanceService.php --level=8` passed.
- `./vendor/bin/pint --test` passed on touched app/test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m8-customer-advance-clearing-cap-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m8-customer-advance-clearing-cap-opus-fallback-review.md`

## M-9 — Aged receivables uses float math

status: DONE
severity: MEDIUM  
source: `09-unwired-precision-status.md`

Claim: `AgedReceivablesService` casts money through floats.

Evidence:
- `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:288`

Acceptance criteria:
- Money math remains decimal-string/bcmath.
- 3-decimal TND test has no drift.

Test plan:
- Add unit/feature test with 3-decimal values.

Scope boundary: Aged receivables only.

Outcome:
- Removed `(float)` casts from `AgedReceivablesService::generateCustomerStatement()` running-balance recalculation.
- Recalculation now consumes debit/credit as decimal strings and uses bcmath at the resolved currency scale.
- Added regression coverage that forbids reintroducing float casts in the service and verifies TND scale-3 customer-statement balances.

Verification:
- Red observed first: `php artisan test tests/Feature/Document/AgedReceivablesScalingTest.php --filter customer_statement_running_balance_preserves_third_decimal_for_large_tnd_amount` showed drift from `100000000000.123` to `100000000000.120`, confirming the audited float precision failure mode. The permanent test was adjusted to avoid SQLite's own large-decimal storage rounding and directly guard the service against float casts.
- Green after implementation: `php artisan test tests/Feature/Document/AgedReceivablesScalingTest.php` passed 3 tests, 6 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse app/Modules/Document/Application/Services/AgedReceivablesService.php --level=8` passed.
- `./vendor/bin/pint --test app/Modules/Document/Application/Services/AgedReceivablesService.php tests/Feature/Document/AgedReceivablesScalingTest.php` passed.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m9-aged-receivables-decimal-strings-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m9-aged-receivables-decimal-strings-opus-fallback-review.md`

## M-10 — Module gating inconsistencies

status: DONE
severity: MEDIUM  
source: `09-unwired-precision-status.md`

Claim: Service and Workshop routes have inconsistent backend/frontend module guards.

Evidence:
- `apps/api/app/Modules/Service/Presentation/routes.php:20`
- `apps/web/src/routes/index.tsx:1225`
- `apps/api/app/Modules/Workshop/WorkOrder/Presentation/routes.php:26`
- `apps/web/src/routes/index.tsx:1285`

Acceptance criteria:
- Disabled-module tenants fail closed consistently.
- Backend and frontend route guards align.

Test plan:
- Add backend route tests and frontend route guard tests.

Scope boundary: Module gating only.

Outcome:
- Added `module:Workshop` middleware to service catalog API routes.
- Wrapped workshop work-order frontend routes with `ModuleGuard module="Workshop"`.
- Added backend access-control coverage for service and service-category routes, and frontend route guard coverage for service/work-order route branches.

Verification:
- Red observed first: `php artisan test tests/Feature/Security/WorkshopModuleAccessControlTest.php --filter test_retail_vertical_cannot_access_workshop_route` failed because `/api/v1/services` and `/api/v1/service-categories` returned 200 for a retail tenant.
- Red observed first: `pnpm --filter @autoerp/web test -- src/routes/routes.test.tsx` failed because the `workshop/work-orders` route branch lacked `<ModuleGuard module="Workshop">`.
- Green after implementation: `php artisan test tests/Feature/Security/WorkshopModuleAccessControlTest.php` passed 12 tests, 23 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `pnpm --filter @autoerp/web test -- src/routes/routes.test.tsx` passed 2 tests, with the pre-existing `--localstorage-file` warning.
- `pnpm --filter @autoerp/web typecheck` passed.
- `pnpm --filter @autoerp/web exec eslint src/routes/index.tsx src/routes/routes.test.tsx` passed.
- `./vendor/bin/pint --test app/Modules/Service/Presentation/routes.php tests/Feature/Security/WorkshopModuleAccessControlTest.php` passed.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-m10-module-gating-alignment-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-m10-module-gating-alignment-opus-fallback-review.md`

## L-1 — Replace accounting/document/treasury magic-string statuses

status: DONE
severity: LOW  
source: seed backlog L-2 + `09-unwired-precision-status.md`

Claim: Literal statuses remain where enums exist.

Evidence:
- `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:48`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:418`
- `apps/api/app/Modules/Accounting/Domain/OpeningBalanceBatch.php:217`
- `apps/api/app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php:67`

Acceptance criteria:
- Enum constants/values are used consistently.
- Architecture/static test covers the agreed rule.

Test plan:
- Add/extend architecture test.

Scope boundary: Enum-backed statuses only.

Outcome:
- Replaced audited enum-backed status string literals with `DocumentStatus`, `OpeningImportRowStatus`, and `PeriodStatus` enum cases.
- Added `EnumBackedStatusLiteralTest` to prevent the exact audited literals from returning in the touched files.

Verification:
- Red observed first: `php artisan test tests/Architecture/EnumBackedStatusLiteralTest.php` failed on all six audited literals.
- Green after implementation: `php artisan test tests/Architecture/EnumBackedStatusLiteralTest.php` passed 6 tests, 12 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `php artisan test tests/Feature/Document/AgedReceivablesScalingTest.php tests/Unit/Treasury/PaymentAllocationServiceTest.php tests/Feature/Accounting/OpeningBalanceStagingPrecisionTest.php` passed 18 tests, 63 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse app/Modules/Document/Application/Services/AgedReceivablesService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Accounting/Domain/OpeningBalanceBatch.php app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php --level=8` passed.
- `./vendor/bin/pint --test` passed on touched app/test files.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-l1-enum-backed-status-literals-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-l1-enum-backed-status-literals-opus-fallback-review.md`

## L-2 — Statement running balance sign for liability statements

status: DONE
severity: LOW  
source: seed backlog L-3

Claim: `getPartnerStatement()` running balance is debit-normal for all purposes, including liabilities.

Evidence:
- Running balance calculation: `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php:269`.

Acceptance criteria:
- Liability statement sign is explicit and tested.

Test plan:
- Add statement tests for `SupplierPayable` and `CustomerAdvance`.

Scope boundary: Statement presentation only.

Outcome:
- `getPartnerStatement()` now keeps debit-normal running balances for receivable/general statements and uses credit-normal running balances for `CustomerAdvance` and `SupplierPayable`.
- Added statement regression tests for customer advance credit/debit movement and supplier payable credit movement.

Verification:
- Red observed first: `php artisan test --filter 'statement_uses_credit_normal'` failed because the customer advance and supplier payable statement balances were negative (`-50.0000`, `-80.0000`).
- Green after implementation: `php artisan test --filter 'statement_uses_credit_normal'` passed 2 tests, 5 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `php artisan test --filter PartnerBalanceServiceTest` passed 20 tests, 40 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/PartnerBalanceService.php --level=8` passed.
- `./vendor/bin/pint --test app/Modules/Accounting/Application/Services/PartnerBalanceService.php tests/Feature/Accounting/PartnerBalanceServiceTest.php` passed.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-l2-liability-statement-running-balance-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-l2-liability-statement-running-balance-opus-fallback-review.md`; `opus-review: PENDING`.

## L-3 — Centralize net-balance formula

status: DONE
severity: LOW  
source: seed backlog L-4

Claim: Net-balance formulas are duplicated across backend and web.

Evidence:
- `apps/api/app/Modules/Partner/Domain/Partner.php:274`
- `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:96`
- `apps/web/src/features/partners/PartnerListPage.tsx:65`

Acceptance criteria:
- Backend formula has one source of truth; frontend consumes API DTO value where possible.

Test plan:
- Add regression tests around API DTO values and frontend rendering.

Scope boundary: Formula reuse only.

Outcome:
- Moved backend net-balance calculation into `Partner::netBalance()` and the list SQL expression into `Partner::netBalanceSqlExpression()`.
- `PartnerController` now consumes the model-provided SQL expression for sortable list rows instead of embedding the CASE expression inline.
- `PartnerData` and generated shared TypeScript types now expose `net_balance`.
- Frontend partner list display prefers API `net_balance` and keeps fallback arithmetic on decimal-string helpers.

Verification:
- Red observed first: `php artisan test tests/Feature/Partner/PartnerBalanceListTest.php --filter test_balance_fields_appear_in_list_response` failed because list rows did not include `net_balance`.
- Red observed first: `pnpm --filter @autoerp/web test -- src/features/partners/partners.test.tsx -t "uses API net balance"` failed because the frontend helper recomputed `650` instead of using API `700.000`.
- Green after implementation: `php artisan test tests/Feature/Partner/PartnerBalanceListTest.php tests/Unit/Partner/PartnerEntityTest.php` passed 22 tests, 80 assertions.
- `pnpm --filter @autoerp/web test -- src/features/partners/partners.test.tsx` passed 43 tests, with pre-existing localstorage and `/partners/1` route warnings.
- `pnpm --filter @autoerp/web typecheck` passed.
- `pnpm --filter @autoerp/web exec eslint src/features/partners/PartnerListPage.tsx src/features/partners/partnerNetBalance.ts src/features/partners/partners.test.tsx src/features/partners/__fixtures__/partner.ts` passed with 0 errors and pre-existing warnings.
- `CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan typescript:transform` passed and transformed 351 PHP types.
- `./vendor/bin/phpstan analyse app/Modules/Partner/Domain/Partner.php app/Modules/Partner/Presentation/Controllers/PartnerController.php app/Modules/Partner/Application/DTOs/PartnerData.php --level=8` passed.
- `./vendor/bin/pint --test app/Modules/Partner/Domain/Partner.php app/Modules/Partner/Presentation/Controllers/PartnerController.php app/Modules/Partner/Application/DTOs/PartnerData.php tests/Feature/Partner/PartnerBalanceListTest.php` passed.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-l3-centralize-net-balance-formula-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-l3-centralize-net-balance-formula-opus-fallback-review.md`; `opus-review: PENDING`.

## L-4 — Orphaned DTO/service cleanup

status: DONE
severity: LOW  
source: `09-unwired-precision-status.md`

Claim: `InvoiceConsolidationService` and several DTOs appear unreferenced.

Evidence:
- `apps/api/app/Modules/Partner/Application/Services/InvoiceConsolidationService.php:11`
- `apps/api/app/Modules/Expense/Application/DTOs/ExpenseData.php:28`
- `apps/api/app/Modules/Identity/Application/DTOs/LoginData.php:10`
- `apps/api/app/Modules/Workshop/WorkOrder/Application/DTOs/PartNeedData.php:15`

Acceptance criteria:
- Confirm each type is unused, then wire or remove it with generated types refreshed.

Test plan:
- Static reference test or architecture scan.

Scope boundary: No behavior changes unless a type is wired.

Outcome:
- Confirmed no active runtime consumers for `InvoiceConsolidationService`, `ExpenseData`, `LoginData`, or `PartNeedData`.
- Removed the four orphaned PHP definitions.
- Refreshed `packages/shared/types/generated.d.ts`, removing the stale `LoginData` and `PartNeedData` exports.
- Updated current architecture/conventions/POS docs that still named the removed DTO/service. Historical audit and plan docs were left as history.
- Added `OrphanedTypesCleanupTest` to pin this cleanup.

Verification:
- Red observed first: `php artisan test tests/Architecture/OrphanedTypesCleanupTest.php` failed on all four still-present orphan files.
- Green after cleanup: `php artisan test tests/Architecture/OrphanedTypesCleanupTest.php` passed 4 tests, 4 assertions.
- `CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan typescript:transform` passed and transformed 349 PHP types.
- `pnpm --filter @autoerp/web typecheck` passed.
- `./vendor/bin/phpstan analyse tests/Architecture/OrphanedTypesCleanupTest.php --level=8` passed.
- `./vendor/bin/pint --test tests/Architecture/OrphanedTypesCleanupTest.php` passed after formatting.
- Active reference scan passed for `LoginData`, `PartNeedData`, `ExpenseData`, and `InvoiceConsolidationService` across active app/shared/current-doc paths.
- `git diff --check` passed.

Reviews:
- `docs/superpowers/reviews/2026-06-22-l4-orphaned-types-cleanup-codex-review.md`
- `docs/superpowers/reviews/2026-06-22-l4-orphaned-types-cleanup-opus-fallback-review.md`; `opus-review: PENDING`.

## DISCARDED / Stale Or Deferred Claims

- `GeneralLedgerService::createFromInvoice()` is the canonical posted invoice writer — DISCARDED. Production posted documents use `InvoicePostedListener` and `AccountingService`.
- `partners.credit_balance`/`payable_balance` sign fix — DISCARDED as already shipped in `5e4fc0dab`; convention remains non-negative magnitude.
- Reserved `FiscalEventType` cases must all be implemented immediately — DISCARDED as stated. Some cases are intentionally reserved; the actionable item is a policy/matrix test.
