# Adversarial Review: Live Inventory Counting

Overall verdict: **needs rework**. The spec identifies the right business need, but the design relies on replay, blocking, onboarding, and POS sync mechanisms that are either absent from the current codebase or underspecified at the exact points where correctness matters. The highest-risk failures are replaying against ambiguous or untrusted instants, rejecting stock-blocked sales at the wrong server boundary, treating opening counts as additive receipts, and allowing overlapping/finalizing counts to race with asynchronous POS stock projections.

## BLOCKER

### Blocking mode rejects the wrong server path

**Failure scenario:** A POS device signs fiscal receipts offline or during a stale sync window while a count is in blocking mode. When the device syncs, the server accepts the fiscal event envelope and the projection later decrements stock. The only current hard stock rejection lives in the retired receipt/order creation path, while the active fiscal ingestion path accepts events and the projection path only logs insufficient stock before proceeding. The result is a legally signed sale that bypasses the proposed count block and still mutates inventory.

**Evidence:**
- Spec §5 says: "Server-side write guard for POS sale stock movements for counted scope; client flag is advisory only."
- Spec §6 says blocking mode should "force POS sync/config heartbeat; terminals must stop creating sale lines for the blocked scope."
- `apps/api/app/Modules/POS/routes.php:145-151` marks `POST /pos/receipts` retired and directs callers to fiscal event sync.
- `apps/api/app/Modules/POS/routes_orders.php:43-49` marks `POST /pos/orders/{id}/close` retired and directs callers to fiscal event sync.
- `apps/api/app/Modules/Fiscal/routes.php:15-29` exposes the active `POST /pos/sync/fiscal-events` ingestion endpoint.
- `apps/api/app/Modules/Fiscal/Presentation/Http/Controllers/FiscalEventIngestionController.php:25-34` documents the endpoint as accepting valid envelopes and only rejecting validation/duplicate-signature issues.
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:916-928` is where `pos_stock_policy === 'block'` throws for insufficient stock, but that service is on the retired receipt path.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1056-1063` logs insufficient stock and continues instead of rejecting.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1073-1076` updates stock and creates the stock movement after the log-only insufficient-stock branch.

### Replay math lacks row-direction and reversal semantics

**Failure scenario:** A counted product has a sale after `T`, then a reversal row, then a customer return, or a count correction adjustment with `movement_type = adjustment`. The spec's `expected_now = final_qty - outflows(T->now] + inflows(T->now]` requires each movement to be classified correctly by physical direction. The current model explicitly warns that `movement_type` alone is not enough for adjustments and reversals. If replay sums by enum class without using stock-before/stock-after deltas and reversal links, it will double-count, invert, or fail to cancel rows.

**Evidence:**
- Spec §4 defines replay as: "expected_now = final_qty - outflows(T->now] + inflows(T->now]".
- Spec §4 says: "Movement direction mapping: Sale/POS issue/transfer_out = outflow; receipt/return/transfer_in/opening = inflow; adjustment uses signed quantity delta."
- Spec §4 says: "Reversals: reversed rows are excluded by following reverses_movement_id pairs."
- `apps/api/app/Modules/Inventory/Models/StockMovement.php:43-52` documents `reverses_movement_id` relationships but there is no replay implementation in the existing count finalization path.
- `apps/api/app/Modules/Inventory/Models/StockMovement.php:168-185` warns that callers needing physical direction should compare before/after values, because `MovementType::Adjustment` may be inbound or outbound.
- `apps/api/app/Modules/Inventory/Enums/MovementType.php:16-23` treats `Adjustment` as inbound in `isInbound()` and not outbound in `isOutbound()`, which conflicts with count corrections that can reduce stock.
- `apps/api/app/Modules/Inventory/Enums/MovementReason.php:21-24` explicitly says `count_correction` can increase or decrease stock and direction must be determined by stock before/after.
- `apps/api/app/Modules/Inventory/Enums/MovementReason.php:33-44` maps `count_correction` to `MovementType::Adjustment`, again requiring signed-delta handling.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1097-1214` restocks returns as receipt movements, with `MovementReason::POSReturn` at `1203-1204`.

### Finalize replay can race POS projection writes

