# PR #109 — C2 Day 2 (cart + grid + chooser composite handling) — Codex review trail

**Branch:** `feat/pos-c2-day2-cart-grid-chooser`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 5 rounds, APPROVE.

Day 1 closed the wire/SQLite layer for menu-tenant composite ids. This PR closes the three downstream consumer surfaces. The kickoff anticipated mostly-mechanical work, but Codex surfaced a four-finding chain on the category-name canonicalization invariant — each finding closed a real correctness gap in the rename / migration windows. L8 (cross-screen ownership) from the T2.4 Day 2 trail proved out again: every finding was a boundary co-ownership question between Menu-flavored rows and a legacy consumer (ProductGrid filter, diffProducts, SQLite hydration).

## Round 1 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — Renamed Menu-category products inaccessible from the surviving filter tab

The initial `extractCategories` change deduped by `menu_category_id` (first-seen name wins) but didn't normalize the products themselves. ProductGrid filters by `p.category === selectedCategory` string; with the deduped list showing only 'Drinks' (canonical) and some rows still labeled 'Beverages' (the freshly-flattened new name for the same `menu_category_id`), those rows were unreachable until reconciliation dropped them.

**Closure (`933837c5`):** new `canonicalizeMenuCatalog(products) → { products, categories }` helper that rewrites each row's `category` to the first-seen name for its `menu_category_id` (via fresh objects — no input mutation) AND returns the deduped list. Every set() site that writes both fields now uses the helper. Non-Menu rows bypass normalization. `extractCategories` becomes a thin wrapper for the legacy SQLite-hydration path that only needs the list.

## Round 2 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — SQLite hydration path stored raw products

The initial SQLite hydration (`fetchProducts` Step 1) used `extractCategories(cachedProducts)` but wrote the raw cachedProducts into state. During the cache-only window (before any successful API refresh, or when the refresh fails), ProductGrid filtering still hid the non-canonical-label rows behind the canonical tab.

**Closure (`33c152e9`):** hydration path now also uses `canonicalizeMenuCatalog`. The obsolete `extractCategories` wrapper was dropped (no remaining call sites).

## Round 3 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — `diffProducts` didn't compare `menu_category_id` / `sellable_id`

The v30 migration backfills `menu_category_id` / `sellable_id` onto existing rows. `diffProducts` only compared visible fields (name, sku, barcode, sale_price, stock_quantity, category, image_url, tax_rate, sellableType, position, modifier_groups). A backfill that touched only the new id fields would result in `productEquals` returning true → `merged` kept the stale cached object → downstream `canonicalizeMenuCatalog` never saw the freshly populated ids → chooser-row category label and ID-based dedupe stayed disabled until another visible field flipped.

**Closure (`f3f5d8fa`):** `menu_category_id` + `sellable_id` added to `COMPARE_FIELDS`. The preserve-reference optimization still fires for genuinely-equal rows. Tests +2 in productDiff.

## Round 4 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — Canonicalization AFTER diff churned the scan cache and store on every refresh tick

Symmetric of r1: canonicalization happened on `merged`, not on `freshProducts`. In-memory `current` is canonical (post-r1 invariant); diffing raw-fresh against canonical-current marked every Menu row with a non-canonical label as changed on every scheduler tick, churning `clearScanCache()` + `set()` for no real change.

**Closure (`98cb43d4`):** canonicalize `freshProducts` BEFORE `diffProducts` in all four sites (Menu API fetch, standard pull legacy, foreground pull, refreshFromSQLite). The merged products now contain stable canonical references; diff is a true no-op when the underlying data is unchanged.

## Round 5 — APPROVE

> The changed product diffing, category canonicalization, and chooser display logic appear consistent with the existing data flow, and the targeted tests pass. I did not identify a discrete regression introduced by this patch.

No findings. PR ready for merge.

## Final shape

- **5 commits** (base + 4 fix commits).
- **3 source files modified** (productStore.ts, productDiff.ts, BarcodeChooserModal.tsx); 1 test file added; 2 test files extended.
- **+11 tests** (+2 cartStore composite-id dedup, +2 BarcodeChooserModal category disambiguator, +3 productStore canonicalization, +2 productDiff field coverage, +2 productStore migration/backfill).
- Final pos test count: 1193/1193 in 132 files.
- typecheck 0 errors; lint baseline preserved (0 errors / 41 warnings).

## Lessons (proposed)

- **L9 (new — canonicalize-before-state-machine-input):** Any normalization step that the in-memory state machine assumes about its own data MUST be applied at every state-machine ingress, BEFORE comparison/diff/merge logic. The L1 cross-tenant pattern's discipline of enumerating every consumer applies symmetrically to normalization invariants — every set() write site is also an ingress. r1 + r2 + r4 were three different ingress sites missing the same invariant.
- **L8 confirmation (cross-screen ownership):** ProductGrid filter consumes `p.category` by string equality. Day 2 introduced canonicalization upstream; ProductGrid's filter contract didn't change. Without the normalization at every write site, the contract broke silently — exactly the boundary co-ownership pattern.
- **L7 confirmation:** Five rounds (four findings + APPROVE) for a "small" Day 2. Three of the four findings (r1, r2, r4) were the same invariant at different ingress sites. The kickoff's "Day 2 ~1 day" estimate was accurate for the SOURCE diff but didn't account for the boundary discipline.

## Day 3 follow-ups (still deferred)

- Server-side `pos_receipt_lines.menu_category_id` column + migration (Day 3 per kickoff).
- Refund-flow recall reconstructing composite from server-side category context.
- In-flight (offline) cart-line migration semantics for upgrading terminals (kickoff Risk #3).

## Cross-references

- Kickoff doc: `docs/superpowers/plans/2026-05-10-pos-c2-menu-tenant-desync-kickoff.md`
- Day 1 review trail: `docs/superpowers/reviews/2026-05-10-pos-c2-day1-codex-rounds.md`
- T2.4 Day 2 review trail (L8 origin): `docs/superpowers/reviews/2026-05-11-pos-t2.4-day2-codex-rounds.md`
- PR #92 in-flight pre-launch work — closes the C2 Day 2 entry; Day 3 still pending.
