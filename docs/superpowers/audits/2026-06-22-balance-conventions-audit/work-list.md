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

## H-2 — Non-POS draft journal entries have no production posting cycle

status: IN PROGRESS
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
- Verification: `php artisan test tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Treasury/MultiPaymentTest.php tests/Feature/Treasury/PaymentTest.php tests/Feature/Treasury/TreasuryEventsTest.php`; `php artisan test --filter PartnerBalanceServiceTest`; `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php`; `./vendor/bin/pint --test app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Treasury/Application/Services/PaymentAllocationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php tests/Feature/Accounting/GLIntegrationTest.php`.
- Reviews saved in `docs/superpowers/reviews/2026-06-22-h2-payment-received-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-payment-received-posting-opus-fallback-review.md`; true Opus review remains pending.
- Customer-advance reviews saved in `docs/superpowers/reviews/2026-06-22-h2-customer-advance-posting-codex-review.md` and `docs/superpowers/reviews/2026-06-22-h2-customer-advance-posting-opus-fallback-review.md`; true Opus review remains pending.
- Remaining H-2 draft-producing flows still TODO: supplier advances, payment tolerance, customer-advance clearing, and other non-POS draft creators.

## H-3 — Supplier invoice/payment AP production path is unwired

status: TODO  
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

## H-4 — `GeneralLedgerService::postEntry()` does not reject unbalanced entries

status: TODO  
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

## H-5 — Treasury allocation precision mismatch

status: TODO  
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

## H-6 — Partner balance/credit money fields still use scale 4

status: TODO  
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

## M-1 — Manual journal create/post bypasses accounting audit events

status: TODO  
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

## M-2 — Financial mutations lack fiscal/audit event policy

status: TODO  
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

## M-3 — Fiscal event enum/validator/projector coverage needs a matrix

status: TODO  
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

## M-4 — Balance refresh is synchronous and often outside posting transaction

status: TODO  
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

## M-5 — Partner non-negative balance DB constraints are missing or unverified

status: TODO  
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

## M-6 — Scheduled subledger reconciliation alert job is missing

status: TODO  
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

## M-7 — Both-type partner net balance ignores payable balance

status: TODO  
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

## M-8 — Uncapped advance clearing

status: TODO  
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

## M-9 — Aged receivables uses float math

status: TODO  
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

## M-10 — Module gating inconsistencies

status: TODO  
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

## L-1 — Replace accounting/document/treasury magic-string statuses

status: TODO  
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

## L-2 — Statement running balance sign for liability statements

status: TODO  
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

## L-3 — Centralize net-balance formula

status: TODO  
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

## L-4 — Orphaned DTO/service cleanup

status: TODO  
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

## DISCARDED / Stale Or Deferred Claims

- `GeneralLedgerService::createFromInvoice()` is the canonical posted invoice writer — DISCARDED. Production posted documents use `InvoicePostedListener` and `AccountingService`.
- `partners.credit_balance`/`payable_balance` sign fix — DISCARDED as already shipped in `5e4fc0dab`; convention remains non-negative magnitude.
- Reserved `FiscalEventType` cases must all be implemented immediately — DISCARDED as stated. Some cases are intentionally reserved; the actionable item is a policy/matrix test.