**Failure scenario:** A count is finalized while a fiscal receipt projection for the same `(product, location)` is concurrently applying. The finalizer reads current stock, computes a delta, and writes an adjustment. The POS projection then writes a decrement based on its own prior read or vice versa. Without a shared lock order covering count finalization, stock-level mutation, movement creation, and cost updates, the final quantity can be off by one sale and the replay window can miss the exact concurrent movement.

**Evidence:**
- Spec §4 says finalization should replay movements from each count timestamp to finalization time.
- Spec §8 says to use a "tenant-scoped advisory lock keyed by `(product_id, location_id)` around finalize replay + adjustment posting."
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:47-66` computes `delta = final_qty - theoretical_qty` from item fields, with no replay window today.
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:73-80` reads current stock before calling adjustment logic.
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:85-94` posts adjustments after that read.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:546-588` transitions a count to finalized and dispatches the completion event after commit; it does not lock affected product/location rows before changing status.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1009-1094` independently decrements stock and records movements during projection.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1073-1076` updates stock and creates the stock movement in the projection path.
- `apps/api/app/Modules/Inventory/Services/StockAdjustmentService.php:619-640` has its own lock/update path for manual adjustments, but the spec does not define how the new advisory lock composes with the existing product cost lock and stock row mutations.

### Count timestamps are not trustworthy enough for replay

**Failure scenario:** A POS or mobile device with a skewed or spoofed clock submits a count or sale event. Replay uses that device timestamp as a boundary. A sale that physically happened before the count can be ordered after it, or vice versa, causing the final adjustment to correct the wrong stock state. The current fiscal ingestion code detects clock anomalies but accepts and flags them; it does not make device time a trustworthy ordering source. Existing count submission APIs also do not capture a device-signed or server-normalized count timestamp.

**Evidence:**
- Spec §4 depends on each count's "counted_at = device instant, plus server_received_at."
- Spec §4 says: "API computes server_clock_skew from batch sync and records counted_at_server_estimate."
- Spec §4 says: "Replay uses server_estimate unless clock skew exceeds threshold; if exceeded require recount/manual review."
- `apps/api/app/Modules/Fiscal/Application/ClockAnomalyDetector.php:25-33` states clock checks are admissibility checks and audit flags, not fiscal proof.
- `apps/api/app/Modules/Fiscal/Application/ClockAnomalyDetector.php:63-68` defaults to a 24-hour maximum skew window.
- `apps/api/app/Modules/Fiscal/Application/ClockAnomalyDetector.php:83-107` detects drift/rollback but does not reject ordinary skew within the configured window.
- `apps/api/app/Modules/Fiscal/Application/OutboxIngestor.php:109-116` says clock anomalies are accepted and flagged.
- `apps/api/app/Modules/Fiscal/Application/OutboxIngestor.php:686-711` verifies clock status during ingestion but feeds the accepted event onward.
- `apps/api/app/Modules/Inventory/Requests/SubmitCountRequest.php:26-28` accepts only `quantity` and `notes`; no device timestamp or server-estimated count instant is accepted.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:302-361` submits a count from the request data and current user context, not from a clock-corrected device instant.
- `apps/api/app/Modules/Inventory/Models/InventoryCountingItem.php:213-222` stamps count submission with `now()`, not the device's physical counting instant.
- `apps/pos/src/lib/offline/receiptService.ts:291-293` derives `postedAtDate` from the local device clock.
- `apps/pos/src/lib/offline/receiptService.ts:413-426` writes `event_time_device` into the fiscal event from that local timestamp.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:768-785` validates second-precision UTC formatting, not trustworthiness of the local clock value.

### Onboarding opening is not an absolute set

**Failure scenario:** A tenant/location is in onboarding mode with negative or nonzero on-hand stock due to pre-live test sales, returns, transfers, or partial imports. The first live count finalizes quantity `Q`. The spec says to create an opening movement with `quantity = Q` instead of a count correction, but the current opening posting service is additive: it computes `quantityAfter = quantityBefore + line.quantity`. If on-hand is `-5` and the count says `10`, posting an opening quantity of `10` leaves stock at `5`, not `10`. If on-hand is `3`, it leaves `13`. That is a blocker for using "opening" as first-count normalization.

