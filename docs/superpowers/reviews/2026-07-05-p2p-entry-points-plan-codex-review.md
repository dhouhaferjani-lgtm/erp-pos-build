# Adversarial Plan Review: P2P Entry Points

Reviewed plan: `docs/superpowers/plans/2026-07-05-p2p-entry-points-plan.md`
Reviewed spec: `docs/superpowers/specs/2026-07-05-p2p-entry-points-design.md`
Mode: read-only on code; this report is the only written file.

## Findings

### BLOCKER: Task 3.2 misses receipt-line query sites that will expose or mutate draft receipts

Plan Task 3.2 lists only `ReceiptLineConsumptionPlanner`, `SupplierInvoiceMatcher`, `SupplierInvoicePostingService`, and "GoodsReceiptService if they read receipt lines" (plan lines 299-327). That is not the full runtime surface.

Evidence:
- `PurchaseOrderController::index()` has the `has_uninvoiced` filter as a raw `goods_receipt_lines` existence query without joining `goods_receipts.status` (apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:230-243). Draft lines would make a PO appear invoiceable.
- `PurchaseOrderController::receiptLines()` builds receipt-line picker results by `goods_receipts` subquery without a status filter (apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:805-825). Draft lines would show in the Wave 7 SI selector.
- `SupplierCreditNotePostingService::post()` locks all receipt lines for linked PO lines without a posted filter (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:151-158). A draft receipt line can switch credit-note reversal from the legacy PO-line path into the receipt-ledger path.
- Its `receiptLedgerSum()` also sums all receipt lines without filtering posted receipts (apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:514-518).
- The plan-listed sites are real and also currently unfiltered: planner base query (ReceiptLineConsumptionPlanner.php:30-34), matcher paid/free aggregates (SupplierInvoiceMatcher.php:151-153 and 414-416), posting lock query and sums (SupplierInvoicePostingService.php:82-89, 174-176, 222-224).

Exact plan correction: expand Task 3.2 to include all receipt-line consumers:
`ReceiptLineConsumptionPlanner`, `SupplierInvoiceMatcher`, `SupplierInvoicePostingService`, `SupplierCreditNotePostingService`, `PurchaseOrderController::index(has_uninvoiced)`, and `PurchaseOrderController::receiptLines()`. Add a shared local scope such as `GoodsReceiptLine::postedReceipts()` or explicit `whereHas/join goods_receipts.status = GoodsReceiptStatus::Posted` at every site. Add red tests for the PO `has_uninvoiced` filter, receipt-line picker, and supplier-credit-note reversal path with a draft receipt line present.

### BLOCKER: Task 3.3 under-specifies behavior preservation for `receiveGoods`, especially events and free quantities

The current `receiveGoods()` behavior is not just "create rows and counters"; it creates stock movement(s), dispatches `GoodsReceived` synchronously per movement, updates PO counters, and writes one receipt line with both `movement_id` and `free_movement_id`.

Evidence:
- Current signature is `receiveGoods(Document $purchaseOrder, array $receivedQuantities, array $batchData = [], array $freeQuantities = [], array $receivedUnitPrices = [], ?string $priceOverrideReason = null, ?string $actorId = null): GoodsReceiptResult` (GoodsReceiptService.php:59-67). The plan's `createDraft()` signature omits `priceOverrideReason`, so a `receiveGoods()` refactor cannot preserve price override audit stamping.
- Current free branch creates a separate zero-cost movement, dispatches `GoodsReceived`, records `free_movement_id`, and increments `free_quantity_received` (GoodsReceiptService.php:275-310, 365-389). Task 3.3 mentions "line `movement_id` backfill" singular, not `free_movement_id`.
- Current paid branch dispatches `GoodsReceived` before `GoodsReceiptLine::create()` and before final PO payload/status update (GoodsReceiptService.php:313-389, 398-409). The listener is synchronous and catches GL failures (PostGrIrOnGoodsReceipt.php:31-53); it is not queued after commit.
- Current listener idempotency uses the stock movement id as `source_id`, not receipt id (GeneralLedgerService.php:1043-1060, 1101-1109).

