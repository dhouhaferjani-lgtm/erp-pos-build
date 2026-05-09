# C2 Menu-tenant Catalog Desync — Scope Brief

**Status:** Investigation complete; STOP-4 fired (scope is days, not hours). Implementation deferred to a dedicated multi-session workstream.

**Source of finding:** PR #94 deferral; T2.1 kickoff prompt §"Out of T2.1 scope" notes Menu-mode tenants (Otospex) write the flattened menu to the generic `products` table, then `refreshFromSQLite` reads back from the same flat table — collapsing multi-category sellables.

**Why pre-launch blocker:** if Otospex ships alongside the parapharmacy, Menu-mode catalog truthfulness is broken at the cashier. Coordination decision still owed by the orchestrator (Otospex go/no-go for the current launch).

---

## Concrete failure surface

`productStore.fetchProducts` Menu branch (`apps/pos/src/stores/productStore.ts:142-183`):

1. Calls `fetchActiveMenu()` (productApi.ts:72) → returns `{ categories: [{ id, name, position, items: [{ sellable_id, sellable_type, ... }] }] }`.
2. Calls `flattenMenuToProducts(menu)` (productApi.ts:89-113) — for each `(category, item)` pair, pushes ONE `POSProduct` whose `id = item.sellable_id`. The category context is collapsed to a single `category: category.name` string.
3. Calls `upsertProducts(db, freshProducts)` writing to the FLAT `products` table. Primary key is the product's `id` — so an item that appears in multiple categories upserts only the LAST category's row.
4. SQLite read-back via `getAllProducts(db)` (or `refreshFromSQLite`) produces the post-collapse view; the in-memory store reflects last-write-wins.

**What is lost:**
- Multi-category presence — an item in both "Drinks" and "Combo Specials" appears only once with one category.
- Per-category `display_order` — only the last upsert's order survives.
- Per-category `modifier_groups` overrides if any tenant ever uses them.
- Category-scoped `is_available` (one category may stock-out while another keeps it active — collapsed).

**Already-correct path (offline fallback):** `fetchActiveMenu`'s SQLite fallback (productApi.ts:81-85) reads from `menu_categories + menu_category_items` (preserved hierarchical schema). So the offline-cached menu is RIGHT; the foreground refresh PATH is what corrupts it.

---

## Why this is days, not hours

The fix space affects every consumer of `productStore.products`:

| Consumer | Touched? | How |
|---|---|---|
| `ProductGrid` rendering | Yes | Category filter UI sources from `extractCategories(products)`; needs to handle multi-category items. |
| Cart line creation | Yes | `id` field today is `sellable_id`; multi-category context (e.g., a "Combo Special" Coca vs. an à-la-carte Coca) may need carrying through. |
| `BarcodeChooserModal` (T2.1 Step B) | Yes | Multi-match resolution may now legitimately return the same sellable from multiple categories. |
| Receipt printing | Yes | Category column on receipts (where shown). |
| Refund/return flows | Yes | Category-scoped sellable lookup. |
| TanStack query cache keys | Maybe | Depends on whether keys include category. |

**Three fix shapes worth evaluating** (none chosen yet — needs a dedicated kickoff):

1. **Derive `productStore.products` directly from `menu_categories + menu_category_items` for Menu tenants** — bypasses the `products` table entirely. Cleanest semantics, biggest refactor.
2. **Composite IDs `(sellable_id, menu_category_id)` for Menu tenants** — `flattenMenuToProducts` produces one row per (category, item) pair. Smallest data model change but cart code must handle the dual-id form.
3. **Two-tier read: products-flat for grids, menu_category_items for category-scoped reads.** — keeps the products table working, adds a parallel read path. Hybrid.

**Test infrastructure gaps:**
- `apps/pos/src/lib/db/repositories/__tests__/menuRepository.test.ts` covers only basic CRUD.
- No multi-category fixture exists.
- No e2e Menu tenant integration test exists.
- Backend menu seeders (apps/api side) need a multi-category cross-listing example.

**Estimated effort once kickoff lands:**
- Day 1 — write failing tests: multi-category fixture + assertions on `productStore.products` post-fetch + cart line creation.
- Day 2 — implement chosen fix shape; iterate on cart / ProductGrid / refund-flow consumers.
- Day 3 — Codex adversarial review iterations + manual smoke against an Otospex-like menu fixture.

---

## Recommended next step (for orchestrator)

1. Decide the Otospex launch coupling — if parapharmacy ships first, C2 can wait; if Otospex co-launches, C2 must land.
2. Pick the fix shape (1 / 2 / 3 above) with the menu domain owner.
3. Open a dedicated multi-session workstream (T2.7?) with its own kickoff prompt, plan doc, and PR plan.

Until those decisions are made, this brief stops here. Other in-flight pre-launch items (T1.4, BarcodeChooserModal focus trap, scan-resolver LRU, T2.4, T2.5, T2.6, TODO triage) can land independently of C2.
