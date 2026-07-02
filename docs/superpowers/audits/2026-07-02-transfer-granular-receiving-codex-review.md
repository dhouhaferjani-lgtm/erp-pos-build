# Verdict: REVISE

The spec has the right product direction: line-level receiving, per-batch counters, append-only receipt sessions, explicit shortfall disposition, and received-quantity-aware WAC are the right shape for transfer receiving. It is not implementation-ready at the owner's bar. The current-code grounding is mostly correct, but the design has blockers in the shortfall algorithm, write-off accounting, receipt idempotency, and location-scoped authorization. It also misses in-transit read-model updates and several industry-standard capabilities that are not acceptable to leave implicit for "complete" transfer receiving.

# Findings

## Blocker

1. **The write-off finalization algorithm is internally inconsistent and fails the all-short / receive-zero cases.**  
   The spec says "receive full at destination, then write the shortfall back down", but its step 2 lands only `thisSession`; step 3 then issues `shortfall` out of destination. In the current code, `StockAdjustmentService::issue()` hard-blocks if requested quantity exceeds available stock, then subtracts from the stock row (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:201-217`). If a final close receives 0 of 10, issuing the 10-unit shortfall from destination fails. If a final close receives 8 of 10, issuing 2 after landing only 8 leaves net +6 instead of +8. Current transfer completion lands the full allocation/line quantity via `receive()` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:225-249`), while cancellation restocks full in-transit quantity to source (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:310-350`). The spec must choose an executable shortfall sequence: either land the shortfall before issuing it, or define a new in-transit loss primitive that creates the correct stock, movement, and GL records without pretending the units were physically received.

2. **Write-off GL treatment is not designed end-to-end.**  
   Current `StockAdjustmentService::issue()` can persist a `MovementReason` and optional cost snapshot on the movement (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:181-233`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:801-849`), but it does not post GL. The existing batch write-off path explicitly calls `GeneralLedgerService::createInventoryWriteOffEntry()` after issuing aggregate stock and batch stock (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:77-121`); that GL method creates Dr COGS / Cr Inventory entries (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2124-2185`). The spec proposes `StockTransferShortfallDispositioned` as an accounting anchor, but leaves account wiring and fully-lost freight handling as open items. Since `write_off` is a first-class disposition, GL posting and idempotency cannot be deferred.

3. **Receipt API lacks an idempotency contract even though transfer initiation already has one.**  
   Current transfers have `idempotency_key` on `stock_transfers` with a tenant/company unique index (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:53-67`), and `StockTransferService::initiate()` replays an existing transfer before doing work (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:101-113`). The proposed `stock_transfer_receipts` schema has only `(transfer_id, sequence)` uniqueness and no client idempotency key. A mobile or flaky-network retry can double-receive the same physical receipt if the first request commits but the client times out. Sequence uniqueness does not solve client retries because the server can assign a new sequence on replay.

## Major

4. **The new receive endpoint does not carry forward the recently added location-scoping rules.**  
   The current controller restricts list/show visibility to transfers touching allowed locations (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:45-54`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:110-114`). It requires source access for store/initiate (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:127-133`), destination access for complete/receive (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:220-223`), and source access for cancel/restock (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:253-256`). Tests pin the same policy (`apps/api/tests/Feature/Inventory/StockTransferLocationScopeTest.php:29-39`, `apps/api/tests/Feature/Inventory/StockTransferLocationScopeTest.php:254-307`). The spec defines permissions but not location access for `/receive`, finalize, or `return_to_source`. That is a security regression unless explicitly specified and tested.

5. **In-transit visibility read models are missed.**  
   The spec correctly updates `WeightedAverageCostService::companyOwnedQuantity()`, which currently adds full `stock_transfer_lines.quantity` for `status = in_transit` (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:95-123`). But transfer incoming stock is also exposed by `LocationStockQueryService`, which sums full line quantity for in-transit transfers in distribution and incoming queries (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164`, `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:222-244`). Partial receipts would overstate incoming stock unless these queries also use `quantity - received_quantity` and include `partially_received`.