Exact plan correction: Task 3.3 must pin event order and free-branch behavior explicitly. Add tests that a receipt with both paid and free quantities creates two stock movements, emits/posts GR-IR by both movement ids in the same order as today, backfills both `movement_id` and `free_movement_id`, preserves `price_override_reason`, and leaves `receiveGoods()`'s public signature and response type unchanged. If event ordering is intentionally changed, the plan must call that out as a behavioral change and update dependent accounting tests.

### MAJOR: Task 4.3 should mandate `whereJsonContainsKey`, not leave JSON path querying ambiguous

The plan says to use "`payload->auto_generated` not null" and mentions pgsql `whereNotNull("payload->auto_generated")`, then says to use a "whereJsonContainsKey equivalent" if needed (plan lines 422-438). Laravel 12 in this worktree has an actual portable API for key existence.

Evidence:
- Installed Laravel provides `whereJsonContainsKey()` and `whereJsonDoesntContainKey()` (vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:2320-2349).
- SQLite compiles this to `json_type(...) is not null` (vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:237-242).
- Postgres compiles this to a JSONB key-existence expression (vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/PostgresGrammar.php:275-300).
- Existing code already uses JSON selector syntax for null checks and `whereJsonContains` for arrays (Document.php:369-376; UninvoicedDeliveryNoteService.php:64-71). Raw JSON extraction is only introduced where driver text extraction differs (FraudAlertRepository.php:58-63).

Exact plan correction: Task 4.3 should require:
- default PO list exclusion: `whereJsonDoesntContainKey('payload->auto_generated')`
- opt-in/include or badge query: `whereJsonContainsKey('payload->auto_generated')`
Do not use `like`; do not hand-roll raw SQL for this case. Keep one SQLite feature test and cite the Laravel method as the portability basis.

### BLOCKER: Task 5.2's pending-SI service changes are incomplete and would crash before posting guards run

The FormRequest relaxation alone is not enough. `CreateSupplierInvoiceService` currently assumes source documents and source lines exist throughout creation.

Evidence:
- Request currently requires `source_document_ids` with `min:1` and every `lines.*.source_line_id` (CreateSupplierInvoiceRequest.php:78-91).
- Service derives `$sourceDocumentIds` and then writes `source_document_id => $sourceDocumentIds[0]` (CreateSupplierInvoiceService.php:57-59, 109-114). With a pending invoice and no source docs, this is undefined.
- Service reads `$lineInput['source_line_id']` into non-null line data (CreateSupplierInvoiceService.php:97-105). Missing pending source lines will throw before a draft invoice is created.
- It always creates `source_line_id` from that non-null value (CreateSupplierInvoiceService.php:153-171). The DB column is nullable, but the current service shape is not (document_lines migration shows nullable FK at database/migrations/tenant/2025_12_11_194522_add_delivery_tracking_to_document_lines.php:28-32).
- Posting's existing hard guard for null source lines is in the matcher (SupplierInvoiceMatcher.php:205-213), but Task 5.2 requires a specific `PENDING_RECEIPT_UNLINKED` domain error.

Exact plan correction: Task 5.2 must require `CreateSupplierInvoiceService` to accept empty `source_document_ids`, nullable `source_document_id`, and nullable per-line `source_line_id` only when `pending_receipt=true`; persist null source_line_id; skip snapshot and auto-match or mark `match_status=Unmatched/Exception` intentionally; and add an explicit posting guard before matcher assertion that checks `payload.supplier_invoice.pending_receipt` and throws `PENDING_RECEIPT_UNLINKED`.

### MAJOR: Task 5.3 says "reuse `matchSnapshotAttributes`", but that method is private

Evidence:
- `CreateSupplierInvoiceService::matchSnapshotAttributes()` is private (CreateSupplierInvoiceService.php:214-248).
- `RematchDraftSupplierInvoicesCommand::snapshotForLine()` is also private and duplicates the logic (RematchDraftSupplierInvoicesCommand.php:148-188).
- Task 5.3 requires the link endpoint to "re-stamp match snapshot (reuse `matchSnapshotAttributes`)" (plan lines 480-492), but no callable API exists.