**Evidence:**
- Spec §5 says: "Onboarding mode first count writes Opening movement with counted qty/cost, not correction adjustment."
- Spec §6 says onboarding mode "Post Opening movement for first counted qty; do not post CountCorrection against theoretical zero."
- `apps/api/app/Modules/Inventory/Services/OpeningBalancePostingService.php:103-114` calculates `$quantityAfter = bcadd($quantityBefore, $line->quantity, InventoryScale::QUANTITY_SCALE)`.
- `apps/api/app/Modules/Inventory/Services/OpeningBalancePostingService.php:137-147` persists the additive result as the stock level.
- `apps/api/app/Modules/Inventory/Services/InventoryOpeningService.php:160-166` validates opening quantities as positive quantities, not absolute target stock levels.
- `apps/api/app/Modules/Inventory/Services/InventoryOpeningService.php:168-174` validates unit cost but does not define "set current stock to counted quantity" semantics.

## MAJOR

### Multi-counter normalization can hide basket-window disagreement

**Failure scenario:** Counter A scans the shelf before a cashier sells an item from the same basket; Counter B scans after the sale. Both counters submit physically valid counts for different instants. The spec normalizes each count to finalization time and compares the normalized values, so the two counts can appear to agree even though the counters did not count the same physical state. That weakens the existing blind-count agreement model and can push an item through reconciliation without surfacing that the count window was ambiguous.

**Evidence:**
- Spec §4 says: "Blind multi-counter: normalize each counter's count from its counted_at to finalize instant, then compare normalized quantities."
- Spec §5 says: "Multi-counter normalization preserves blind count policy."
- `apps/api/app/Modules/Inventory/Services/CountingReconciliationService.php:61-69` currently resolves double/triple counts by direct equality/threshold comparison between submitted blind counts.
- `apps/api/app/Modules/Inventory/Services/CountingReconciliationService.php:241-253` applies variance thresholds to the submitted counted quantity versus theoretical quantity.
- `apps/api/app/Modules/Inventory/Services/CountingReconciliationService.php:259-261` defines epsilon semantics for quantity agreement.
- `apps/api/app/Modules/Inventory/Models/InventoryCountingItem.php:228-235` uses `floatsEqual()` to determine whether counters agree.
- `apps/api/app/Modules/Inventory/Models/InventoryCountingItem.php:263-269` defines the current epsilon comparison around direct count values.

### Backfilled `occurred_at = created_at` is unsafe for late offline sales

**Failure scenario:** A POS device sells an item offline at 10:00, syncs at 18:00, and the projection creates a stock movement at 18:00. A count at 14:00 finalizes at 15:00. For pre-deploy or non-migrated rows with `occurred_at = created_at`, replay treats the sale as after the count/finalization even though it physically happened before the count. That can trigger a false correction or fail to correct the actual shelf quantity.

