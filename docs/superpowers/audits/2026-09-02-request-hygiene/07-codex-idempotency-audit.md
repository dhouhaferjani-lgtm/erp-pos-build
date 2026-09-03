# Codex independent audit — idempotency

## Method

Read-only static audit. No files were modified and no patches are proposed.

I:

- Booted Laravel’s route table and inventoried every API route accepting `POST`, `PUT`, `PATCH`, or `DELETE`.
- Traced high-consequence controllers through services, transactions, database uniqueness, queued listeners, frontend mutations, POS SQLite, fiscal ingestion, and external adapters.
- Treated a state check outside the relevant lock as concurrency-unsafe.
- Distinguished duplicate prevention from true request idempotency. A correct request mechanism needs a stable caller key, atomic claim, payload fingerprint, proper tenant/company/user scope, and replay of the original outcome.
- Used the mandatory `superpowers:using-superpowers` skill to follow the repository’s skill-selection workflow.

No concurrent test suite or live external-provider calls were run.

## Route inventory

The current application exposes **603 mutating API route entries**:

| Method | Count |
|---|---:|
| POST | 415 |
| PATCH | 79 |
| DELETE | 85 |
| PUT | 20 |
| PUT\|PATCH | 3 |
| GET\|POST\|HEAD | 1 |
| **Total** | **603** |

All top-level route families, accounting for all 603 entries:

```text
accounts=2; admin=37; auth=8; bank-statement-lines=5; bank-statements=5;
batches=7; catalog-carts=7; categories=4; channels=6; companies=14;
compliance=2; composite-items=8; contacts=5; correcting-entries=3;
coupons=6; credit-notes=4; delivery-notes=3; document-ingestions=4;
documents=13; enrichment-results=2; expense-categories=3;
expense-recurrences=5; expenses=6; fiscal=4; fiscal-periods=2;
fraud-alerts=3; fraud-settings=2; goods-receipts=3; imports=4; income=4;
instrument-remittances=6; inventory=27; invoices=12; journal-entries=2;
labels=2; line-entry=1; locations=4; loyalty=34; menu-categories=5;
menus=4; migration-wizard=2; modifier-groups=4; modifiers=2;
notifications=2; orders=6; parapharmacy=12; partners=5;
payment-instruments=11; payment-methods=2; payment-repositories=4;
payments=8; platform=6; pos=58; price-lists=8; pricing=6;
procurement-policies=1; product-attributes=3; product-variants=2;
products=12; progression=4; promotions=6; purchase-hub=2;
purchase-orders=5; purchase-quote-requests=5; quotes=5; recipes=6;
replenishment-requests=5; return-notes=4; roles=3; sales-withholding=1;
scheduling=12; service-categories=3; services=3; settings=3;
smart-payment=2; smart-prompts=1; statement-import-profiles=3;
stock-adjustments=5; stock-movements=1; stock-reservations=2;
stock-transfers=3; storefront=1; supplier-invoices=4; support-access=5;
taxation=4; uom=5; users=9; variants=2; vat=4; vehicles=5;
vouchers=4; webhooks=3; withholding=10; workshop=31
```

The following consequence counts are overlapping URI-based classifications; they should not be summed. Mechanism counts are not presented as exact because equating a `PUT`, state check, unique constraint, or request key would materially overstate protection. Confirmed mechanisms and gaps are enumerated below.

| Class | Routes counted | With mechanism | Mechanism type | Without mechanism |
|---|---:|---|---|---|
| (a) Numbered/fiscal documents | 87 | Mixed | State transitions, source locks, procurement claim rows, ingestion claims | Generic document creation, several conversions, normal supplier-invoice creation |
| (b) Stock movement | 55 | Mixed | Optional request keys, movement identities, counting replay markers, fiscal projections | PO receive, web stock-transfer creation, web stock-adjustment creation |
| (c) Money/GL | 72 | Mixed | Optional payment keys, stable treasury movement identities, bank-file hashes, conditional opening-balance claims | Vendor prepayment refund, repository adjustment, frontend payment/split-payment calls, stale document-posting paths |
| (d) Fiscal/hash chain | 32 | Mixed | POS canonical-envelope equality, chain sequence uniqueness, projection row state | B2B document chain append, manual journal posting, legacy shift close |
| Draft/create surfaces | 87 | Mixed | Locked updates to known drafts, stable keys in expense/income forms | First autosave, ordinary document forms, remittance drafts |
| External/sync | 31 | Limited | Some payload hashes and downstream keys | Emails, Stripe event processing, product publishing, enrichment submission/feedback |