6. **The WAC narrative assumes transfer receipt blends unit cost, but current transfer receipt does not.**  
   Purchase goods receipt calls `WeightedAverageCostService::recordPurchase()` with landed unit cost and emits `GoodsReceived` for GR-IR (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:154-191`). Transfer completion calls `StockAdjustmentService::receive()` without unit cost (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:225-249`); that method increments stock and records a movement, but it does not update product WAC (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:88-120`). The only WAC update in transfer completion is transfer-cost capitalization after the status flip (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:255-270`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:565-580`). The spec's claim that landing shortfall units "blends N at `unit_cost_snapshot`" is not current behavior and should not be used as an accounting proof.

7. **Variant handling is not explicit enough for the new receive/write-off paths.**  
   Current transfer lines are variant-aware: `variant_id` was added with partial unique indexes (`apps/api/database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:26-53`), the model exposes it (`apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:18-35`, `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:42-62`), and current complete/cancel pass `variantId: $line->variant_id` into stock adjustments (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:227-248`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:323-345`). `StockAdjustmentService` rejects product-level operations for products with active variants (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:77-88`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:864-872`). The spec's algorithm says "same as complete" but does not explicitly require threading `variant_id` through every receive, write-off issue, return-to-source receive, movement event, and test case.

8. **Batch expiry-in-transit is under-specified.**  
   Dispatch enforces sellable FEFO batches: `assertAllocationsFollowFefo()` skips non-sellable batches and validates the submitted split (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:654-658`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:671-743`), and `assertBatchCanIssue()` rejects expired/recalled/inactive batches at dispatch (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745-768`). A batch can later become expired because `Batch::canBeSold()` depends on current expiry status (`apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:75-116`, `apps/api/app/Modules/BatchExpiry/Domain/Enums/ExpiryStatus.php:26-29`). Current receipt will still increment batch stock for the allocated batch if `batchId` is supplied (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:112-120`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:880-912`). The spec tracks per-batch received quantity, but it does not define whether expired-in-transit lots must be quarantined, auto-shorted with reason `expiry`, or allowed to land as ordinary on-hand.

9. **Discrepancy reason design loses reporting detail and includes a reason that contradicts the current transfer model.**  
   Current movement reasons include `Damage`, `Expiry`, `WriteOff`, and `CountCorrection`, but not `loss`, `theft`, or `short_shipment` as first-class movement reasons (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:17-31`). Mapping loss/theft/short-shipment to `WriteOff` means GL and stock movement reporting cannot distinguish them unless downstream reports join back to transfer discrepancy rows. Also, "short_shipment" says "never dispatched all", but current initiation issues the full line/batch quantity out of source before receipt (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:383-473`), so a short shipment must either be a source return/count correction or a source-side dispatch correction, not a destination-only discrepancy.

10. **The migration/API contract does not lock down receipt-line uniqueness or immutable replay semantics.**  
    Current batch allocation storage has a unique `(stock_transfer_line_id, batch_id)` constraint (`apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:14-35`). The new receipt-line schema has no stated unique key to prevent duplicate line/batch rows within one receipt and no idempotency key as noted above. Because receipt lines are append-only audit rows, the contract should define whether duplicates are illegal, merged, or preserved as multiple scans. Without that, API consumers and audit reports can disagree on one receipt's authoritative quantity.

11. **Concurrent receiving is only partially addressed.**  
    The current `complete()` path locks the transfer row, then acquires all product cost locks in one sorted call (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:186-208`) before receiving lines. The spec says to preserve the product-lock pattern, but it does not explicitly require locking the transfer row before calculating remaining quantities, assigning receipt sequence, validating over-receipt, and incrementing `received_quantity`. Product locks serialize per-product cost/stock operations, not duplicate receipt submission for the same transfer with disjoint products or retry races.

12. **The status/backfill plan must update all code paths that deserialize or filter status.**  
    `TransferStatus` currently has only four cases and labels (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:15-51`), and the controller status filter relies on `TransferStatus::tryFrom()` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:56-60`). Adding `partially_received` is storage-safe because `status` is `varchar(20)` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`), but the spec should require label/filter updates, frontend enum/type regeneration, and query updates wherever `status = InTransit` is used for operational visibility.

