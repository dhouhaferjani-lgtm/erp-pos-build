# Spec: Recipe-driven 86-ing + ingredient depletion gating

**Date:** 2026-06-18
**Status:** Draft (for Codex adversarial review)
**Owner decision basis:** "86-ing is the right way when inventory tracking is activated. Costing should work without inventory tracking." Composite/recipe definition + costing are `module:CompositeItems`; stock-aware behaviour requires `module:Inventory`.

---

## 1. Problem

A composite item (recipe-backed dish/combo/BOM) should be **86-ed** — blocked or warned at the point of sale — when its ingredients are insufficient, **but only once the tenant has activated inventory tracking** (`module:Inventory`). Before that, F&B/BOM tenants operate made-to-order: define + cost recipes, sell freely, no stock enforcement.

Two things must be true:
1. **When `Inventory` is OFF:** no sell-time stock enforcement and **no ingredient depletion** for anyone — a CompositeItems-only tenant is not tracking stock and must not have ingredient stock driven negative.
2. **When `Inventory` is ON:** selling a recipe-backed composite must respect `PosStockPolicy` (`Block`/`Warn`/`Off`) against **ingredient availability** (auto-86), and deplete ingredients on the completed sale.

## 2. Current state (grounded; `apps/api`, `apps/pos`)

- **Ingredient depletion EXISTS** — `ReceiptCreationService::deductCompositeItemStock()` (`POS/Application/Services/ReceiptCreationService.php:1188`) recursively explodes `activeRecipe->lines` and calls `decrementStock()` per leaf, creating `StockMovement` (Issue / POSSale) records.
- **`PosStockPolicy` enforcement EXISTS for simple products** — `decrementStock()` (`ReceiptCreationService.php:915-938`): `Block` throws (prevents checkout), `Warn`/`Off` log + proceed (negative allowed). Offline mirror in `apps/pos/src/lib/stock/stockGate.ts:62-96`.
- **Composites are EXEMPT from the availability gate** — `apps/pos/.../availability.ts isStockExempt()` returns exempt for `sellableType !== 'product'`; the server has no composite availability gate. `CompositeItemAvailabilityService::checkAvailability()` (`Catalog/Application/Services/CompositeItemAvailabilityService.php:26`) exists but is **display-only** (`GET /composite-items/{id}/availability`), never gating checkout.
- **Dual stock-decrement paths:**
  - **Path A (synchronous draft):** `ReceiptCreationService` — enforces policy, explodes composites, decrements.
  - **Path B (event-sourced):** `PosCoreReceiptProjection::apply()` (`...Projections/PosCoreReceiptProjection.php:319`) re-derives decrement from canonical SALE_RECEIPT lines — **no policy enforcement** (always proceeds, logs if under-stocked). Documented variant double-decrement gap at `:856-865`.
- **No `Inventory`-module gate** anywhere on the sell path — decrement/explosion run unconditionally.
- **Offline POS has NO recipe/component data** — local `location_stock` table (migration v50) is finished-good aggregate only; composites are pre-exempt offline.
- **Batch/FEFO** — batch-tracked products consume via `FEFOInventoryService::consumeBatchesAtomically()` (`ReceiptCreationService.php:1388`), hard-FEFO regardless of policy.

## 3. Goals / Non-goals

**Goals**
- G1. Gate **all** sell-time stock operations (simple decrement, composite ingredient explosion+depletion, availability enforcement) on `hasModule('Inventory')`. Inventory OFF ⇒ behave as today's `Off` policy AND skip ingredient depletion.
- G2. Enforce `PosStockPolicy` against **composite ingredient availability** (auto-86) at the same gates simple products use: add-to-cart (POS) + checkout (server), when Inventory is ON.
- G3. Keep the canonical event-sourced path (Path B) correct: enforcement is a **pre-commit gate**, not a projection concern; the projection records movements (idempotent, may reflect negative under race).
- G4. Define offline (Tauri) behaviour explicitly.
- G5. TDD throughout; no regression to simple-product gating or existing depletion.

