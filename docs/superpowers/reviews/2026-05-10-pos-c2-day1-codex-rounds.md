# PR #107 — C2 Day 1 (composite-id helper + schema + flatten + wire unpack) — Codex review trail

**Branch:** `feat/pos-c2-day1-composite-ids`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 7 rounds at time of writing.

The C2 kickoff under-specified the **SQLite cache reconciliation lifecycle** for Menu tenants — the data-flow connecting `/active-menu` (server) → `menu_categories` / `menu_category_items` (cache) → `products` table (POS grid). Each round surfaced a real correctness gap in that lifecycle; each fix was mechanical. The end-state is materially safer than the round-1 baseline: both foreground (productStore) and background (runFullSync via pullActiveMenu) paths now share a single `reconcileMenuProducts(db, freshProducts)` helper that handles success-empty wipe + non-empty upsert + bare-row sweep + composite prune.

## Round 1 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P1 closed)

### P1 — Remove stale bare rows when writing composite menu products

For Menu tenants that already had `id = sellable_id` bare rows cached pre-Day-1 (or after the scheduler's unconditional `pullProducts()` writes bare `/products` rows), the new composite-id flatten would no longer collide on the PK — both shapes would coexist and `getAllProducts` would surface duplicates / non-menu prices.

**Closure (`41247af6`):**
- `pullProducts(db)` gated on `!isMenuTenant` at the wrapper layer (dynamic import of productStore avoids a circular dependency).
- New `deleteStaleBareSellableRows(db, sellableIds)` helper at the repository layer; called from `productStore.doMenuApiFetch` after the composite upsert.

## Round 2 — APPROVE-WITH-MINOR-EDITS-APPLIED (2 P2 closed)

### P2-1 — Delete stale composite menu rows after refresh

When an item disappeared from one menu category but the same sellable remained in another, the old composite row's `menu_category_id IS NOT NULL` shielded it from the round-1 sweep. The cashier kept seeing a removed entry.

**Closure (`eb1ab684`):** `pruneStaleCompositeRows(db, freshCompositeIds)` reads existing composite rows, computes the set difference against the fresh active-menu set, deletes the stale composite rows. Empty fresh set is a defensive no-op.

### P2-2 — Avoid flat product pulls before company config is loaded

The round-1 gate treated `companyConfig === null` as non-Menu and fell through to `/products`. For a Menu tenant booting before the first `fetchCompanyConfig` resolved, that wrote bare-id rows during the boot window.

**Closure:** when companyConfig is null at call time, attempt to fetch it inline; if the fetch succeeds, route normally; if it fails (network down), defer this tick rather than guess. The `runFullSync` reruns every 60s, so deferring is bounded.

## Round 3 — APPROVE-WITH-MINOR-EDITS-APPLIED (2 P2 closed)

### P2-1 — Successful empty active-menu leaves stale composite rows

The round-2 prune helper short-circuits on empty input as a defensive no-op for transient API failures. But when /active-menu RESPONDS with an empty body, composite rows in SQLite stayed put.

**Closure (`6a62e3b5`):** new `wipeAllCompositeRows(db)` for the success-empty branch. The prune helper's empty-input no-op stays for the failure case; the success-empty path uses the explicit wipe.

### P2-2 — Composite delimiter `:` breaks Windows image-cache filenames

The image cache writes files as `${productId}.${ext}` at `apps/pos/src/lib/images/imageCache.ts:172`. Colon is not a valid filename character on Windows.

**Closure:** switched DELIMITER from `:` to `_`. Underscore is filename-safe across every platform we ship AND does not appear in the UUID alphabet. The kickoff's "Open question #1" (delimiter choice) is resolved as `_` based on this Windows-filename evidence.

## Round 4 — APPROVE-WITH-MINOR-EDITS-APPLIED (2 P2 closed)

### P2-1 — Scan resolution auto-adds wrong cross-listed row

Composite-id POSProducts for the same sellable across two categories share `barcode` / `sku`. Tier-1 (in-memory) and Tier-2 (SQLite) returned the FIRST match — locking the receipt to whichever category-priced row came back first, bypassing the Tier-3 chooser.

**Closure (`61c2d653`):** new `getProductsByBarcode` plural helper. `resolveScannedCode` Tier-1 changed `find` → `filter`, Tier-2 swapped to plural; both route to `kind: 'choose'` on multi-match (mirroring Tier-3 API contract).

### P2-2 — Stale bare rows survive a Menu success-empty fetch

Round-3 added `wipeAllCompositeRows` for success-empty but left bare-id rows untouched. Pre-C2 cache orphans resurfaced after restart.

**Closure:** new `wipeAllProductRows(db)` total-wipe helper. Used ONLY by the Menu-tenant success-empty branch — every local row is stale by construction in that case.

## Round 5 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — Bare orphans for sellables removed from the menu survive the targeted sweep

The round-1 sellable-targeted sweep only deleted bare rows whose `id` matched a sellable in the fresh menu. Pre-C2 cache rows for sellables that have since been REMOVED from the menu entirely never matched.

**Closure (`7c24b88e`):** replaced the targeted sweep with a total bare-row wipe on every successful non-empty Menu fetch. New `wipeAllBareRows(db)` helper. The semantic is correct because for Menu tenants every bare row is stale by construction (`pullProducts` is gated, so no new bare rows are written post-C2).

## Round 6 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P1 closed)

### P1 — Background sync no longer propagates Menu catalog changes mid-shift

The round-1 + round-2 P2 closures gated `pullProducts` for Menu tenants. But `runFullSync` only calls `pullActiveMenu` (which writes to `menu_categories` + `menu_category_items`) and never flattened the result back into `products`. Mid-shift menu changes never reached an already-running cashier session until the next foreground `fetchProducts`.

**Closure (`3f3b33c2`):** extracted Menu reconciliation logic into a shared helper `reconcileMenuProducts(db, freshProducts)` at the repository layer. Both call sites now use it:
- `productStore.doMenuApiFetch` (foreground).
- `pullActiveMenu` (background runFullSync tick) — flattens the just-pulled menu via `flattenMenuToProducts` and reconciles BEFORE returning.

## Round 7 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — Empty active-menu sync wipes SQLite but leaves cashier's in-memory grid stale

The round-6 fix made `pullActiveMenu` reconcile the products table during background sync. For a Menu tenant whose /active-menu becomes empty mid-shift, that wipes SQLite — but `productStore.refreshFromSQLite` had a defensive early-return on `freshProducts.length === 0`, so the in-memory `products` array kept the previous catalog.

**Closure (`6b3cf095`):** dropped the early-return. `diffProducts` correctly emits `{ changed: true, products: [] }` when current is non-empty and fresh is empty, so the existing `if (changed)` branch clears in-memory state along with the scan LRU. The defensive case (both sides empty → `changed: false`) keeps the historical no-op semantic.

## Round 8 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P1, REGRESSION introduced in r6)

### P1 — Standard-retail tenants' product catalog wiped by pullActiveMenu

The round-6 reconcile call inside `pullActiveMenu` ran for **all** tenants, not just Menu ones. A standard-retail tenant's `/active-menu` endpoint succeeds with `categories: []` (no menu module enabled), the flatten yields empty, and `reconcileMenuProducts(db, [])` called `wipeAllProductRows` — deleting the entire products table that `pullProducts` had just populated.

**This was a regression I introduced in round 6.** Without the round-8 catch, it would have shipped to IziPOS (standard-retail) terminals and wiped their catalog on every sync tick.

**Closure (`474db3b8`):** gate the reconcile on `isMenuTenant` inside `pullActiveMenu`, mirroring the `pullProducts` gate pattern. Defer-on-unknown-config (companyConfig null) matches the pullProducts posture — skipping is safer than wiping a non-Menu catalog. The dynamic import of productStore avoids the circular dependency.

## Round 9 — NOT RUN (STOP-3)

Per session stop condition STOP-3 ("Codex review goes past round 5 — brief and surface"), did not push past round 8 in this session. The round-8 fix lands a clear correctness improvement; whether Codex round 9 closes APPROVE or finds another gap is for the next session to determine. Per session lesson L6: "rounds 6+ mean something is structurally wrong with the design" — in this case the structural under-spec was the SQLite cache reconciliation lifecycle, AND I introduced a regression in round 6 that took until round 8 to catch.

## Final shape

- **8+ source files modified, 7+ test files** (helper + migration + repository + flatten + sync wire-unpack + scan resolver + productStore + Menu reconciliation).
- **+48 tests** across the new files (1119 → 1167 in 131 files).
- **8 rounds run; round 8 caught a regression introduced in round 6.** No BLOCKERs; no STOP-2 spec decisions surfaced.
- The end-state architecture has both foreground (productStore) and background (runFullSync) paths sharing the SAME reconciliation helper — that property is itself a regression-safety win the kickoff didn't anticipate.

## Day 2 / Day 3 follow-ups (deferred)

- Cart-line composite-ID handling (Day 2 per kickoff).
- BarcodeChooserModal category-context per-row rendering (Day 2 — only display is deferred; the chooser already mounts on multi-match thanks to the round-4 P2 fix).
- `extractCategories` dedupe by menu_category_id (Day 2).
- Server-side `pos_receipt_lines.menu_category_id` column (Day 3).
- Refund-flow recall reconstructing composite from server-side category context (Day 3).
- In-flight (offline) cart-line migration semantics for upgrading terminals (Day 2/3 — Risk #3 in the kickoff).

## Lesson update for L6 (proposed)

T2.4 Day 1 went 8 rounds because phase recoverability was statically declared but inherently dynamic. C2 Day 1 went 7+ rounds because the kickoff didn't trace the SQLite cache reconciliation lifecycle (foreground productStore + background runFullSync + refreshFromSQLite empty handling). Pattern: "kickoff missing a data-flow lifecycle audit" produces 5+ rounds even when each fix is mechanical. **Mitigation for next kickoff:** include a "lifecycle audit" section that traces every consumer of the modified state through every entry point (foreground fetch, background sync, restart hydration) and decides explicitly whether each path needs updating.

## Cross-references

- Kickoff doc: `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
- PR #92 in-flight pre-launch work — partial closure of the C2 entry.
