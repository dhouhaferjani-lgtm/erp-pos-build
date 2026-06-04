# Branch / Per-Establishment — Doc 09c: Per-Branch Inventory Valuation & Event-Sourcing Readiness

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec`
**Type:** Codebase deep-dive. RESEARCH/PLANNING ONLY — no code change.
**Companion to:** Doc 08 (program scope), Doc 06 (accounting).

**Owner directive (2026-06-04):** WAC unit cost stays **company-wide** (not per-location). Add per-**branch** inventory **valuation + reporting** (branch quantity × shared company WAC). "Make sure event sourcing is properly set up."

---

## 1. WAC is company-wide (confirmed) — and cost changes are only weakly audited (gap)

- `WeightedAverageCostService` recomputes one product-level WAC and writes `products.cost_price` (single `decimal(12,2)`, no location dimension). A purchase at any branch shifts the company-wide cost. `recordPurchase` `:87-130`, `recordSale` reads company cost `:231-255`, `recordReturn` recomputes `:342-378`. Cost column: `2025_12_02_064541_add_cost_and_margin_fields_to_products_table.php:15-19`. **No per-location cost column anywhere.** ✅ matches the design intent.
- **Audit gap:** `ProductCostPriceUpdated` is emitted **only when the sale price also changed** (`WeightedAverageCostService.php:149-170,397-418`) and is **not subscribed** in `DomainEventSubscriber` (`:941-1000`) → no durable audit-log row; only websocket broadcast. **No cost-history table.** Cost history is only weakly reconstructable from `stock_movements.avg_cost_before/after` — and only on paths that populate them (§2).
- **CORRECTION (2026-06-04, Codex review):** an earlier draft said `recordCostAdjustment` is not present — **that was wrong.** `WeightedAverageCostService::recordCostAdjustment()` **exists** (`WeightedAverageCostService.php:646-731`) and is called by stock transfers (`StockTransferService.php:506-521`). It is the canonical company-WAC adjustment entry point (reuses the landed-cost capitalization pattern); per-branch valuation work must route through it, not around it.

## 2. `stock_movements` has the right shape but the high-volume path leaves cost NULL

`stock_movements` is a per-event ledger with `location_id`, `quantity`, `quantity_before/after`, `unit_cost`, `total_cost`, `avg_cost_before/after`, `movement_type`, `reason`, `reference_*`, `is_historical` (model `StockMovement.php:55-94`; cols across `2025_11_30_110000_create_inventory_tables.php:32-50`, `2025_12_02_065035_add_cost_tracking_to_stock_movements_table.php`, `2025_12_24_133827_extend_stock_movements_table.php`). Indexed `(tenant_id, location_id, created_at)` → **queryable per-location over time.**

**But cost columns are populated inconsistently — POS is NOT the only null-cost writer (corrected per Codex review 2026-06-04):**
- `WeightedAverageCostService` — full cost fields ✅ (`:103-120,242-259,352-369`).
- **`POS/ReceiptCreationService::issueStock` (the high-volume sale path) — writes `location_id` + qty but NO `unit_cost`/`total_cost`/`avg_cost_*`** ❌ (`:900-918`). POS sales bypass `recordSale` and write the movement directly with null cost.
- **`StockAdjustmentService` — creates movements without cost fields** ❌ (`:611-622`; reads `unit_cost ?? '0.00'` defensively at `:88,461,571`).
- **`ReceiptVoidService` — void returns omit costs** ❌ (`:160-175`).
- **`ReceiptReturnService` — returns omit costs** ❌ (`:1019-1032`).
- **`InventoryOpeningService` — creates movement rows without cost fields** ❌ (`:279-315`) even though it later updates product cost.

⇒ The P2 task "populate cost on every `stock_movements` writer" must enumerate **all** of these, not just the POS sale path.

**Reconstruction verdict:** per-location **quantity** over time is fully reconstructable; per-location **cost-at-time** is **not** reliably reconstructable today because the dominant POS path leaves cost null.

## 3. Per-branch valuation feasibility

- **Current (now): ✅ derivable today.** `stock_levels` has `(location_id, quantity)`, unique `(tenant_id, product_id, location_id)` (`2025_11_30_110000_...:16-30`), with aggregation indexes (`2025_12_22_200001_add_stock_aggregation_indexes.php`). Branch valuation now = `Σ (stock_levels.qty@location × products.cost_price)` — exactly the owner's "branch qty × shared WAC". **This is a reporting query, buildable with no schema change.**
- **Historical / point-in-time per-branch valuation: ❌ not faithfully derivable.** Missing: (1) no inventory valuation/closing-stock snapshot table (the `snapshots` table is Spatie's aggregate store, not inventory); (2) per-movement cost null on the POS path; (3) no durable WAC-change history to know the company cost at a past date.

## 4. GL / COGS location linkage — schema ready, posting drops it

- `journal_entries.location_id` exists (nullable, FK, indexed) — `2025_12_27_150002_...:23-37` — but **`createCOGSEntry` never sets it** (`GeneralLedgerService.php:834-843`), and `JournalLine` has no location column at all. `PostCOGSOnInvoice` aggregates one company-level COGS entry from `product_id/quantity/unit_cost`, discarding line/location (`:115-154`), cost = company `product->cost_price` (`:133`). **Confirmed: COGS postings carry no location_id.** This is the seam where per-branch valuation connects to per-branch P&L (Doc 08 §1).

## 5. Event-sourcing infrastructure to build on

- **Spatie laravel-event-sourcing is installed** (`DomainEvent extends ShouldBeStored` `Shared/Domain/Events/DomainEvent.php:16`; `stored_events`/`snapshots` tables; `AggregateRoot` base; `config/event-sourcing.php`) — **but inventory is not plugged in** (`dispatch_events_from_aggregate_roots=false`, no projectors/reactors registered, inventory uses plain Laravel events, not an aggregate root).
- **`audit_events` is the de-facto durable event log** (written via `Compliance\Services\AuditService` from `DomainEventSubscriber`). `StockMovementRecorded` already has `getAuditData()` (`:55-70`) but isn't subscribed — **two lines in `subscribe()` would give a durable, per-location, cost-bearing inventory trail.**
- **`fiscal_events` is the strongest event-sourcing pattern** (append-only, DB immutability triggers, projections, quarantine) — the reference for "proper event sourcing" already in production for POS receipts.
- **GL + document hash chains** exist; **no outbox table.**

### What "proper event sourcing for per-branch valuation" requires (build order)
1. **Make `stock_movements` the complete authoritative ledger** — populate `unit_cost` + `avg_cost_after` + `location_id` on **every** writer (close the POS-sale null-cost gap at `ReceiptCreationService.php:894`). *Single biggest enabler* — makes movements replayable into historical per-branch valuation.
2. **Subscribe `StockMovementRecorded` (+ `ProductCostPriceUpdated`)** in `DomainEventSubscriber` → durable cost/movement audit trail.
3. **Thread `location_id` into COGS** (`createCOGSEntry` / `PostCOGSOnInvoice`) → wires per-branch valuation to per-branch P&L (schema already supports it).
4. **Add a periodic inventory-valuation snapshot** (month-end branch closing-stock × WAC) — new projection table or Spatie projector — for cheap point-in-time valuation + reconstruction anchoring.

---

## 6. Recommendation for the program (slots into Doc 08)
- **MVP (low risk, no schema change):** current per-branch valuation report = `stock_levels.qty@branch × products.cost_price`. Ship in the reporting phase (Doc 08 P3).
- **Proper event sourcing (the owner's ask):** fold items 1–3 above into the ledger-dimension phase (Doc 08 P1/P2) since they share the `location_id`-threading plumbing; item 4 (snapshots) is a follow-on for historical valuation.
- **Keep:** company-wide WAC unit cost (unchanged). **Do not** add a per-location cost column — it would collide with the single `products.cost_price` design and the company-wide costing decision (memory `project_inventory_costing`).

## 7. Uncertainty flags
- Cost-field handling in `ReceiptVoidService` / `ReceiptReturnService` / `InventoryOpeningService` not read line-by-line; POS-sale null-cost (`ReceiptCreationService:894`) and the WAC-service full-cost paths are confirmed.
- `stored_events` emptiness for inventory inferred (config + no aggregate root), not runtime-verified.

**End of Doc 09c.**
