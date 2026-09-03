# Idempotency audit — synthesis (2026-09-03)

**Question (owner, 2026-09-03):** where should there be idempotency that we are not using: forms, documents, drafts, invoices, and so on.

**Lanes:** `06-idempotency-inventory.md` (Sonnet: 605-route inventory, mechanism correctness, queued-job re-run table, 10 web forms, 12 findings I-1..I-12) and `07-codex-idempotency-audit.md` (Codex CLI high effort: 603 routes by consequence class, 24 findings I-01..I-24). Orchestrator verified the two overlapping P0s in source (PaymentForm, goods receipt) and corrected one severity (§4).

---

## 1. Verdict

The codebase already contains a **mature idempotency idiom** and uses it where the fiscal stakes were highest: POS checkout keys, server fiscal-event ingestion (insert-or-conflict with canonical-bytes comparison), fiscal projection jobs (overlap lock + terminal-state short-circuit), counting completion replay markers, bank-statement import hashes, opening-balance conditional claims, the standalone procurement receipt, expense/income client UUIDs. Both lanes list these as done well.

The gaps fall into **four classes**, in descending cost of fixing:

| Class | What is missing | Findings | Fix shape |
|---|---|---|---|
| **A. Backend supports a key, the web client never sends it** | Payments, split payments, stock transfers, stock adjustments all have server-side key handling (pre-check + unique index or catch). `PaymentForm`, `SplitPaymentForm`, `CreateStockTransferPage`, `CreateStockAdjustmentPage` send none. `PaymentForm` also lets a second click through because the mutation is not awaited. | I-02/I-1, I-03, I-06, I-07 | Frontend only: one shared hook that mints a UUID per form session, sent as `idempotency_key`; `mutateAsync` awaited; button on `isPending`. Same pattern as `ExpenseFormPage.tsx:27-37`. |
| **B. "Check a stale model, then write" on posting/transition paths** | `DocumentPostingService::post` refreshes without a lock (its sibling `cancel()` was already hardened for this exact race). Manual journal posting, expense/income posting, legacy POS shift close, quote→order conversion, sales-order→delivery-note all check status on the caller's loaded model and write without a locked re-read. | I-08/I-4, I-09, I-10, I-11, I-14/I-3, I-15 | Backend: `lockForUpdate()` re-read inside the transaction before the state check; for conversions a unique source link or a lock on the source row. Concurrency lane with fiscal + treasury reviewers. |
| **C. No request identity at all on document creation and some money moves** | Ordinary document `store()` (quote, order, invoice, PO, DN, return, credit note), PO receive, ordinary supplier-invoice create, invoice→credit-note, vendor prepayment refund, repository adjustment (mints a server UUID per call, then advertises `idempotent_replay`). First autosave with `draft_id: null` can race itself. | I-01/I-2, I-04, I-05, I-12/I-5, I-13, I-16, I-17, I-18 | Backend: an `idempotency_key` column + partial unique index on `documents` (and refund/adjustment), key resolved from header or body, replay returns the original. Migration lane. Autosave create-race is a frontend in-flight gate. |
| **D. External side effects with no delivery identity** | Document emails (`Mail::send`/`queue` per click), Stripe webhook event id parsed but never claimed (notifications re-fire on redelivery), platform brand-mapping/enrichment-feedback jobs retry 3× with no key, channel stock job re-pushes after ack, marketplace listing sync has no unique on `(seller_id, source_product_id)`. | I-19, I-20, I-21/I-9, I-22, I-10, I-23, ReconcileListingsJob row in 06 §D | Backend: claim rows (`firstOrCreate` on event/delivery id inside the job), `ShouldBeUnique` where the key is the natural id, gate the outbound call on prior success. |

---

## 2. Ranked findings (deduplicated)