**Non-goals**
- Manual 86-ing UI (staff toggling an item unavailable) — separate feature.
- Reservation/ATP for in-flight offline sales beyond what exists.
- Changing recipe costing (already decoupled from Inventory).
- Web POS being a real sell path (it's demo-only per project decision).

## 4. Design

### 4.1 Inventory-module gate (G1) — the spine
Resolve `inventoryActive = company.hasModule('Inventory')` once per receipt. Thread an **effective policy**:
- `inventoryActive == false` ⇒ effective `PosStockPolicy::Off` **and** skip `deductCompositeItemStock()` / `decrementStock()` entirely (no movements written). Rationale: a non-Inventory tenant is not tracking stock; writing negative ingredient stock is wrong.
- `inventoryActive == true` ⇒ use `company.pos_stock_policy` and run depletion as today.

Insertion points (server): `ReceiptCreationService::createReceipt()` near `:168` (replace the bare `$stockPolicy = $company->pos_stock_policy`); guard `decrementStock()` + `deductCompositeItemStock()` call sites (`:658-696`). Projection path `PosCoreReceiptProjection::decrementStockForLines()` (`:319`) must apply the **same** `inventoryActive` gate (read module state for the receipt's tenant) so a non-Inventory tenant never gets movements from the async path either.

### 4.2 Composite availability gate / 86-ing (G2)
Add a **pre-decrement availability check** for composite lines when `inventoryActive && policy != Off`:
- Compute producible quantity for the composite at the **receipt's location** via the recipe explosion (reuse `CompositeItemAvailabilityService` logic, but location-scoped and **cart-aware**: subtract quantities already consumed by earlier lines / same-ingredient composites in this cart).
- Apply policy:
  - `Block`: if `requestedQty > producibleQty` ⇒ reject (throw, same shape as simple-product block) naming the **limiting ingredient**.
  - `Warn`: allow, attach a warning (limiting ingredient) to the response; proceed (ingredients may go negative).
  - `Off`: skip (sell-through).
- This runs at **add-to-cart** (offline/online UX) and again at **server checkout** (authoritative), mirroring simple products.
- **Shared-ingredient correctness:** the check must account for multiple composite lines (and simple lines) drawing the same ingredient in one cart — compute against a running per-ingredient remaining map, not per-line in isolation.

`CompositeItemAvailabilityService` is refactored so the sell path and the display endpoint share one explosion/availability core (single source of truth), with the sell path passing `locationId` + a cart-consumption map.

### 4.3 Dual-path reconciliation (G3)
- **Enforcement stays pre-commit** (ReceiptCreationService draft + POS gate). The fiscal projection (Path B) is post-commit and authoritative for *recording* — it must NOT block (can't reject an already-sealed receipt). It applies the same `inventoryActive` gate for *whether to write movements*, but never the availability policy.
- Document the asymmetry in code (Path A enforces, Path B records) per the repo's data-flow-annotation convention.
- Confirm we don't double-deplete: today Path A (draft) and Path B (projection) both decrement. **Open question OQ-1** — verify which path is authoritative for stock in the current fiscal-engine design (draft vs projection) and ensure composites deplete exactly once.

### 4.4 Offline (Tauri) behaviour (G4) — the hard part
The device has no recipe/ingredient data and only finished-good `location_stock`. Two options:

- **Option A (recommended for v1): made-to-order offline, reconcile on sync.** Offline, composites remain **sell-through** (current behaviour) even when Inventory is ON — the device cannot compute ingredient availability. On reconnect, the server applies depletion (4.1/4.2) and, under `Block`/`Warn`, **flags oversells** for the operator (advisory banner) rather than reversing the sale. This honours offline-first (never block a sale on missing data) and keeps the device thin.
- **Option B (later): ship recipe + ingredient stock to the device.** Add `recipe_lines` + ingredient `location_stock` to the local DB and a local explosion in `availability.ts` so offline 86-ing works. Larger: device schema migration, sync payload growth, multi-level explosion offline, cart-aware ingredient math on-device.

v1 = Option A. Spec records Option B as the follow-on, gated on demand. The device's existing `is_physical`/`sellableType` exemption stays; the only change is the **server** now enforces/depletes for composites when Inventory is ON, and offline oversells surface in the existing reconcile/advisory channel.

### 4.5 Multi-location, batch, partial availability
- **Location:** availability + depletion are scoped to the **terminal's location** (matches simple-product behaviour and the existing `decrementStock` location scoping).
- **Batch-tracked ingredients:** when an exploded ingredient is batch-tracked, depletion continues through `FEFOInventoryService` (hard-FEFO); the availability check must use batch-available quantity for that ingredient. Confirm FEFO + composite explosion compose (OQ-2).
- **Partial availability:** no partial fulfilment of a single composite line — a dish is producible or not at the requested qty (integer producible count vs requested qty).

## 5. Data model / API

- **No new tables expected** for v1 (depletion + stock_levels already exist). Possibly a response field on the cart/checkout error for the **limiting ingredient** (name + shortfall) for the 86-ing UX.
- The display endpoint `GET /composite-items/{id}/availability` stays; it and the sell gate share the refactored availability core.
- No device schema change in v1 (Option A).

## 6. TDD test plan

Backend (`tests/Feature/POS` + `tests/Feature/Catalog`):
- T1. Inventory OFF (CompositeItems-only tenant): selling a composite writes **no** ingredient `StockMovement`; no negative stock; succeeds.
- T2. Inventory ON, `Block`: composite with one depleted ingredient ⇒ checkout **rejected**, error names the limiting ingredient; ingredient stock unchanged.
- T3. Inventory ON, `Block`, sufficient stock ⇒ succeeds; each leaf ingredient decremented once; `StockMovement` rows correct (scale-4).
- T4. Inventory ON, `Warn`: oversell **allowed**, warning carries limiting ingredient; ingredients go negative.
- T5. Inventory ON, `Off`: sells through, ingredients deplete (may go negative), no block.
- T6. **Shared-ingredient cart:** two composite lines sharing one ingredient under `Block` ⇒ blocked when their **combined** demand exceeds stock (not evaluated per-line).
- T7. Nested composite (kit-of-kits) explodes to leaves; availability + depletion correct.
- T8. Batch-tracked ingredient: availability uses batch-available; depletion via FEFO; allocation snapshot written.
- T9. Projection path (Path B): non-Inventory tenant ⇒ projection writes no movements; Inventory tenant ⇒ exactly-once depletion (no double with Path A).
- T10. Regression: simple-product Block/Warn/Off unchanged; existing `ReceiptStockDecrementScalingTest` green.

(Note: several of these touch fiscal/projection + `lockForUpdate` → likely **PG-only**; mark them for the PG test lane, not SQLite.)

## 7. Risks / open questions

- **OQ-1 (authoritative path):** Is stock authoritative on the synchronous draft (Path A) or the fiscal projection (Path B)? The spec assumes enforcement pre-commit + recording post-commit; must confirm composites deplete **exactly once** and which path "owns" stock. This is the highest-risk unknown.
- **OQ-2:** FEFO (batch) + recipe explosion interaction for batch-tracked ingredients — availability and FEFO consumption must agree.
- **OQ-3:** Performance — recipe explosion + per-ingredient `lockForUpdate` for a multi-composite cart could lock many `stock_levels` rows; define lock ordering to avoid deadlocks (reuse the WAC canonical lock-order pattern if applicable).
- **OQ-4:** What is `pos_stock_policy` for the new BOM verticals (fashion/retail/parapharmacy via CompositeItems extra)? They default `Block` (non-Menu) — confirm that's desired once they enable Inventory + composites.
- **OQ-5:** Offline oversell reconciliation — does an advisory-only flag suffice, or is a stronger control needed for `Block` tenants who go offline?
- **OQ-6:** Availability semantics with `reserved` quantity — should producible use `quantity` or `quantity - reserved`?

## 8. Phasing

- **Phase 1 (server, the core):** Inventory-module gate (4.1) on both decrement paths + skip depletion when OFF. Tests T1, T9, T10.
- **Phase 2 (composite 86-ing):** availability gate + limiting-ingredient error, cart-aware, shared the availability core (4.2). Tests T2–T7.
- **Phase 3 (batch + polish):** FEFO interaction (4.5), limiting-ingredient UX field, docs. Tests T8.
- **Phase 4 (offline Option B, deferred):** device recipe/stock + offline 86-ing — separate spec when demanded.

Resolve OQ-1 **before** Phase 1 (it determines where the gate lives).