Exact plan correction: before the link endpoint implementation, extract snapshot calculation into an injectable service (for example `SupplierInvoiceMatchSnapshotService`) used by `CreateSupplierInvoiceService`, `RematchDraftSupplierInvoicesCommand`, and the link-receipts service. Add a test that pending lines get null snapshots before linking and correct `price_match_basis` / `matched_receipt_line_id` after linking.

### MAJOR: Task 5.4 cannot implement a non-ambient approval gate without changing `SupplierInvoicePostingService::post()` callers

Evidence:
- `SupplierInvoicePostingService::post()` currently accepts only `Document $supplierInvoice` (SupplierInvoicePostingService.php:56).
- The HTTP controller calls it without actor/user context (SupplierInvoiceController.php:224-242).
- Many tests call `app(SupplierInvoicePostingService::class)->post($invoice)` directly (for example SupplierInvoiceReceiptClearingTest.php paths reported by rg; service use appears in tests/Feature/Procurement/SupplierInvoiceReceiptClearingTest.php and tests/Feature/Accounting/SupplierInvoiceGlTest.php).

Exact plan correction: Task 5.4 must explicitly change the posting service signature to accept an actor/user or authorization context, update `SupplierInvoiceController::post()` to pass `$request->user()`, and update direct service tests. If backward compatibility is required, add a separate `postSystem()`/`postWithActor()` split rather than falling back to `auth()` or `app()`.

### MAJOR: Task 4.2 correctly suspects actor handling, but the plan must make the `PurchaseOrderService::confirm()` signature change mandatory

Evidence:
- `PurchaseOrderService::confirm()` currently accepts only `Document $purchaseOrder` (PurchaseOrderService.php:46-68).
- It stamps `confirmed_by` from `auth()->id()` inside a private method (PurchaseOrderService.php:73-83).
- `PurchaseOrderController::confirm()` also calls `confirm($lockedDocument)` without passing the already available `$user` (PurchaseOrderController.php:601-641).

Exact plan correction: Task 4.2 should not leave this as "verify confirm() accepts/derives actor". It must require `confirm(Document $purchaseOrder, ?string $actorId = null)` and use `$actorId ?? auth()->id()` only for backward compatibility, then update controller and auto-PO orchestration to pass explicit actor ids.

### MAJOR: Task 1.2's drift command is keyed to the wrong source id if it uses receipt id

The plan interface says the command reports posted goods receipts with no GR-IR entry, but its implementation note says "expected `goods_receipt` + receipt id" (plan lines 150-180). Actual GL source semantics are `goods_receipt` + stock movement id.

Evidence:
- `GeneralLedgerService::createGoodsReceiptGrIrEntry()` documents idempotency as `source_type = 'goods_receipt', source_id = $movementId` (GeneralLedgerService.php:1043-1046).
- It checks and creates `JournalEntry` with `source_id => $movementId` (GeneralLedgerService.php:1052-1060, 1101-1109).
- `GoodsReceived` event carries `movementId`, and the listener passes that to GL (GoodsReceived.php:20-35; PostGrIrOnGoodsReceipt.php:31-40).

Exact plan correction: Task 1.2 must left-join by receipt line movement ids, not receipt header id. For each posted receipt, every paid movement with positive `received_qty` and every free movement with positive `free_qty` that should accrue must be evaluated according to existing GL rules. If free movements at zero cost intentionally produce no entry, the report must not flag them. The command output can group missing movement entries by receipt number.

### MINOR: Task 1.1 contains wrong codebase assumptions

Evidence:
- The plan says to constructor-inject `GoodsReceiptService` into `PurchaseOrderController`, but it is already injected (PurchaseOrderController.php:66-76).
- The plan says to reuse helpers from `tests/Feature/Procurement/GoodsReceiptLedgerTest.php` (plan line 118), but no such file exists. Existing receipt ledger tests are under `tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php` and `tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`.
- Confirmed purchase orders are currently editable because `DocumentStatus::isEditable()` returns true for `Draft, Confirmed` (DocumentStatus.php:19-24) and `Document::isEditable()` delegates to it (Document.php:574-577), so the guard location in `PurchaseOrderController::update()` is real (PurchaseOrderController.php:431-480).