## Findings

| ID | Severity | Class | Evidence | Replay scenario | What double-applies |
|---|---|---|---|---|---|
| I-01 | **P0** | Stock + GL | The PO receive endpoint accepts quantities but no request identity (`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:767`). `receiveGoods()` always creates a new receipt (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:72`), and `createDraft()` unconditionally inserts it (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:116`, `:137`). Posting records a new purchase movement (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:708`). | Response is lost after a partial receipt; the same request is resubmitted while the PO still has enough remaining quantity for both applications. | Second GRN, stock receipt, WAC movement, `GoodsReceived`, and movement-keyed GR/IR posting. |
| I-02 | **P0** | Money + GL | Payments support an optional header/body key (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:128`) and short-circuit only when supplied (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:338`). The main web form sends no key (`apps/web/src/features/treasury/PaymentForm.tsx:664`, `:679`), and its submit handler does not return the mutation promise (`apps/web/src/features/treasury/PaymentForm.tsx:747`) while the button only uses RHF `isSubmitting` (`apps/web/src/features/treasury/PaymentForm.tsx:1353`). | Double-click, page-level retry, or lost response from `POST /payments`. | Second payment, allocation, treasury movement, GL entry, and payment event. |
| I-03 | **P0** | Money + GL | The split-payment UI posts only `splits` (`apps/web/src/features/treasury/SplitPaymentForm.tsx:89`). The server’s dedupe is conditional on an optional key (`apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:119`, `:129`). | A successful response is lost and the operator repeats the split payment. | A second batch of payment rows, allocations, treasury movements, and GL effects. |
| I-04 | **P0** | Money + GL | Vendor prepayment refund accepts amount/method/repository but no request key (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php:231`). It creates a new refund payment every call (`apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:115`), a negative allocation (`:139`), increases PO balance due (`:158`), posts GL (`:187`), and moves repository cash using the freshly minted payment ID (`:204`, `:209`). | Lost response followed by identical refund retry while enough allocated prepayment remains. | Second refund, negative allocation, PO balance restoration, GL reversal, and cash outflow. |
| I-05 | **P0** | Money + GL | Repository adjustment creates a new server UUID for every HTTP invocation (`apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryAdjustmentController.php:58`, `:67`) even though the response advertises `idempotent_replay` (`:104`). | Same HTTP request is replayed. Internal movement dedupe sees a new adjustment identity. | Second repository movement and repository-adjustment journal entry. |
| I-06 | **P0** | Stock | Stock adjustment dedupe is optional (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockAdjustmentController.php:132`). The service admits its read/insert race returns a confusing failure rather than replaying the outcome (`apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:87`). The web form omits the field (`apps/web/src/features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:260`). | Lost response or resubmission of an immediate-post adjustment. | Second adjustment document and second stock movement. |
| I-07 | **P0** | Stock | Stock transfer has an optional key and lookup (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:103`), backed by a unique index (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:53`, `:67`). The web payload omits it (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:694`). Creation immediately moves source stock into transit (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:185`). | Lost response followed by resubmission. The disabled button protects only the currently mounted in-flight mutation (`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:1031`). | Second transfer and second source-to-in-transit movement. |
| I-08 | **P0** | Fiscal chain | B2B posting refreshes, but does not lock, the document before the inner state check (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:110`, `:123`). Chain allocation locks only the previously posted row (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:673`) and explicitly documents that no `(company_id,type,chain_sequence)` uniqueness exists (`:658`). | Two confirmed documents begin posting together. Both statements can snapshot the same predecessor before one waits for its lock. | Same chain sequence/previous hash on two documents: a fiscal fork, plus duplicated downstream posting events. |
| I-09 | **P0** | GL + fiscal chain | Manual journal posting reads and checks the draft outside a lock (`apps/api/app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:148`, `:158`). `postEntry()` does not open a transaction (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3051`), while its transaction-scoped advisory lock is taken later (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3754`). The stale model is then updated and an event returned (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3784`, `:3793`). | Concurrent posting of the same or different draft entries. In autocommit, the transaction-scoped advisory lock ends with its own SQL statement and does not protect subsequent max/sequence reads. | Fiscal chain collision or resealing of the same entry, plus duplicate `JournalEntryPosted` processing. |
| I-10 | **P0** | Money + GL | Expense and income controllers check status before entering their services (`apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php:216`, `:228`; `apps/api/app/Modules/Income/Presentation/Controllers/IncomeController.php:184`, `:194`). Their services check the same stale models and enter transactions without re-fetching/locking them (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:383`, `:405`; `apps/api/app/Modules/Income/Application/Services/IncomeService.php:148`, `:154`). GL creators unconditionally create source entries (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4765`, `:4768`; `:4904`, `:4907`). | Concurrent `post` calls for an unpaid/non-repository expense or non-received/non-repository income. | Two journal entries for one source document. Repository-movement uniqueness only helps branches that actually create a repository movement. |
| I-11 | **P0** | Fiscal + POS | Legacy shift close loads the shift outside the transaction (`apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:104`, `:108`). `closeShift()` checks the stale object and never row-locks/reloads it (`apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:156`, `:162`). Each caller inserts an unrestricted closing operation (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:225`, `:230`) and emits `ShiftClosed` (`apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:214`, `:226`). | Two v2/legacy close requests race. V3 terminals are correctly rejected by this REST path (`apps/api/app/Modules/POS/Presentation/Controllers/ShiftController.php:120`). | Second closing cash-drawer record and second NF525 `shift.closed` audit event. |
| I-12 | **P1** | Draft/document | The first autosave uses `draft_id: existingDraftId || draftId` and does not gate against an already running save (`apps/web/src/hooks/useDraftAutoSave.ts:133`, `:146`, `:163`). On a null ID, the backend creates a fresh draft (`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:104`, `:118`, `:250`). | Two initial debounced/manual saves overlap before React receives the first draft ID. | Two draft documents. |
| I-13 | **P1** | Draft/document | The ordinary document form creates with `apiPost(apiEndpoint, data)` and no request identity (`apps/web/src/features/documents/DocumentForm.tsx:390`). Submission creates rather than updating when not in edit mode (`apps/web/src/features/documents/DocumentForm.tsx:481`). The pending state prevents a local double-click but cannot cover response loss (`apps/web/src/features/documents/DocumentForm.tsx:495`, `:753`). | Browser/network retry or operator resubmission after ambiguous success. | Second quote, order, invoice, PO, or other draft; both may later acquire numbers. |
| I-14 | **P1** | Document conversion | Quote conversion checks `converted_to_order_id` before beginning its transaction (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/QuoteToSalesOrderConverter.php:118`, `:129`) and creates the order before linking the source (`:130`, `:148`). No source lock or source-key uniqueness is present here. | Concurrent conversion requests both observe an unconverted quote. | Two sales orders and two conversion events. |
| I-15 | **P1** | Document conversion | Sales-order delivery checks remaining/full-delivery state on the caller’s loaded model (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php:129`, `:146`). The transaction locks the header but deliberately continues using the already-loaded order (`:177`, `:198`). Both full and partial paths create a delivery after locking without refreshing/revalidating (`:216`, `:277`). | Second request waits for the first header lock, then proceeds using its stale order and line collection. | Second delivery note; if subsequently confirmed, duplicated stock consequences. |
| I-16 | **P1** | Supplier document | `idempotency_key` is required only for invoice-first-delivered (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:101`). Ordinary creation delegates directly to the create service (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:223`, `:230`), which allocates a number and inserts a new supplier invoice (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:69`, `:120`). | Normal supplier-invoice response is lost and retried. | Second numbered supplier invoice. |
| I-17 | **P1** | Supplier orchestration | Invoice-first correctly reuses the standalone receipt key, then reads a nullable `supplier_invoice_id` without claiming or locking it (`apps/api/app/Modules/Procurement/Application/InvoiceFirstOrchestrator.php:27`, `:40`). It creates the invoice and only afterwards updates that pointer (`:71`, `:78`). | Two calls share a key: both receive the same receipt, both see no supplier invoice, then both create one; last pointer wins. | Two supplier invoices, one orphaned from the idempotency row. |
| I-18 | **P1** | Credit note | Invoice-to-credit conversion accepts reason plus amount/lines but no request identity (`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/InvoiceToCreditNoteConverter.php:99`, `:137`). The service locks the source and checks aggregate headroom, but then creates a fresh draft (`apps/api/app/Modules/Document/Application/Services/CreditNoteService.php:812`, `:845`, `:862`; line mode `:940`, `:994`). | Sequential replay of a partial credit while sufficient headroom remains. | Second credit-note draft; later posting/refunding can turn it into a monetary duplication. |
| I-19 | **P1** | Email | Both synchronous and queued document-email endpoints have no delivery identity (`apps/api/app/Modules/Communication/Presentation/Controllers/DocumentEmailController.php:35`, `:78`). They call `Mail::send` or `Mail::queue` directly (`apps/api/app/Modules/Communication/Application/Services/DocumentEmailService.php:60`, `:66`, `:118`, `:124`). | Double-click or timeout after the mail provider accepted the message. | Second email or second queued mailable. |
| I-20 | **P1** | External channel | Product publishing uses only an after-the-fact payload hash (`apps/api/app/Modules/Channel/Application/Services/ChannelService.php:61`, `:70`). It persists local state, calls the external adapter, and only then stores the successful hash (`:74`, `:82`, `:85`). The job itself has no uniqueness middleware (`apps/api/app/Modules/Channel/Application/Jobs/DispatchProductToChannelJob.php:15`, `:34`). | Remote push succeeds but the worker dies before `last_sync_hash` is saved, or two jobs race. | Second product publish/update call. |
| I-21 | **P1** | External channel | Stock sync creates or finds an operation by key, but immediately resets even an acknowledged operation to Pending (`apps/api/app/Modules/Channel/Application/Jobs/DispatchStockChangeToChannelJob.php:46`, `:57`) and calls the adapter again (`:63`). | Queue redelivery after acknowledgment. | Second remote stock push unless every adapter independently honors the passed key. |
| I-22 | **P1** | External platform | Product submission mints a fresh `Idempotency-Key` inside each call (`apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php:77`, `:93`). Bulk submission has no key (`:110`, `:126`). Feedback jobs retry three times and call the provider without a visible delivery identity (`apps/api/app/Modules/Product/Application/Jobs/SendEnrichmentFeedbackJob.php:19`, `:25`, `:42`). | Caller retry or worker crash after remote acceptance. | Second enrichment request, bulk submission, or feedback operation. |
| I-23 | **P1** | Webhook/email | Stripe event ID is parsed but not persisted or claimed (`apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:107`); the class comment explicitly records missing event-id idempotency (`:64`). Payment rows use `updateOrCreate`, but notifications run on every delivery (`apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:291`, `:315`; failures `:361`, `:392`). The notification is queued mail (`apps/api/app/Modules/Billing/Notifications/InvoicePaidNotification.php:14`). | Stripe redelivers the same signed event. | Duplicate tenant/admin email notifications and any other non-idempotent handler side effects. |
| I-24 | **P2** | Key correctness | Payment keys are optional and scoped tenant+company only (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:128`, `:159`); stock transfer returns whatever row has the same key without comparing payloads (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:103`). Stock adjustment does the same and documents the race-loser inconsistency (`apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:87`). Expense/income metadata queries use only the key, not company/user (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:74`; `apps/api/app/Modules/Income/Application/Services/IncomeService.php:49`). | A key is accidentally reused with a changed amount, destination, partner, or company context. | Usually no second write, but the wrong prior resource is silently returned; behavior is not a trustworthy replay contract. |

## Top 10 expanded

### 1. Purchase-order receipt

This is the most direct duplicate-stock path. A request identity should be mandatory for each receive command, distinct from the PO or receipt ID. The atomic transaction should claim `(tenant, company, PO, key)`, store a canonical request hash, create/post the GRN once, and retain its response identity. Reuse with the same hash returns the original receipt; reuse with different quantities is a conflict. Fiscal/stock command keys should not expire merely because a browser session ended.

### 2. Payment and split payment

The backend already has most of a mechanism, but the first-party callers do not participate. A key should be generated once per form/session intent and used for every retry of that intent. The server claim must include a request fingerprint and preserve the original result/status. Split-payment child identities should remain derived from the parent command key.

### 3. Vendor prepayment refund

The refund requires its own caller-stable command ID. The refund payment, negative allocation, GL reversal, and repository movement should all be downstream projections of that one identity. The current freshly minted payment ID is too late: it makes each HTTP replay look like a legitimate new refund.

### 4. Stock transfer

Make the existing key mandatory for initiation and have every official client send a stable UUID. The claim should store the source, destination, line/batch quantities, and payload hash. A duplicate must return the already-created transfer; changed payload under the same key must fail explicitly.

### 5. Stock adjustment

The existing optional column and unique index are a useful base. Correct behavior also requires mandatory client participation, request hashing, and recovery of the unique-race loser as the original successful response. A duplicate immediate-post request must not be routed through `post()` a second time.

### 6. Repository adjustment

The HTTP request must supply or derive a stable adjustment command ID; generating it in the controller defeats request-level idempotency. The same identity should key the adjustment document, GL source, and repository movement, with one stored response.

### 7. B2B document fiscal chain

A company/type chain-head serialization primitive is needed for the entire predecessor-read/sequence-allocation/seal operation. It should be inside the same transaction as a lock or conditional claim of the document being posted. A unique `(company_id, type, chain_sequence)` constraint should be the final backstop, and the posted event should be emitted from a uniquely keyed transactional outbox.

### 8. Manual journal and expense/income posting

Posting should reload and lock the source row, then conditionally claim its Draft→Posted transition. Every derived journal entry should have a unique source identity, such as `(source_type, source_id, posting_leg)`. A transaction-scoped advisory lock is useful only when all protected reads and writes occur in that same transaction.

### 9. Legacy shift close

Close should claim `OPEN → CLOSED` against the current database status while holding the shift row lock. There should be at most one closing drawer operation per shift and one fiscal close-event identity. Replays should return the existing closed shift and closing record.

### 10. Email, webhook, and external calls

Use durable delivery/outbox records keyed by the business action:

- Document email: `(document, recipient set, template/version, request key)`.
- Stripe: provider event ID, claimed before dispatching notifications.
- Channel publication: local operation ID passed to the remote provider.
- Enrichment: persist the generated key before the first external attempt.

The outbox should record Pending/InFlight/Succeeded plus payload hash and provider result. A retry resumes or returns the prior delivery instead of inventing a new provider request.

## Done well

- POS checkout generates a stable key atomically with its in-flight gate (`apps/pos/src/stores/paymentStore.ts:1059`) and passes it into receipt creation (`:1107`, `:1122`).
- Offline receipt creation returns the existing SQLite receipt before repeating inserts, hash advancement, or voucher updates (`apps/pos/src/lib/offline/receiptService.ts:334`, `:345`).
- Device fiscal authoring uses tenant/terminal/source identity (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts:576`).
- Server fiscal ingestion atomically inserts or resolves a sequence conflict (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:241`) and verifies ID, hash, canonical bytes, and source tuple before accepting a replay (`apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:1097`, `:1105`). Non-identical conflicts are quarantined (`:1115`).
- Fiscal projections combine queue overlap protection with a row lock and terminal-state short-circuit (`apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:183`, `:206`, `:234`, `:254`).
- POS audit sync treats a duplicate event ID as a duplicate, not another event (`apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php:24`, `:131`).
- Z-report sync returns the existing report only when its fiscal hash matches, otherwise conflicts (`apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:161`).
- Inventory-counting replay protection is explicit: modern lines carry an atomic replay marker and legacy lines check their existing movement identity (`apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:139`, `:147`, `:204`, `:212`).
- Bank-statement import hashes the source file before preview (`apps/api/app/Modules/Treasury/Application/Services/StatementImportService.php:53`), binds and expires the preview token (`:73`, `:109`, `:115`), then rechecks duplicates under repository/profile locks (`:154`, `:168`, `:180`).
- Opening-balance transitions use conditional current-state claims instead of trusting stale models (`apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:390`, `:398`, `:458`, `:463`).
- Standalone procurement receipt claims its key with an insert and recovers unique-race losers (`apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php:65`, `:68`, `:95`).
- Expense and income creation clients generate and reuse stable UUIDs (`apps/web/src/features/expenses/pages/ExpenseFormPage.tsx:27`, `:37`; `apps/web/src/features/income/pages/IncomeFormPage.tsx:25`, `:33`), backed by unique metadata keys.
- TanStack mutations do not retry automatically (`apps/web/src/lib/queryClient.ts:11`). This limits accidental replay but does not replace server idempotency.

## Blind spots

- Route counts reflect the current environment’s successfully booted Laravel route table. Feature-flagged routes absent from this environment may not be represented.
- Migrations and constraints were inspected statically; they were not compared with every deployed tenant database for drift.
- No adversarial concurrent requests were executed. The race findings follow transaction boundaries, locks, unique constraints, and stale-model use in the opened code.
- Remote channel, mail, Stripe, and enrichment provider behavior could not be verified. An adapter may offer additional dedupe, but the repository cannot safely depend on undocumented provider behavior.
- `apps/erp-mobile` is not present under the current `apps/` tree, so there was no mobile client to audit.