**Evidence:**
- Spec §4 says: "Migration adds occurred_at to stock_movements, backfilled to created_at."
- Spec §4 says replay depends on movements in `(T -> now]`.
- `apps/api/database/migrations/2025_11_30_110000_create_inventory_tables.php:32-50` creates `stock_movements` with timestamps and indexes on `created_at`, but no `occurred_at`.
- `apps/api/app/Modules/Inventory/Models/StockMovement.php:60-82` has no `occurred_at` in `$fillable`.
- `apps/api/app/Modules/Inventory/Models/StockMovement.php:87-103` has no `occurred_at` cast.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:195` reads the receipt event's device event time.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:922-935` applies stock movements during projection rather than at the original device event time.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:959-997` projects receipt lines into stock decrements.

### First-count detection ignores transfers and misclassifies returns

**Failure scenario:** A product/location receives stock through a transfer before its first count, or a customer return is projected as an inbound receipt before onboarding. The spec's first-count rule says "no prior Opening movement and no prior receipt/POS movement," which can miss transfer-in movements or treat a return as a receipt depending on reason/type mapping. The system may post an opening movement when stock already exists through transfer, or fail onboarding because a customer return happened before the first count.

**Evidence:**
- Spec §5 says first count detection is: "product+location has no prior Opening movement and no prior receipt/POS movement."
- `apps/api/app/Modules/Inventory/Services/StockAdjustmentService.php:301-374` implements transfers as stock mutations with source and destination movement rows.
- `apps/api/app/Modules/Inventory/Services/StockAdjustmentService.php:341-353` creates the source `TransferOut` movement.
- `apps/api/app/Modules/Inventory/Services/StockAdjustmentService.php:362-374` creates the destination `TransferIn` movement.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1097-1214` restocks customer returns through receipt-style stock movement creation.
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1203-1204` uses `MovementReason::POSReturn` for POS returns.
- `apps/api/app/Modules/Inventory/Enums/MovementReason.php:10-11` distinguishes goods receipt and customer return reasons.
- `apps/api/app/Modules/Inventory/Enums/MovementReason.php:31` has a separate `POSReturn` reason.

### Overlapping active counts can double-apply corrections

**Failure scenario:** Two supervisors create active counts over the same product/location through overlapping scopes, for example one product count and one category or full-inventory count. Both counts include the same item. If they finalize close together, each computes its own correction against theoretical/current quantities and posts an adjustment. There is no current overlap guard in count creation, and the spec only mentions a finalization advisory lock, not a rule preventing overlapping sessions or revalidating overlap before finalization.

**Evidence:**
- Spec §8 mentions a tenant-scoped advisory lock around finalize replay/adjustment posting, but does not define an overlap exclusion rule for active sessions.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:34-60` creates count sessions and items without checking for existing active counts over the same `(product, location)`.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:84-104` generates count items for the selected products/locations.
- `apps/api/app/Modules/Inventory/Models/InventoryCounting.php:175-181` defines active counts only by status, but no creation query uses this to exclude overlapping item scopes.
- `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:47-66` calculates a correction per finalized item, so two sessions can each post a correction for the same row.

### Zone scope requires more schema and exhaustive code changes than the spec admits

**Failure scenario:** The spec adds a `zone` counting scope and claims status checks remain untouched/backward-compatible. In the current backend and frontend, scope enums, request validation, labels, and item generation are all closed over the existing cases. Adding a new enum case without touching every match/switch/request/frontend union will fail validation, fail item generation, or silently omit products.

**Evidence:**
- Spec §3 says: "Scope: zone is a first-class scope, modeled as a new CountingScopeType::Zone."
- Spec §10 says: "Keep inventory_countings.status CHECK constraint untouched."
- `apps/api/app/Modules/Inventory/Enums/CountingScopeType.php:7-13` has only `full_inventory`, `location`, `category`, `brand`, `supplier`, and `products`.
- `apps/api/app/Modules/Inventory/Enums/CountingScopeType.php:20-28` has an exhaustive `match` for labels without `zone`.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:121-158` switches over existing scope types for item generation and has no zone branch.
- `apps/api/app/Modules/Inventory/Requests/CreateCountingRequest.php:83-115` validates required scope fields for existing scope types and has no zone path.
- `apps/api/database/migrations/2025_12_02_070000_create_inventory_countings_table.php:102-106` defines a database check constraint for `scope_type` with only the existing enum values.
- `apps/api/database/migrations/2025_12_02_070000_create_inventory_countings_table.php:114-123` separately defines the `status` check constraint; the spec's status-only compatibility statement misses the existing scope check that must change.
- `apps/web/src/features/inventory-counting/types.ts:6-12` defines a frontend `CountingScopeType` union with no zone and includes stale `warehouse`.
- `apps/web/src/features/inventory-counting/pages/CreateCountingPage.tsx:22-28` renders the available scope options with no zone option.

### The POS block/advisory "heartbeat" channel is not actually present

**Failure scenario:** A count is started in blocking mode. The web/API records that products are blocked, but an offline-first POS terminal does not receive the flag before it signs receipts. The spec assumes a config or heartbeat sync channel can carry block advisories, but the current terminal refresh channel exposes terminal config and company stock policy, not per-count or per-product/location block state. A search of the relevant API/POS sync code found no `config/heartbeat` endpoint or live count block payload.

**Evidence:**
- Spec §3 says: "Blocking mode: optional POS block for counted scope, with mandatory sync/heartbeat advisory."
- Spec §6 says: "When count starts: force POS sync/config heartbeat; terminals must stop creating sale lines for the blocked scope."
- `apps/api/app/Modules/POS/Resources/TerminalResource.php:29-33` serializes the company `pos_stock_policy`.
- `apps/api/app/Modules/POS/Resources/TerminalResource.php:81-83` contains the terminal payload fields and no count-block scope payload.
- `apps/pos/src/stores/terminalStore.ts:21-26` defines the local POS stock policy type.
- `apps/pos/src/stores/terminalStore.ts:55-64` defines terminal state with optional `pos_stock_policy`, not count-scope blocks.
- `apps/pos/src/stores/terminalStore.ts:948-968` implements terminal record refresh.
- `apps/pos/src/stores/terminalStore.ts:974` fetches `/pos/terminals/{id}` for that refresh.
- `apps/pos/src/stores/terminalStore.ts:987` compares and updates only `pos_stock_policy` for stock policy changes.
- `apps/pos/src/lib/sync/syncScheduler.ts:96-105` invokes terminal refresh as part of the sync scheduler.