## Minor

13. **Several spec citations are stale or incomplete.**  
    `StockTransferController::complete()` is currently at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:205-235`, not the spec's cited `:174-200`. The original stock transfer line migration does define only `quantity`, `unit_cost_snapshot`, and `allocated_transfer_cost` at creation time (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:86-88`), but the spec omits the later `variant_id` migration (`apps/api/database/migrations/tenant/2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:26-53`). The `MovementReason` citation is also imprecise: `CountCorrection` is at line 24, `Damage`/`Expiry`/`WriteOff` at lines 26-28 (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:17-31`).

14. **`complete` backward compatibility should specify request-body behavior.**  
    The current complete controller accepts no validated body and simply calls the service after location access checks (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:205-235`). If `/complete` becomes "receive all remaining", the spec should state whether it ignores any body, rejects unexpected receipt/discrepancy fields, or delegates only to an internal receive-all command.

15. **Transfer receipt events need a replay/idempotency anchor stated as strongly as `GoodsReceived`.**  
    `GoodsReceived` uses `movementId` as the idempotency anchor (`apps/api/app/Modules/Inventory/Domain/Events/GoodsReceived.php:20-36`), and the GR-IR listener relies on the service being idempotent by movement id (`apps/api/app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php:22-40`). The proposed `StockTransferReceiptRecorded` and `StockTransferShortfallDispositioned` events should declare their immutable scalar payloads and unique replay keys, especially if accounting listens to them.

# Industry-Gap Table

| capability | industry standard behavior | spec's current treatment | verdict |
|---|---|---|---|
| Line-level and batch-level receiving | Receive by transfer line, lot/batch, and sometimes serial; maintain receipt history | Covered with line counters, batch counters, and append-only receipt tables | acceptable non-goal: serials are explicitly out of scope because repo has no serial model |
| Partial receipts over multiple sessions | Supported with status/progress and remaining in-transit visibility | Covered in state machine and schema | acceptable non-goal only if implemented now; the open question suggesting single-session Phase 1 conflicts with the owner bar |
| Over-receipt tolerance policies | Configurable tolerances, approvals, and audit trail; may create variance or source adjustment | Hard cap at sent quantity; surplus handled by separate count correction | real gap |
| In-transit visibility | Remaining in transit is visible by destination/location and reduced as receipts post | WAC query addressed, but `LocationStockQueryService` incoming queries missed | real gap |
| Receipt reversal / undo | Reverse or correct an erroneous receipt with audit trail and financial reversal | Not specified; no undo/void receipt model | real gap |
| Concurrent receiving | Serialized receive sessions, idempotent retries, no over-receipt races | Product lock pattern mentioned, but no receipt idempotency key and no explicit transfer-row/line counter lock contract | real gap |
| Mobile scan-receive | Barcode/lot scan entry, scan-to-increment, lot validation, offline/retry-safe idempotency | Desktop dialog only; API is line_id/batch_id based | real gap |
| Discrepancy disposition | Explicit reason, quantity, disposition, approval for loss, GL posting | Mostly covered, but GL and reason taxonomy are incomplete | real gap |
| Batch/expiry vertical handling | FEFO lot identity preserved; expired/recalled lots cannot silently land as sellable stock | Dispatch FEFO preserved; expiry-in-transit receipt policy missing | real gap |
| Transfer-cost allocation | Landed/freight cost capitalized over received goods, loss portion expensed | Directionally covered, but fully-lost transfer and GL treatment left open | real gap |
| Cancel after partial receipt | Cancel disabled once receipt exists; use final close/correction | Covered | acceptable non-goal to not support cancel after receipt |

# Concrete Spec-Change Recommendations

1. **Replace the shortfall write-off algorithm. Resolves findings 1, 2, 6.**  
   State exactly what stock movements happen for each disposition. For `write_off`, either receive the shortfall into destination and immediately issue the same shortfall, or create a dedicated in-transit-loss operation that produces the same inventory, batch, movement, and GL facts without touching sellable on-hand. The spec must include examples for receive 0, receive 8 of 10, and all lines short.