Exact plan correction: update Task 1.1 to say the controller already has `GoodsReceiptService`; add the new public method to the existing injection. Replace the nonexistent helper path with `tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php` or make the new test self-contained.

### MINOR: Task 2.3 points to the wrong existing preset preservation test location

Evidence:
- The plan says to extend an "EXISTING Wave 9 preset preservation test file" by locating `rg -l "preset" apps/api/tests/Feature/Procurement` (plan lines 228-244).
- Actual preset unit coverage is `apps/api/tests/Unit/Procurement/ProcurementPresetTest.php` (ProcurementPresetTest.php:13-35).
- Existing API preset preservation/round-trip tests are in `apps/api/tests/Feature/Procurement/ProcurementPolicyApiTest.php` (ProcurementPolicyApiTest.php:124-162, 164-198).

Exact plan correction: split Task 2.3 tests: update `tests/Unit/Procurement/ProcurementPresetTest.php` for enum bundle shape and `tests/Feature/Procurement/ProcurementPolicyApiTest.php` for API preservation behavior. Do not rely on a non-existent "Wave 9 preservation" file.

### MAJOR: Task 6.2 says "same PDF service" but `DocumentPdfService` only accepts `Document`

Evidence:
- `DocumentPdfService::generate()` is typed to `Document $document` and resolves Blade templates from `DocumentType` (DocumentPdfService.php:43-48, 121-133).
- Goods receipts are not documents per spec §8, and current templates are document-type templates only; there is no `goods_receipt.blade.php` today (resources/views/documents/templates lists `delivery_note.blade.php`, `purchase_order.blade.php`, etc.).

Exact plan correction: Task 6.2 must create a dedicated `GoodsReceiptPdfService` or generalize PDF rendering to a non-Document view-data contract. Do not route a `GoodsReceipt` through `DocumentPdfService::generate(Document)`. The test should use existing document PDF render tests only as style reference, not as direct service wiring.

### MAJOR: Consumer-matrix coverage in Task 4.3 is incomplete versus spec §6.4

Spec §6.4 requires default PO-list exclusion, opt-in inclusion, FE toggle/badge, PO detail/action/print visibility, goods-receipt/SI pickers included, AgedPayables included, RFQ award guard unaffected, document chain readers included.

Plan Task 4.3 covers PO list/default/detail/serializer, FE toggle, and AgedPayables, but not RFQ award guard or document chain readers (plan lines 422-438). The receipt-line picker inclusion is scattered into Task 4.1 rather than tested as an auto-PO consumer.

Evidence:
- Document chain readers have explicit payload-linked supplier-invoice logic in `Document::payloadLinkedSupplierInvoiceChildren()` using `whereJsonContains('payload->supplier_invoice->source_document_ids', $this->id)` (Document.php:363-377).
- RFQ award code uses RFQ payload group queries and creates POs through a separate converter path (PurchaseQuoteRequestAwardService.php appears in rg output; plan has no test task for auto-PO non-interference).
- AgedPayables service exists (AgedPayablesService), but no backend aged-payables test file was found under `apps/api/tests` by path scan. Cannot verify the plan's "locate the aged payables test file" instruction because no such API test path was found.

Exact plan correction: add Task 4.3 tests for document chain inclusion and RFQ award guard non-interference. Add or create an API test for `AgedPayablesService` if no existing backend test exists, instead of saying to extend a locate-only file.

### MAJOR: Spec §12 missing-column fail-closed test has no plan task

Spec §12 explicitly requires "Policy fail-closed: missing row / missing column (pre-migration simulation) ⇒ 403." The plan covers missing row in Task 2.2, but no task covers missing-column/pre-migration behavior.

Evidence:
- Task 2.2 test only deletes policy rows and resolves defaults (plan lines 202-226).
- Current resolver falls back to `ProcurementPolicy::defaultForVertical()` when no row exists (ProcurementPolicyResolver.php:24-51), and the current model has no new columns yet (ProcurementPolicy.php:53-78).

Exact plan correction: add a W2 test and implementation requirement for server-side policy assertions to treat absent new attributes as disabled/default true for approval. If true DB missing-column simulation is impractical, test the service-level resolver result with an old-shape policy object and require guarded accessors.

