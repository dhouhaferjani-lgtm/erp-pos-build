# C2 — Menu-tenant Catalog Desync (Kickoff)

**Status:** Kickoff. Pre-launch blocker (Otospex co-launches with parapharmacy, decision locked 2026-05-10).
**Fix shape:** Shape 2 — composite IDs `(sellable_id, menu_category_id)`. Picked over Shape 1 (derive from menu tables — biggest refactor) and Shape 3 (two-tier hybrid — drift-prone).
**Estimated effort:** 2–3 days.
**Coordination risk:** Medium. Touches productStore, cart, ProductGrid, BarcodeChooserModal (T2.1 Step B), refund-flow lookup, receipt printing, tanstack query keys. Zero overlap with T2.4 (bootstrap state machine) and T2.7 (training-aware receipts).

## Source documents

- Scope brief: `docs/superpowers/plans/2026-05-09-pos-c2-menu-tenant-desync-scope-brief.md` (the failure surface + three fix shapes).
- T2.1 kickoff §"Out of T2.1 scope": flagged the Menu-mode collapse but deferred.
- Audit C2 finding: `apps/pos/src/stores/productStore.ts:65-90` (the verbatim-pre-T2.1 Menu branch with the inline `// Menu-tenant catalog desync (audit finding C2) is a separate session.` anchor at line 70).

## Why Shape 2 over the alternatives

Shape 1 (derive from `menu_categories + menu_category_items`) is the cleanest semantics but rebuilds the productStore data model from scratch — every consumer of `productStore.products` (~10 surfaces) needs verification. The audit risk grows with the diff size.

Shape 3 (two-tier read) is the smallest cart change but introduces a parallel read path. SQLite cache invalidation must hold both paths in sync — a future drift surface that's hard to test exhaustively. Likely to bite under sync-tick edge cases.

Shape 2 (composite IDs) preserves the existing `products` table as the single read path, keeps cache invalidation simple, and pushes the change to two well-defined surfaces: the flatten function and any code that handles `POSProduct.id`. The cart-line "dual-id form" cost is real but localized — a parsing helper at the boundary contains it.

## Composite ID format

Decision: **string composite** of the form `"<sellable_id>:<menu_category_id>"`, with both UUIDs preserved as separate fields on the `POSProduct` record:

```ts
type POSProduct = {
  id: string;              // Composite for Menu tenants: "{sellable_id}:{menu_category_id}".
                           // Standard retail tenants: bare sellable_id (unchanged).
  sellable_id: string;     // NEW (Menu only): the underlying sellable's UUID, parsed from id.
  menu_category_id: string | null;  // NEW (Menu only): the category UUID, parsed from id.
  // ...rest unchanged
};
```

For standard-retail (non-Menu) tenants, `id === sellable_id` and `menu_category_id === null` — backwards compatible. SQLite primary key stays on `id` (string).

A small helper handles the parse/build:

```ts
// apps/pos/src/lib/menu/compositeId.ts
export function buildMenuCompositeId(sellableId: string, categoryId: string): string {
  return `${sellableId}:${categoryId}`;
}

export function parseMenuCompositeId(id: string): { sellableId: string; categoryId: string | null } {
  const parts = id.split(':');
  if (parts.length !== 2) return { sellableId: id, categoryId: null };
  return { sellableId: parts[0]!, categoryId: parts[1]! };
}
```

Why colon-delimited string and not a tuple object? SQLite primary key + TanStack Query cache key + cart-line keying all expect a string-like primitive. A composite string lets every cache stay flat. The parse helper is cheap and the boundary is small.

## Surface (estimated 2–3 days)

### Frontend changes (apps/pos)

1. **`apps/pos/src/api/productApi.ts:89` — `flattenMenuToProducts`**
   - Iterate `(category, item)` pairs as today, but emit one `POSProduct` per pair.
   - Set `id = buildMenuCompositeId(item.sellable_id, category.id)`.
   - Populate `sellable_id = item.sellable_id` and `menu_category_id = category.id`.
   - Preserve `category: category.name` so the existing display-side category column still works.
   - Preserve `display_order` from `item.display_order` (today already collapses to last write — fix is automatic with the composite key).

2. **`apps/pos/src/api/productApi.ts:81-85` — SQLite fallback in `fetchActiveMenu`**
   - The fallback already reads from `menu_categories + menu_category_items` (preserved hierarchical schema). Update the rebuilt-from-SQLite shape to populate `sellable_id` + `menu_category_id` so the in-memory object matches the foreground-fetched one.

3. **`apps/pos/src/lib/db/repositories/productRepository.ts` — `upsertProducts` / `getAllProducts`**
   - Add `sellable_id` and `menu_category_id` columns to the `products` SQLite schema (NULL-able for non-Menu tenants).
   - Migration: `ALTER TABLE products ADD COLUMN sellable_id TEXT; ALTER TABLE products ADD COLUMN menu_category_id TEXT;` — backfill with `id` for `sellable_id`, NULL for `menu_category_id` on existing installs.
   - `upsertProducts` writes both new columns from the `POSProduct`.
   - `getAllProducts` returns the same shape including the new fields.