| ID | Sev | Class | Finding | Evidence |
|---|---|---|---|---|
| ID-1 | **P0** | A | Payment form: no key sent, `onSubmit` calls `createMutation.mutate()` without awaiting, button disabled on RHF `isSubmitting` only. Double click = two payments, two treasury movements, two GL entries. Backend dedupe fully built but unused. | `PaymentForm.tsx:747-749,1353`; `PaymentController.php:128-192` |
| ID-2 | **P0** | A | Split payment posts `{splits}` only; `MultiPaymentController` dedupe is conditional on the absent key. | `SplitPaymentForm.tsx:89`; `MultiPaymentController.php:119-129` |
| ID-3 | **P0** | A | Stock transfer create moves stock into transit immediately; web payload omits `idempotency_key` although the service and a unique index support it. A genuine key race surfaces as an uncaught 500 rather than a replay. | `CreateStockTransferPage.tsx:694`; `StockTransferService.php:103-116,185`; migration `:53,67`; `StockTransferController.php` (no catch) |
| ID-4 | **P0** | A | Stock adjustment (immediate post) web form omits the key the controller and service support. | `CreateStockAdjustmentPage.tsx:260`; `StockAdjustmentController.php:132`; `StockAdjustmentDocumentService.php:87-99` |
| ID-5 | **P0** | C | PO receive has no request identity; a lost response on a partial receipt followed by a retry receives the remainder twice against physical reality. (Concurrency over-receipt is guarded: `post()` re-asserts under lock, see §4.) | `PurchaseOrderController.php:767`; `GoodsReceiptService.php:72-166,326,562` |
| ID-6 | **P0** | C | Vendor prepayment refund: every call creates a refund payment, negative allocation, PO balance increase, GL entry, cash movement. No key. | `PaymentRefundController.php:231`; `VendorRefundService.php:115-209` |
| ID-7 | **P0** | C | Repository adjustment mints a fresh server UUID per HTTP call so the internal movement dedupe never matches; response still says `idempotent_replay`. | `RepositoryAdjustmentController.php:58,67,104` |
| ID-8 | **P0** | B | B2B document posting: `refresh()` without `lockForUpdate()`; chain allocation locks only the predecessor; no unique on `(company_id,type,chain_sequence)`. Same as request-hygiene S-8. | `DocumentPostingService.php:110-124,658-678` vs hardened `cancel()` `:363-374` |
| ID-9 | **P0** | B | Manual journal posting: draft checked outside a lock, `postEntry()` opens no transaction, advisory lock taken later, stale model updated. | `JournalEntryController.php:148,158`; `GeneralLedgerService.php:3051,3754` |
| ID-10 | **P0** | B | Expense and income posting: controllers and services both check stale models, then GL creators insert unconditionally. | `ExpenseController.php:216,228`; `ExpenseService.php:383,405`; `IncomeController.php:184,194`; `IncomeService.php:148,154` |
| ID-11 | **P0** | B | Legacy POS shift close: shift loaded outside the transaction, never re-locked; each call inserts a closing operation and emits `ShiftClosed`. | `ShiftController.php:104,108`; `ShiftManagementService.php:156-226`; `CashDrawerService.php:225-230` |
| ID-12 | P1 | C | First autosave: `draft_id: null` twice in flight (debounced tick + `saveNow()`) creates two drafts; hook has no in-flight gate. | `useDraftAutoSave.ts:133-163`; `DraftPersistenceService.php:104-118` |
| ID-13 | P1 | C | Ordinary document create (`DocumentForm` → `apiPost(apiEndpoint)`) has no request identity; `isPending` stops a local double click but not a lost response. | `DocumentForm.tsx:390,481,495` |
| ID-14 | P1 | B | Quote→order: `converted_to_order_id` checked before the transaction, order created before the link, no source lock. | `QuoteToSalesOrderConverter.php:103-156` |
| ID-15 | P1 | B | Sales order→delivery note: header locked, but remaining-quantity decision uses the pre-lock model. | `SalesOrderToDeliveryNoteConverter.php:129-277` |
| ID-16 | P1 | C | Ordinary supplier-invoice create has no key (only invoice-first requires one); invoice-first orchestrator reads `supplier_invoice_id` unlocked and can create two invoices under one key. | `CreateSupplierInvoiceRequest.php:101`; `SupplierInvoiceController.php:223-230`; `InvoiceFirstOrchestrator.php:27-78` |
| ID-17 | P1 | C | Invoice→credit-note: source locked, headroom checked, then a fresh draft created every call; sequential replay within headroom duplicates. | `InvoiceToCreditNoteConverter.php:99-137`; `CreditNoteService.php:812-994` |
| ID-18 | P1 | D | Document email endpoints call `Mail::send`/`queue` with no delivery identity. | `DocumentEmailController.php:35,78`; `DocumentEmailService.php:60-124` |
| ID-19 | P1 | D | Stripe webhook: event id parsed, never claimed; payment rows `updateOrCreate` but notifications re-fire on every redelivery. Class comment admits it. | `StripeWebhookController.php:64,107,291-392` |
| ID-20 | P1 | D | Platform product submission mints a new `Idempotency-Key` per call; bulk has none; brand-mapping and enrichment-feedback jobs retry 3× with no identity. | `ProductSubmissionService.php:77-126`; `SendBrandMappingJob.php:39-44`; `SendEnrichmentFeedbackJob.php:42-52` |
| ID-21 | P1 | D | Channel stock job resets an acknowledged operation to Pending and re-pushes; product publish saves `last_sync_hash` only after the remote call. | `DispatchStockChangeToChannelJob.php:46-63`; `ChannelService.php:61-85` |
| ID-22 | P2 | A/C | Key semantics: payment key scoped tenant+company only (not user), replay with a different payload silently returns the original (contrast `TreasuryMovementService::handleIdempotentHit`); transfer/adjustment replay does not compare payloads. | `PaymentController.php:128-192,826-864`; `StockTransferService.php:103`; `StockAdjustmentDocumentService.php:87` |
| ID-23 | P2 | D | Marketplace listing sync: check-then-create with no confirmed unique on `(seller_id, source_product_id)`. | `ReconcileListingsJob.php:60-89`; `SyncSellerListingsJob.php:48-77` |
| ID-24 | P3 | D | Channel webhook concurrent redelivery: unique index holds but the loser gets a 500 instead of the idempotent 202. | `ChannelWebhookController.php:133-135`; `ChannelOrderIngestService.php:18-31` |