2. **Make write-off accounting a first-class part of the spec. Resolves findings 2, 6, 15.**  
   Define the accounting listener/service, source idempotency key, amount basis (`shortfall × unit_cost_snapshot` vs current WAC), currency scale, journal `source_type/source_id`, and fully-lost freight treatment. Do not leave shrinkage account mapping or transfer-cost expensing as an open implementation question for this spec.

3. **Add receipt idempotency. Resolves findings 3, 10, 11, 15.**  
   Add `idempotency_key` to `stock_transfer_receipts` with `UNIQUE (tenant_id, company_id, transfer_id, idempotency_key)` or equivalent. Require it in `POST /stock-transfers/{transfer}/receive`, replay the prior receipt response on retry, and use it as the event/audit replay anchor. Keep sequence as display numbering, not idempotency.

4. **Specify the concurrency contract. Resolves finding 11.**  
   Require every receive/finalize/legacy-complete path to `lockTransfer()` first, validate remaining quantities under that lock, assign receipt sequence under that lock, update line and batch counters under that lock, and only then run stock adjustments under the sorted product lock pattern.

5. **Carry location scoping into `/receive`. Resolves finding 4.**  
   Require destination access for any receive/finalize operation. For `return_to_source`, either require source access too or explicitly document and test the exception. Add HTTP tests mirroring `StockTransferLocationScopeTest`: incoming receive allowed, foreign receive denied, outgoing-to-foreign receive denied, and return-to-source policy covered.

6. **Update every in-transit read model to use remaining quantity. Resolves findings 5, 12.**  
   Change WAC and `LocationStockQueryService` transfer queries from `SUM(quantity)` over only `in_transit` to `SUM(quantity - received_quantity)` over `in_transit` and `partially_received`. Add tests for product distribution and incoming-location views, not just WAC.

7. **Make variant threading explicit in the API/service/test plan. Resolves finding 7.**  
   Every receive, write-off issue, return-to-source receive, batch movement, and stock movement event must pass the transfer line's `variant_id`. Add at least one variant transfer receiving test, including partial receipt and shortfall disposition.

8. **Define expired-in-transit lot behavior. Resolves finding 8.**  
   If a dispatched batch expires or is recalled before receipt, the receive UI/API must force an explicit path: quarantine, write off with reason `expiry`, or return to source. Do not let an expired/recalled lot silently land as ordinary sellable destination on-hand. Add per-batch tests where `canBeSold()` changes between initiate and receive.

9. **Refine discrepancy reasons. Resolves finding 9.**  
   Either add first-class movement/accounting reasons for loss/theft/short-shipment or state that the transfer discrepancy table is the reporting source and all stock movements collapse to `WriteOff`. Rename `short_shipment` unless the algorithm also returns the not-shipped quantity to source or adjusts the source-side dispatch record.

10. **Add receipt reversal/correction support or explicitly phase it with compensating workflows. Resolves industry reversal gap.**  
    At minimum, define how an operator corrects an accidental receipt before final close, and how a finalized receipt is reversed through auditable compensating stock movements and GL reversals.

11. **Add controlled over-receipt design or explicitly reduce the product claim. Resolves industry over-receipt gap.**  
    If matching industry standards is required, add company/location tolerance settings, manager approval, and a source-side issue/positive discrepancy path. If hard cap remains, label it a deliberate stricter-than-standard policy and explain the separate workflow operators must use at receiving time.

12. **Add mobile scan-receive contract. Resolves industry mobile gap.**  
    The desktop dialog can remain the first UI, but the API should also support scanning by transfer number plus product/variant barcode and batch number/GS1 lot/expiry, with idempotent scan increments. This is especially important for the parapharmacy lot/expiry differentiator.

13. **Tighten migration/API details. Resolves findings 10, 12, 14.**  
    Add model fillable/casts for new fields, receipt-line uniqueness rules, status labels/types, generated TypeScript updates, response shapes, no-op receive rejection (`received_quantity = 0` with `finalize=false`), and legacy `/complete` body handling.