4. **`apps/pos/src/stores/productStore.ts` — `fetchProducts` + `refreshFromSQLite`**
   - Mostly free with the schema change. The collapse-on-upsert pathology at productStore.ts:163 (`upsert to flat products table by id`) goes away because each `(sellable_id, category_id)` pair has a distinct composite id.
   - Update `extractCategories` (if it filters on `id`) to dedupe by `menu_category_id` for Menu tenants.

5. **`apps/pos/src/components/molecules/BarcodeChooserModal/BarcodeChooserModal.tsx` (T2.1 Step B)**
   - When `resolveScannedCode` returns multiple matches that share `sellable_id` but differ in `menu_category_id`, the chooser must show category context per row so the cashier picks the right (item, category) tuple. Update the row template to render `{product.category}` next to the SKU/barcode line.
   - The recent-scan LRU at `scanResolutionCache.ts` (PR #98) already keys on the chosen `POSProduct` — its `id` field is now the composite, so cached picks restore the (item, category) tuple correctly. **No LRU change needed.**

6. **Cart line creation (`apps/pos/src/stores/cartStore.ts` and surrounding)**
   - Cart line `id` (or `productId` / `sellable_id` depending on the field name) takes the composite. When the cart submits to the API, the boundary unpacks: `parseMenuCompositeId(line.id).sellableId` is the `product_id` the API expects. The category context is preserved on the cart line for display (receipt printing, refund flow).
   - **Audit every consumer of `cartLine.id`** — receipt printing, refund-flow line lookup, hold/recall persistence, transaction sync payload. Most should treat `id` as opaque and need no change; the boundary unpack happens once at the API call.

7. **Refund flow line lookup (`apps/pos/src/lib/refundFlow/`)**
   - When a refund recalls a previous receipt, the line's `product_id` (server-side) is a sellable_id. The local product lookup must reconstruct the composite if the tenant is Menu-mode — i.e. `menu_category_id` rejoins from the receipt's category context (already on the receipt line per spec). Verify the receipt-line schema carries `menu_category_id` server-side; if not, this becomes the only data shape change at the wire boundary.

8. **TanStack Query keys**
   - Audit `apps/pos/src/api/productApi.ts` query key shapes. Anywhere a key includes `productId`, replace with `productCompositeId` for Menu tenants. Keys that key on `sellable_id` for cross-category aggregation (e.g., "all variants of this item") stay as-is.

9. **Receipt printing**
   - The category column on receipts pulls from `cartLine.category`, which is already the human-readable `category.name`. No change.

### Backend changes (apps/api) — minimal

Verify the receipt-line schema carries the category context (`menu_category_id` per line). If absent today, this is a small spec change:
- Add `menu_category_id` to the receipt-line DTO at sync ingest (optional UUID field).
- Persist on the `receipt_items` table.
- Surface on receipt-line read endpoints used by refund flow.

This may already be partially in place — Menu-mode tenants have presumably been recording category context somewhere since the menu feature shipped. Verify before scoping the backend slice; if it's a 1-line additive field, fold into the same PR. If it's structural, split as a separate PR shipped first.

## Tests

### Unit / repository

- `productRepository.test.ts` — `upsertProducts` writes a (sellable_id, menu_category_id) pair as two distinct rows when shared sellable spans two categories; `getAllProducts` returns both.
- `compositeId.test.ts` — round-trip parse/build; defensive on malformed input (returns `{ sellableId: id, categoryId: null }` for legacy IDs).

### Store

- `productStore.fetchProducts` (Menu branch) — fixture with one item in two categories → in-memory `products` array contains two POSProduct entries with distinct composite ids.
- `productStore.refreshFromSQLite` — same fixture round-tripped through SQLite returns the same multi-row shape.

### Integration

- `BarcodeChooserModal` — when scan resolves to multiple products sharing `sellable_id` but differing in `menu_category_id`, the modal renders per-row category context.
- Cart-add path — clicking a `(sellable_id, category_a)` product adds a line keyed on the composite; clicking the same sellable in `category_b` adds a separate line. `paymentStore.totals` reflects both.

### E2E (Menu tenant)

- New seeder fixture: `MenuTenantMultiCategoryFixture` with a "Coca" sellable cross-listed in "Drinks" and "Combo Specials". Verify cashier flow (scan → add → pay → print receipt) preserves the category context end-to-end.

### Regression

- Standard-retail (non-Menu) tenant: assert `products[i].id === products[i].sellable_id` and `menu_category_id === null` for all rows. The composite path must NOT activate for non-Menu tenants.

## Risks

1. **Cart-line ID semantics change is the highest-risk surface.** Every `cartLine.id` consumer must be audited. **Mitigation:** the parse boundary is one helper file; production audit is mechanical (grep for `cartLine.id` and `line.id` in cart/refund/hold/recall/sync paths).

2. **Sync payload shape.** The transaction-sync payload sends `product_id`. If we keep the composite as `cartLine.id` and unpack to `sellable_id` only at the API boundary, the wire shape stays unchanged. If we want the server to know category context (for refund flow's category-restoration), we add `menu_category_id` server-side. **Decision:** add the server-side field; refund flow's category-restoration depends on it.

3. **Backwards compat for in-flight (offline) carts.** A POS terminal upgrading from pre-C2 has cart lines persisted with bare `sellable_id`. On boot post-upgrade, the productStore returns composite IDs and the existing cart lines no longer match. **Mitigation:** during the cart-line migration step, treat any cart line with `id === sellable_id` and Menu-mode tenant as "needs category re-resolution" — render with a category-picker modal or fall back to the first category's pricing. **Recommendation:** dump and warn (rare; user can re-add the line). Document this in the migration release notes.

4. **Receipt-line history.** Existing offline-receipt rows have bare `sellable_id` in their `product_id` column. Refund flow against these rows can't restore category context. **Mitigation:** refund-flow falls back to "no category" rendering for legacy lines — graceful degradation, no data loss, matches the same edge case as #3.

5. **Codex review surface.** This is a multi-day, multi-surface change. Expect 3–4 review rounds. **Mitigation:** structure as 3 sub-PRs (schema + flatten; cart + grid; refund + receipt) so each round reviews a smaller diff. Bundle if Codex suggests.

## Codex review cadence (expected)

3–4 rounds, possibly more if a sub-PR strategy exposes coordination edge cases:

- **Round 1**: likely flags the cart-line dual-id boundary — does the parse happen at the right layer? Should the cart store carry `sellable_id` + `menu_category_id` as separate fields rather than a composite string?
- **Round 2**: SQLite migration + backwards compat for in-flight carts (Risk #3).
- **Round 3**: receipt-line shape + refund flow boundary (Risk #4).
- **Round 4**: APPROVE.

## Recommended execution order

1. **Day 1 — Schema + flatten + helper**
   - `compositeId.ts` + tests.
   - Migration for `products` SQLite table.
   - `flattenMenuToProducts` rewrite + tests.
   - `productStore.fetchProducts` Menu branch verification (multi-category fixture). Codex review.

2. **Day 2 — Cart + grid + chooser**
   - Cart line composite-ID handling + sync-payload boundary unpack.
   - `BarcodeChooserModal` category-context rendering.
   - `extractCategories` dedupe. Codex review.

3. **Day 3 — Receipt + refund + e2e**
   - Server-side receipt-line `menu_category_id` field (if not already there).
   - Refund-flow restoration of category context.
   - E2E seeder fixture + integration test. Codex review iterations.

Each day's slice is an independent PR to dev. A backend-only landing of the schema change is a win regardless of whether the frontend slice ships in the same release.

## Out of scope

- **Multi-category modifier_groups overrides.** The scope brief lists this as a lost-data case; verify whether any tenant uses per-category modifiers today (probably not) and defer the fix as a separate workstream if needed.
- **Per-category `is_available` (one category may stock-out while another keeps it active).** Same as above — defer pending field signal.
- **Folding C2 + T2.7 fixtures.** Both touch Menu-mode tenants but the workstreams are independent.
- **Refund flow for Phase 2 (automotive / Otospex).** Refund flow Phase 2 hasn't shipped yet; this kickoff covers the Phase 1 (standard-retail / IziPOS) refund path that already exists.

## Open questions / spec decisions

1. **Composite ID delimiter.** Colon (`:`) is the recommendation. UUIDs don't contain colons, so unambiguous. **Confirm before Day-1 execution.**

2. **Server-side `receipt_items.menu_category_id`.** Does the column exist? If not, who owns the platform-side schema change? **Recommendation:** apps/api is owned by the same team — fold into the same PR cluster.

3. **In-flight cart migration.** Drop-with-warning vs prompt-for-category-on-resume. **Recommendation:** drop-with-warning (rare path; no production data lost since cart lines are pre-payment).

4. **TanStack Query key shape audit.** Some POS query keys may key on `productId` for prefetch. Should we standardize on `compositeId` everywhere (uniform but verbose) or `sellableId` for cross-category lookups (semantic but inconsistent)? **Recommendation:** use the composite where the consumer needs category context; use sellable_id where the consumer aggregates across categories. Document at the helper.

5. **Telemetry hook.** Should the migration emit a beacon when a Menu tenant's first post-C2 sync hits the new flatten path? **Recommendation:** no — the existing sync metrics suffice, and a dedicated beacon would be noise post-launch.

## Cross-references

- Scope brief: `docs/superpowers/plans/2026-05-09-pos-c2-menu-tenant-desync-scope-brief.md`
- T2.1 Step B (chooser modal): `apps/pos/src/components/molecules/BarcodeChooserModal/BarcodeChooserModal.tsx`
- Recent-scan LRU (PR #98): `apps/pos/src/lib/scanResolutionCache.ts`
- T2.7 sister kickoff: `docs/superpowers/plans/2026-05-10-pos-t2.7-offline-training-receipts-kickoff.md`
- T2.4 sister kickoff: `docs/superpowers/plans/2026-05-10-pos-t2.4-bootstrap-state-machine-kickoff.md`
- PR #92 release vehicle: in-flight pre-launch work entry.