---

## 3. What "correct" looks like here (reference, no patch)

- **Client:** one UUID per form session, minted on mount, reused across retries, reset only after a confirmed success. `ExpenseFormPage.tsx:27-37` already does this. Send it as `idempotency_key` in the body (the treasury and inventory controllers accept header or body).
- **Server, create paths:** resolve the key before validation, look up `(tenant_id, company_id, idempotency_key)`, and on a hit compare a payload hash: same payload returns the original with the same status; different payload returns 409/422. Claim the key with the INSERT under a partial unique index, catch `UniqueConstraintViolationException`, re-read after rollback. `StandaloneReceiptService.php:65-95` and `OutboxIngestor.php:241` are the in-repo reference implementations.
- **Server, transition paths:** inside the transaction, `lockForUpdate()` re-read the row, then evaluate the state guard on the locked instance. `DocumentPostingService::cancel()` `:363-374` is the in-repo reference.
- **Jobs with external calls:** claim a delivery row keyed by the natural id before calling out; skip when the claim already exists in a success state; `ShouldBeUnique` when the natural id is the job argument.

---

## 4. Corrections and disputes

| Lane claim | Ruling |
|---|---|
| Inventory I-2 "two concurrent receipts both over-receive stock and money" (P0 concurrency) | Overstated. `GoodsReceiptService::createDraft` checks remaining quantity unlocked, but `post()` re-asserts `assertQuantitiesWithinRemaining` at `:562` under the sorted row locks taken at `:225`, so the second receipt fails at posting. The **retry** scenario (Codex I-01: partial receipt, lost response, resubmit) is the real P0 and is kept as ID-5. |
| Codex I-05 "repository adjustment not idempotent" vs inventory lane "RepositoryAdjustment via `TreasuryMovementService` confirmed correct" | Both true at different layers: the movement service dedupes on the adjustment id, but the controller mints that id per request, so HTTP replay bypasses it. ID-7 stands. |
| Inventory I-12 counting-completion race | Backstopped by the unique index shipped 2026-08-23; not a live exposure. Dropped from the ranked list. |

---

## 5. Placement in the programme

- **Phase A (safe during onboarding, frontend-only or additive):** ID-1, ID-2, ID-3 (client key + controller catch), ID-4, ID-12. Added to `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` as Tasks 11 to 14.
- **Phase B lanes (after tenant #1 is live one quiet week):** B-4 concurrency lane absorbs ID-8..ID-11, ID-14, ID-15 (stale-model posting class); new B-9 treasury identity lane for ID-5, ID-6, ID-7, ID-22; new B-10 document identity lane (migration: `documents.idempotency_key` partial unique, request identity on receive/supplier-invoice/credit-note, conversion source links) for ID-13, ID-16, ID-17; new B-11 external-effects lane for ID-18..ID-21, ID-23, ID-24.

## 6. Not verified

No concurrent requests were executed; every race is argued from transaction boundaries, locks, unique indexes and stale-model use in the opened code. Provider-side dedupe (mail, Stripe, channel adapters, platform) is unknown and must not be relied on. `apps/erp-mobile` is not in this checkout, so the mobile counting client was not audited.