### MAJOR: Spec §9.4 enum-truth requirement is not planned

Spec §9.4 says `GoodsReceiptStatus::Draft` becomes reachable, `Cancelled` is Phase 2, and any case still unreachable at the end of v1 is removed. The plan makes Draft reachable but does not remove or implement `Cancelled`.

Evidence:
- Current enum already has `Draft`, `Posted`, and `Cancelled` (GoodsReceiptStatus.php:9-11).
- Plan W3 makes Draft reachable and W7 does not implement corrective receipt cancellation; Phase 2 defers corrective receipts (plan self-review lines 579-590).

Exact plan correction: either remove `GoodsReceiptStatus::Cancelled` in W3/W7 if still unreachable at v1 end, or add the corrective/cancel lifecycle implementation to this plan. Do not leave the enum case as an unreachable lie after this campaign.

## Task-By-Task Verification Notes

- Task 1.1: target controller and service exist. `GoodsReceiptService::poLineIdsWithReceipts()` does not exist. Referenced helper test `tests/Feature/Procurement/GoodsReceiptLedgerTest.php` does not exist.
- Task 1.2: `RematchDraftSupplierInvoicesCommand` exists and is single-tenant by command doc (RematchDraftSupplierInvoicesCommand.php:18-29), but it is not a tenant-looping base-command pattern. GR-IR source id in the plan is wrong; use movement ids.
- Task 2.1/2.2: policy model/resolver exist; new columns do not. Default construction is in `ProcurementPolicy::defaultForVertical()` and `firstOrCreateForCompany()` (ProcurementPolicy.php:90-119).
- Task 2.3: enum bundle method is `fields()`, backed by `values()` (ProcurementPreset.php:20-50), not a named "bundle" method.
- Task 2.4: seeder is a flat permission list plus per-role `syncPermissions()` arrays (RolesAndPermissionsSeeder.php:32-404, 417-670). Plan should name exact roles after inspection.
- Task 2.5: backend controller/request exist (`ProcurementPolicyController`, `UpdateProcurementPolicyRequest` found by rg); FE settings page/test and purchases locale files exist.
- Task 3.1: goods receipt schema exists and currently has non-null receipt_number plus unique index according to existing schema test expectations (GoodsReceiptLedgerSchemaTest path exists).
- Task 3.2: plan list is incomplete; see blocker above.
- Task 3.3: signatures and internal effects need stronger preservation tests; see blocker above.
- Task 3.4: `GoodsReceiptController` does not exist yet; `Inventory/Presentation/routes.php` exists and uses the required middleware stack plus `EnforceTokenTenantClaim` (Inventory routes.php:25).
- Task 4.1: receipt-line picker endpoint is currently `PurchaseOrderController::receiptLines()` (PurchaseOrderController.php:791-848), not in a separate serializer.
- Task 4.2: `PurchaseOrderService::confirm()` currently derives actor from `auth()`; see finding.
- Task 4.3: `DocumentData` currently has no `is_auto_generated` constructor property or serializer field (DocumentData.php:22-69, 179-226).
- Task 4.4: frontend `GoodsReceiptListPage.tsx`, purchases locale files, and routes structure exist; standalone receipt page does not exist.
- Task 5.1: `CreateSupplierInvoiceService::create()` exists; delivered-path orchestration will need to map fresh PO lines to invoice lines explicitly.
- Task 5.2/5.3: pending source-less invoice flow is not supported by current request/service shape; snapshot helper is private.
- Task 5.4: posting service lacks actor context; see finding.
- Task 5.5: `SupplierInvoiceCreatePage.tsx` and test exist.
- Task 6.1: no existing `EntryExitNoteController` or page found; create task is appropriate.
- Task 6.2: `delivery_note.blade.php` exists, but Document PDF service cannot accept `GoodsReceipt`; see finding.
- Task 7.1: `DocumentPostingService` exists, but no `revert()` route found in current document routes.
- Task 7.2: cannot verify the "3 scattered cancel implementations" count without a dedicated grep-and-review pass beyond this report; the plan correctly instructs implementers to grep.

## Verdict

NEEDS-REVISION