### Location onboarding/policy override is not a local field today

**Failure scenario:** One location in a tenant is still onboarding while another is live. The spec says onboarding mode is tenant/location policy controlled, but the current schema has company-level POS stock policy and no location-level onboarding/counting policy field. Without a concrete schema and policy resolution rule, one location's onboarding behavior can leak into another location, or the implementation will have to infer onboarding from stock movements alone.

**Evidence:**
- Spec §5 says: "Mode is tenant/location policy controlled."
- Spec §6 says onboarding mode should apply to "new tenant/location."
- `apps/api/database/migrations/2025_11_30_105000_create_locations_table.php:23-54` creates locations with code, name, type, active flag, and address fields, but no onboarding/counting policy fields.
- `apps/api/database/migrations/2026_06_12_000000_add_pos_stock_policy_to_companies.php:14-17` adds `pos_stock_policy` to companies, not locations.
- `apps/api/app/Modules/POS/Resources/TerminalResource.php:29-33` reads stock policy from `$this->company?->pos_stock_policy`.
- `apps/api/app/Modules/Inventory/Services/InventoryService.php:74-82` detects active opening sessions at tenant level.
- `apps/api/app/Modules/Inventory/Services/InventoryService.php:94-101` detects downstream movements, but not location policy mode.

## MINOR

### The spec's no-floats claim conflicts with current web counting code

**Failure scenario:** The spec relies on exact decimal math for replay and variance, but the current web counting UI and reconciliation types use JavaScript numbers and `parseFloat()`. Even if the backend stores decimal strings, the UI can round or compare quantities differently from the precision contract before submission/manual override.

**Evidence:**
- Spec §10 says: "Quantities remain DECIMAL(20,4) strings in API/web."
- `docs/architecture/precision-contract.md` defines quantity scale as 4 and says JS/PHP float must not touch money or quantity.
- `apps/web/src/features/inventory-counting/types.ts:120-122` represents count quantities as numbers.
- `apps/web/src/features/inventory-counting/types.ts:133-140` represents reconciliation quantities as numbers.
- `apps/web/src/features/inventory-counting/types.ts:231-233` represents manual override quantity as a number.
- `apps/web/src/features/inventory-counting/components/ManualOverrideDialog.tsx:27-31` parses override input with `parseFloat()`.
- `apps/web/src/features/inventory-counting/components/ReconciliationTable.tsx:312-324` compares numeric count values to theoretical values in the UI.

### `CountingStatus::isActive()` excludes pending review, but blocking spec says active until finalized/cancelled

**Failure scenario:** A count in `pending_review` still represents an unresolved physical count and may need to keep POS blocking active. The spec says blocks are released only when count finalizes or cancels, but existing active-status semantics exclude `pending_review`. If block lifecycle code reuses `scopeActive()`/`isActive()` conventions, it may release the POS block during review.

**Evidence:**
- Spec §6 says: "When count finalizes/cancels: release block."
- `apps/api/app/Modules/Inventory/Models/InventoryCounting.php:175-181` scopes active counts to `draft`, `scheduled`, and `in_progress`, excluding `pending_review`.
- `apps/api/app/Modules/Inventory/Models/InventoryCounting.php:219-237` allows status transitions through `pending_review` before finalization.

### Auto-exit onboarding is ambiguous for multi-location `full_inventory`

**Failure scenario:** A tenant runs a `full_inventory` count that covers only stocked products currently present in positive stock, because existing item generation queries active stock levels with quantity greater than zero. New products or locations with zero/negative stock can remain effectively uninitialized, but the spec says a full-inventory/whole-location count finalization auto-exits onboarding. That can exit onboarding before every product/location has a valid opening baseline.

**Evidence:**
- Spec §6 says: "Auto-exit onboarding for a location after full_inventory/whole-location count finalizes."
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:121-158` resolves products for each existing scope.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:160` filters stock levels to `quantity > 0` when resolving products with stock.
- `apps/api/app/Modules/Inventory/Services/InventoryCountingService.php:84-104` creates items only for the resolved products/locations.

## NIT

### Rollout key `final_qty_as_of` is not enough by itself

**Failure scenario:** A migration or rollout adds `final_qty_as_of` but does not also persist normalized per-counter quantities, replay source ranges, skipped/reversed movement ids, and clock quality. Auditors and support staff will not be able to explain why a final adjustment was computed. The name also sounds like a final item timestamp, while the algorithm needs at least count instant, server-estimated count instant, finalization instant, and replay audit metadata.

**Evidence:**
- Spec §9 says rollout begins by adding `occurred_at` and `final_qty_as_of`.
- Spec §4 depends on `counted_at`, `server_received_at`, `counted_at_server_estimate`, movement replay windows, and reversal exclusion, which cannot be reconstructed from `final_qty_as_of` alone.

## Severity Count Summary

| Severity | Count |
| --- | ---: |
| BLOCKER | 5 |
| MAJOR | 7 |
| MINOR | 3 |
| NIT | 1 |

---

## Triage dispositions (main session, 2026-07-06)

Note: several "Spec §N says" quotes above are Codex paraphrases, not verbatim spec text (e.g. the cited "§8 advisory lock" section does not exist in the spec). The code evidence stands regardless; dispositions below are against the findings' substance.

| Finding | Disposition | Spec change |
|---|---|---|
| B1 wrong server rejection path | **ACCEPTED** — signed fiscal receipts can't be rejected; block enforced device-side pre-signing via TerminalResource/syncScheduler channel; late signed sales accepted+flagged+replay-corrected | §3 rewritten |
| B2 replay direction/reversal semantics | **ACCEPTED** — replay redefined as Σ(quantity_after − quantity_before), no type mapping | §4 |
| B3 finalize races projection | **ACCEPTED** — replay+post inside existing per-product advisory lock; ProductCostLock ordering noted | §4 |
| B4 count timestamp trust | **ACCEPTED (partially pre-existing)** — spec already had skew correction; now persists counted_at/server_received_at/counted_at_server_estimate, reuses ClockAnomalyDetector stance, flags-not-replays beyond threshold | §2 |
| B5 opening not absolute set | **PARTIAL MISREAD** — spec always posted the replay delta, not absolute Q; now explicit that opening is additive delta and OpeningBalancePostingService semantics must NOT be reused unchanged | §5 clarified |
| M1 normalization hides basket disagreement | **ACCEPTED** — basket flag per counter's own T; raw-disagree/normalized-agree keeps informational flag | §4 |
| M2 backfilled occurred_at unsafe | **ACCEPTED WITH REASONING** — safe because replay only runs for post-deploy counts; reasoning documented | §10 |
| M3 first-count rule misses transfers/returns | **ACCEPTED** — rule = no prior opening/receipt/transfer_in (supply-side baseline); POS sales/returns never block opening | §5 |
| M4 overlapping counts double-apply | **ACCEPTED** — activation overlap guard + finalize re-validation | §4 |
| M5 zone scope exhaustiveness + scope_type CHECK | **ACCEPTED** — scope_type CHECK migration + closed-enum touchpoint list | §7 |
| M6 heartbeat channel doesn't exist | **ACCEPTED (channel exists, name was wrong)** — concrete mechanism = TerminalResource payload + syncScheduler terminal refresh | §3 |
| M7 location onboarding fields missing today | **ALREADY IN SPEC** (new columns proposed); explicit resolution rule + resolved-policy in TerminalResource added | §5 |
| MIN1 web floats | **ACCEPTED** — touched counting UI migrates to string quantities | §6 |
| MIN2 pending_review block release | **ACCEPTED** — explicit block-status list incl. pending_review | §3 |
| MIN3 auto-exit vs qty>0 generation filter | **ACCEPTED** — onboarding/full counts include zero/negative-stock products; auto-exit conditioned on it | §5 |
| NIT replay audit metadata | **ACCEPTED** — replay_audit jsonb (window, delta, excluded reversals) + per-count server estimates | §7 |
