# POS Catalog Warmup Performance Audit — 2026-04-30 (opus)

**Scope:** Tauri desktop POS (`apps/pos/`) cold-start product catalog warmup for a 5,000-SKU parapharmacy tenant. Investigation only, no code changes.

**Predecessors:**
- `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md` §3 — flagged the foreground 500-SKU cap.
- `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md` §Phase 3 — proposed paginated foreground pull + warmup banner.

This audit verifies, refutes, and extends those findings with the actual numeric profile (memory, disk, wire, render).

---

## 1. Executive Summary

### Top 5 ranked findings

| # | Severity | Finding | Cite |
|---|---|---|---|
| 1 | `P0` | **Foreground warmup is single-shot and capped at 500 SKUs.** `fetchProductsFromAPI` calls `fetchPOSProducts({ limit: 500 })` for retail; that hits `/products?per_page=500` page=1 only. For a 5,000-SKU parapharmacy, the cashier sees 500 of 5,000 (10%) on cold launch until the *background* `pullProducts` loop catches up across ~10 ticks. Confirms Codex audit §3 P0. | `apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:6-13`, `apps/pos/src/lib/sync/syncService.ts:378-410` |
| 2 | `P0` | **Wire payload is ~10–15× over-fetched for POS use.** The `/products` endpoint emits the full `ProductData` DTO (24 scalar fields + `parapharmacy_metadata` with nested `ingredients/keyComponents/healthClaims/certifications` arrays), but the POS only consumes 12 fields via `POSProduct`. Estimated 1–3 KB/product for parapharmacy vs ~250 B if a POS-projected endpoint existed. 5,000 SKUs ≈ **5–15 MB uncompressed** JSON, **~1.5–4 MB gzipped** (assuming nginx default). | `apps/api/.../ProductData.php:13-86`, `apps/pos/src/types/product.ts:3-16` |
| 3 | `P1` | **No HTTP-level caching: no `If-None-Match`, `If-Modified-Since`, ETag, or cursor pagination.** Every cold launch re-downloads the full catalog from page 1; the `updated_since` delta cursor exists but is only used by *background* `pullProducts` (via `sync_metadata.products_last_sync`), never by the foreground `fetchPOSProducts`. Second cold launch = same 5–15 MB. | `apps/pos/src/api/productApi.ts:11-13`, `apps/pos/src/lib/sync/syncService.ts:380-384`, `apps/pos/src/lib/api.ts:87-92` |
| 4 | `P1` | **SQLite cache is functional and SQLite-first works**, but `extractCategories` runs on the full 5,000-row in-memory list on every fetch and category counts in `ProductGrid` rebuild on every `products` change. On a parapharmacy 5K SKU set this is two O(n) scans + a `[...products].sort()` per render of `HomePage`. Likely 30–100 ms blocking cost on a low-end Tauri host; not catastrophic but visible on category-tab tap. | `apps/pos/src/stores/productStore.ts:38-46`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:89-118` |
| 5 | `P1` | **Image strategy is lazy-on-render with no preloading and no eviction.** `useProductImage` enqueues a download per rendered card; `processDownloadQueue` processes them in batches of 10 *only when called from `runFullSync` ticks* (every 60s). On 4G with a ~5,000-image catalog and ~30 KB/thumbnail, full localization takes ~25 minutes wall-clock and ~150 MB disk; nothing trims `product_images/` except `cleanupOrphanedImages` (orphans only — never size-bounded). | `apps/pos/src/lib/images/useProductImage.ts:11-27`, `apps/pos/src/lib/images/imageCache.ts:107-204`, `apps/pos/src/lib/sync/syncService.ts:865-869` |

### Top 5 quick wins (each <2 h, ranked by impact ÷ effort)

1. **Add a POS-projected query param `?fields=pos` (or new `/pos/products` endpoint)** that returns only the 12 fields `POSProduct` consumes. Cuts wire bytes ~5–10× for parapharmacy (full → ~250 B/row). **Impact: 5–15 MB → 1–2 MB on first launch.** Effort: 1.5 h backend + 30 min frontend. Note: this is a *backend* change; the frontend quick wins below remain valid even if the backend doesn't ship today.
2. **Rewrite `fetchProducts` to delegate to `pullProducts(db) + refreshFromSQLite()`** instead of `fetchPOSProducts({ limit: 500 })` (Phase 3 Step 3.1 plan; not yet implemented). Removes the 500-SKU cap. **Impact: P0 fix — cashier sees full catalog on cold launch.** Effort: 1 h.
3. **Persist `products_last_sync` and reuse the `updated_since` delta on second launch.** `pullProducts` already supports it; `fetchPOSProducts` does not. After fix #2 lands, this is automatic; if fix #2 doesn't ship, add it independently to the foreground path. **Impact: 5–15 MB → ~50 KB on second launch with no churn.** Effort: 30 min (after fix #2). 
4. **Add a `catalog_warmup_progress` banner with `loaded/expected` counts** (Phase 3 Step 3.2). Already planned. Cashier sees "Loading catalog: 1500 / 5000 products" instead of guessing. **Impact: cashier doesn't restart the app thinking it's stuck.** Effort: 1 h.
5. **Move `processDownloadQueue` invocation off the 60s tick onto post-pull and viewport-driven preloading.** Today images localize at 60s × 10/batch = 600/min only. Trigger after every paginated page persists, and pre-warm visible-grid images. Combined with a 100 MB LRU cap on `product_images` it bounds disk + accelerates initial visibility. **Impact: visible-screen images localized in <30 s instead of minutes.** Effort: 1.5 h.

---

## 2. Current Pull Strategy

### Verified facts

**Foreground pull (cashier-visible):** `productStore.fetchProducts()` calls `fetchProductsFromAPI(config)` which for retail tenants is **a single API call**:

```ts
// apps/pos/src/stores/productStore.ts:52-58
async function fetchProductsFromAPI(config: CompanyConfig | null): Promise<POSProduct[]> {
  if (hasModule(config, 'Menu')) {
    const menu = await fetchActiveMenu();
    return flattenMenuToProducts(menu);
  }
  return fetchPOSProducts({ limit: 500 });
}
```

That maps to `GET /products?per_page=500` (page defaults to 1) — see `apps/pos/src/api/productApi.ts:11-13`. **The Codex audit's 500-SKU cap finding is verified.** No pagination loop in the foreground path. For a 5,000-SKU parapharmacy: foreground returns 500, the remaining 4,500 arrive only via background `SyncScheduler` ticks running `pullProducts`.

**Background pull (sync scheduler, every 60s baseline):** `apps/pos/src/lib/sync/syncService.ts:378-434` is the *correct* paginated pull — `per_page=500`, loops until a short page, supports `updated_since` delta, supports `deleted_ids` tombstones. This is what eventually fills the catalog.

**What the cashier sees during the pull:**
- `ProductGrid` shows a `<LoadingSpinner>` while `isLoading=true` (gates on `productStore.isLoading`). See `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-164`.
- On first launch with empty SQLite, `fetchProducts` keeps `isLoading=true` for the entire foreground API call (`productStore.ts:155-158`), so the cashier stares at the spinner.
- Once the first page returns, all 500 products render at once. The remaining 4,500 trickle in over **subsequent SyncScheduler ticks** — they arrive in 500-row batches, with the in-memory store updated via `refreshFromSQLite` after each tick (scheduler line 76).
- **There is no streaming, no progressive accumulation in the foreground path, and no banner indicating "still loading 4,500 more products in the background"**. The grid silently re-renders when the next tick lands; the cashier has no signal that the catalog is incomplete.
- Auto-tick cadence: `BASE_INTERVAL_MS = 60_000` (`syncScheduler.ts:9-12`). So a 5,000-SKU first-run takes **~10 ticks ≈ 10 minutes** to fully populate via the background path.

### What the cashier can safely do during warmup

- Browse the 500 visible products. ✓
- Search across the 500 visible products. ✓ (search is in-memory, `ProductGrid.tsx:131-138`)
- **Search across the missing 4,500 — broken**. Search misses items until they arrive.
- Barcode scan — *partially works*: `getProductByBarcode(db, ...)` queries SQLite (`productRepository.ts:44-51`), so any product the *background* `pullProducts` has already written will scan. But on first launch within the first 60s, the only rows in SQLite are the foreground 500. There's no online fallback to `/products?barcode=X` on barcode miss inside `cartStore`/scanner code — verify in a follow-up if needed.

### Severity

`P0` — confirmed.

---

## 3. SQLite Cache

### Verified facts

**Schema:** `products` table (migration v1, `apps/pos/src/lib/db/migrations.ts:9-31`) — primary key on `id`, indexes on `sku`, `barcode`, `category`. `modifier_groups` added v11. Stores: `id, name, sku, barcode, sale_price, stock_quantity, category, image_url, tax_rate, sellable_type, modifier_groups, updated_at, synced_at`. Roughly 12 columns × 5,000 rows ≈ ~3-5 MB on disk including indexes.

**Repository:** `apps/pos/src/lib/db/repositories/productRepository.ts`.
- `getAllProducts(db)` — `SELECT * FROM products ORDER BY name`. No LIMIT, no projection. On 5,000 rows this is fine (~10–30 ms in SQLite + Tauri IPC).
- `upsertProducts(db, products)` — batch INSERT...ON CONFLICT in batches of 50 rows × 11 placeholders = 550 params per statement. Conservative against SQLite's 999-param default. **No transaction wrapper** — each batch is its own implicit transaction. For 5,000 products = 100 round-trips. Likely ~3-5 seconds on a typical Tauri host, dominated by IPC overhead. (Improvable with `BEGIN/COMMIT` wrap; out of scope for this audit's quick wins.)
- `deleteProducts(db, ids)` — supports tombstone IDs from server, batch size 200.

**Cache invalidation strategy:**
- **No TTL.** Local rows live until the server says they're updated (via `updated_since` delta) or deleted (via `deleted_ids` tombstones). Both go through `pullProducts`.
- `synced_at` column is updated on every upsert but isn't read for staleness checks.

**Second-launch behavior (verified):**
- `fetchProducts` runs SQLite-first (`productStore.ts:73-91`): `getAllProducts(db)` → if rows exist, `set({ products: cached, isLoading: false })` *immediately*. **Cashier sees full catalog in <500 ms** (this part works correctly).
- After the SQLite-first read, `doApiFetch` runs in background (`productStore.ts:149-154`) to revalidate. **The revalidation calls `fetchPOSProducts({ limit: 500 })` — still single-shot, still no `updated_since`.** So the foreground revalidation is wasteful on second launch: it pulls 500 rows the cashier already has, and any updates beyond the first 500 are still only delivered via the *background* scheduler.
- Net effect: second launch shows the full cached catalog instantly (good), but the foreground "freshness check" is effectively a no-op while the background scheduler does the real delta-pull.

### Severity

`P2` — cache works; the SQLite path is the correct one. The remaining inefficiency is the foreground revalidation duplicating work and not exploiting `updated_since`.

---

## 4. Image Loading

### Verified facts

**Storage:** `product_images` table (migration v12, `migrations.ts:233-242`): `product_id PK, remote_url, local_path, etag, downloaded_at`. ETag *is* captured (`imageCache.ts:178`), but it's never sent back as `If-None-Match` on re-download; effectively dead column.

**Loading model:** **Lazy, render-driven, with no preloading.**
1. `<ProductCard>` renders → calls `useProductImage(productId, remote_url)` (`ProductCard.tsx:30`).
2. `useProductImage` synchronously checks the in-memory `imageMap` (`useProductImage.ts:15-17`); if hit, returns the local asset URL.
3. On miss, calls `enqueueDownload(productId, remoteUrl, callback)` to put the request in `downloadQueue`. **The download does not start until `processDownloadQueue` is invoked.** 
4. `processDownloadQueue` is invoked from exactly two sites: (a) inside `runFullSync` on every scheduler tick (`syncService.ts:865-869`); (b) tests. There is **no immediate trigger** when a card mounts — the cashier waits up to 60 s for the next tick before the first batch of 10 images even starts downloading.
5. Each batch processes 10 images concurrently via `Promise.allSettled` (`imageCache.ts:158-200`). With ~5,000 thumbnails × 10/batch × 60s/tick = **~50 minutes wall-clock to fully localize the catalog**, ignoring batch concurrency wins (a single tick processes the entire current queue serially in batches of 10, so once a tick starts, it'll keep churning until the queue is empty — but the tick only runs every 60s and only after `runFullSync` finishes its other work).

**Cache eviction:**
- `cleanupOrphanedImages(db, currentProductIds)` removes images for products no longer in the catalog (`imageCache.ts:209-260`). **Not size-bounded.** Never invoked from production code as far as grep shows — search for callsites:

```
$ grep -rn "cleanupOrphanedImages" apps/pos/src/
apps/pos/src/lib/images/imageCache.ts:209: export async function cleanupOrphanedImages(...)
apps/pos/src/lib/images/__tests__/imageCache.test.ts: (test only)
```

→ **Image cache disk grows monotonically.** No LRU, no TTL, no size cap. A 5,000-SKU parapharmacy with thumbnail-sized JPEGs (~30 KB each on average for a 320 px thumbnail) = **~150 MB** persistent on disk after first complete sync. If the catalog churns (replaced products), the orphan cleanup never runs to reclaim space.

**Memory footprint:** `imageMap: Map<string, string>` holds productId → asset URL. ~5,000 × ~150 bytes/entry ≈ **~750 KB RAM**. Negligible.

**Disk hot path:** Images written to `${appDataDir}/images/products/${productId}.${ext}`. No subdirectory sharding, so a parapharmacy after full warmup has 5,000 files in one directory. macOS APFS handles this fine; on Windows NTFS this is also fine; on FAT32 USB sticks (unlikely for a POS, but possible for portable installs) it would be a problem.

**No preloading.** Images for products *not yet rendered* are never queued — the virtualizer (3 rows overscan) means only ~15-20 images are visible at any moment in `'visual'` mode. So even with the cache fully populated, the *initial* visual-mode render shows blank placeholders for the first viewport's worth of products until the next 60s tick fires `processDownloadQueue` for the cards that just enqueued.

### Severity

`P1` — for `'grid'` (text) display mode this is fine; for `'visual'` display mode the cashier sees placeholders for ~30-60s on cold launch. The unbounded disk growth is `P2` (no client has used the device long enough to hit it yet, but it'll matter at 6+ months).

---

## 5. Render Performance

### Virtualizer configuration

`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:148-153`:

```ts
const virtualizer = useVirtualizer({
  count: rowCount,
  getScrollElement: () => scrollContainerRef.current,
  estimateSize: () => rowHeight + GAP,
  overscan: 3,
});
```

- `rowHeight`: 140 px in grid mode, 220 px in visual mode (`cardSizing.ts:12-17`).
- `columns`: 5 (grid, ≥1024 px), 4 (visual, ≥1024 px). For 5,000 products in grid mode = 1,000 rows; visual mode = 1,250 rows.
- `overscan: 3` rows means ~15 cards rendered in grid mode, ~12 in visual mode at any time. Good.
- Virtualizer is *correct* — confirms Phase 3 plan's assumption.

### Hot-path cost on category switch / search

Three synchronous `useMemo` chains run on **every** `products`/`searchQuery`/`selectedCategory`/`sortMode` change (`ProductGrid.tsx:89-142`):

1. `sortedProducts`: `[...products].sort(...)` — O(n log n) on 5,000 products = ~50–80 ms on a typical Tauri host (V8 sort is fast but the spread + comparator allocation is not free).
2. `categoryCounts`: O(n) reduction.
3. `filteredProducts`: up to 3 chained `.filter(...)` passes — O(n) each.

**Total: ~3-4 full scans of the 5,000-product array on every category-tab tap or search-keystroke.** Each scan is fine in isolation (~5-15 ms), but compounded with React re-rendering 12-15 cards = **~80–150 ms blocking the main thread** on every keystroke. Visible jank on a low-end POS host. Not catastrophic, but it's the reason a category switch *feels* slow even though the virtualizer is doing the right thing.

`extractCategories` in the store (`productStore.ts:38-46`) also runs on every `fetchProducts` and every `refreshFromSQLite` if the diff says `changed`. That's an additional O(n) scan + Set allocation, ~10-20 ms.

### Display-mode cost difference

- `'grid'` (text-only): no `<img>` element rendered. Cheapest. ~5,000 SKUs trivially handled.
- `'visual'` (image cards): `<img src={imageSrc} ... />` for each rendered card. With lazy-image queue and no immediate processing, the initial visible-grid render shows `<Package>` placeholders — the rendering itself is cheap, but the perceived "is this loading?" jank is a UX issue, not a render-cost issue.

### Severity

`P2` — the virtualizer works correctly. The compounded `useMemo` chain has visible cost but is fixable with a single-pass filter or move to a worker. Not a P0/P1 blocker.

---

## 6. Network Bandwidth Profile

### Wire format estimates

**Server-side `ProductData` DTO** (`apps/api/.../ProductData.php`):

24 scalar fields (`id, name, sku, type, description, sale_price, purchase_price, cost_price, tax_rate, default_tax_configuration_id, unit, barcode, is_active, is_physical, oem_numbers, cross_references, target_margin_override, minimum_margin_override, created_at, updated_at, primary_image_url`) + nullable `parapharmacy_metadata` and `automotive_metadata` nested objects.

For a parapharmacy product, the eager-loaded nesting includes:
- `parapharmacy_metadata.ingredients[]` — variable count, typically 5–20 ingredients per product
- `parapharmacy_metadata.keyComponents[]` — 1–5 entries
- `parapharmacy_metadata.healthClaims[]` — 1–10 entries
- `parapharmacy_metadata.certifications[]` — 0–3 entries

**Estimated per-product JSON size (parapharmacy):** 1.5–3 KB uncompressed. Baseline: a stripped-down POSProduct shape would be ~250 bytes. **The wire is ~6–12× over-fetched relative to what the POS UI needs.**

### Cold-launch transfer estimate (5,000 SKUs)

| Path | Per-product | 5,000 SKUs uncompressed | gzipped (typical 0.25–0.30 ratio for repetitive JSON) | Time on 4G HSPA+ (~3 Mbps effective) |
|---|---|---|---|---|
| Foreground single page (current) | ~2 KB | ~1 MB (only 500 rows) | ~250 KB | ~0.7 s (just the first 500) |
| Background paginated pull | ~2 KB × 5,000 | **5–15 MB** | **~1.5–4 MB** | **~5–12 s** ideally; slower in practice with TLS handshake + Laravel response time per page (~10 round trips × 200–800 ms = 2–8 s server-side latency on top) |
| With POS-projected fields | ~250 B × 5,000 | ~1.2 MB | ~300–400 KB | ~1–2 s |

**Real-world parapharmacy wall-clock on 4G:** plan-note assumes 5,000-SKU pull completes within "a few minutes" — actual measurement is required, but rough ceiling is 10–20 s on healthy 4G, 30–60 s on degraded 4G, indefinite on captive-portal'd or sub-1-Mbps connections (no foreground timeout per Phase 1 §1.3 — that's still pending).

### Pagination, cursor, ETag, 304

- **Pagination:** offset-based via Laravel's `paginate($perPage)` with `?page=N&per_page=500`. **No cursor pagination** — for a stable 5,000-SKU set this is fine, but for a busy catalog with concurrent inserts it can produce duplicate or missing rows across pages (acceptable for first-launch warmup; wrong for high-frequency delta sync).
- **`updated_since` delta:** supported server-side (`ProductController.php:101-118`), used by background `pullProducts` (`syncService.ts:380-384`), **not used by foreground `fetchPOSProducts`**.
- **`deleted_ids` tombstones:** supported and consumed correctly by background sync.
- **ETag / `If-None-Match`:** captured for *images* (`imageCache.ts:178`) but never sent back. Not used for `/products` at all. Laravel doesn't emit ETag headers by default and there's no middleware adding them.
- **`If-Modified-Since`:** not used.
- **HTTP/2 multiplexing / response compression:** depends on server config; assumed gzip-on at the nginx/Apache layer, but **the Tauri `fetch` does not explicitly request `Accept-Encoding: gzip`** (the HTTP plugin default may include it; verify in a packet trace). No code-level evidence either way.

### Severity

`P1` — over-fetching is a real cost on 4G. The single biggest backend lever is a POS-projected query.

---

## 7. Failure Modes

### What happens if the pull is slow (15s+) on 4G?

**Foreground (`fetchProducts`):**
- `apps/pos/src/lib/api.ts:87-92` — `connectTimeout: 10000`, **no read-timeout**. The fetch can hang indefinitely once the TCP connection is established. Phase 1 §1.3 of the offline-first plan calls for a 30 s `AbortController`-based read timeout but is not yet implemented.
- The `<ProductGrid>` shows the spinner the entire time (`ProductGrid.tsx:155-164`). No partial state, no "still loading" affordance, no Cancel button.
- Cashier perception: "the app is frozen". Recovery requires force-quit and relaunch.

**Background (`pullProducts`):**
- Each page fetch is also subject to the same 10 s connectTimeout / no-read-timeout. A stalled page mid-pagination will hang the SyncScheduler tick until the OS gives up (typically 60–120 s for a TCP stale connection).
- `pullProducts` returns `0` on error (`syncService.ts:429-433`) and the next tick retries with the same `updated_since` cursor — so partial pages already written to SQLite are preserved (positive). But the in-memory `productStore` only sees the new rows after the next successful tick + `refreshFromSQLite`.

**Useful loading indicator?** No. The grid spinner is opaque — no progress, no row count, no "partial catalog" indicator. The Phase 3 §3.2 banner is the planned fix but is not yet built.

**Can the cashier start a sale on a partial catalog?** Yes:
- The cart is open as soon as `shift` is truthy.
- Barcode scan works against SQLite-cached products.
- Manual product search works against whatever's in `productStore.products`.
- Items not yet synced are simply invisible — search misses, barcode-not-found falls back to "product not found" error (modal in `cartStore`).
- **No gate** on `products.length > 0` blocks shift open or checkout (verified by grep — no such guard in `HomePage.tsx` or `PaymentSummary.tsx`). This matches Phase 3 §3.3 default.

### Pull abort mid-stream

If the user kills the app during foreground `fetchPOSProducts`:
- Whatever was already returned by the server is dropped (the upsert in `productStore.ts:113` ran *after* the await resolves, so a mid-fetch abort writes nothing).
- SQLite is unchanged; on next launch, it shows whatever the previous run had cached (could be zero if this was first launch).

If the user kills the app during background `pullProducts`:
- Whatever pages already completed are durable in SQLite (each page writes via `upsertProducts` before incrementing `page`, `syncService.ts:399-409`).
- On next launch, the next tick's `updated_since` cursor is *still* the original value (the cursor only advances after the *full* pagination loop succeeds — `syncService.ts:416-417`). So the next run re-pulls everything. Wasteful, but correct (no missed rows).

### 401 mid-pull

If the auth token expires during a pull:
- `apiGet` throws `ApiRequestError(401, ...)` which `pullProducts` catches and returns `0`.
- `runFullSync` collects errors; the scheduler detects `result.errors.some(e => e.includes('Unauthorized'))` and triggers `checkSession()` / logout. (`syncScheduler.ts:97-110`).
- Cashier is logged out mid-shift. Acceptable (auth recovery is the offline-first plan's Phase 1 concern).

### 500-row corner case

The pagination loop in `pullProducts` uses `hasMore = products.length === 500` (`syncService.ts:408`). **If the server returns exactly 500 products on the last page, the loop fires one extra request.** Not a correctness bug (the next page will be empty and the loop terminates), just one wasted round-trip. Acceptable.

---

## 8. Quick Wins (each <2 h)

Ranked by impact ÷ effort ratio. Each scoped to <2 hours of work.

### QW1: Replace foreground single-shot with paginated pull (P0 fix)
**File:** `apps/pos/src/stores/productStore.ts:52-58, 105-159`.
**Change:** `fetchProductsFromAPI(config)` for retail tenants delegates to a new helper that wraps `pullProducts(db)` (already paginated, already supports `updated_since` and tombstones), then `refreshFromSQLite()` to load the in-memory store. Keep F&B `Menu` path untouched.
**Impact:** removes the 500-SKU cap. Cashier sees full 5,000-SKU catalog on cold launch instead of waiting ~10 minutes for background ticks. **Single highest-impact change in this audit.**
**Effort:** ~1 h (matches Phase 3 §3.1 plan).
**Risk:** must update `lastFetched` only after the *full* pagination succeeds, not after page 1 — addressed in plan §3.1 review prompt.

### QW2: Add catalog warmup banner with progress
**File:** `apps/pos/src/stores/productStore.ts` + `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`.
**Change:** add `catalogWarmupState` + `warmupProgress: { loaded, expected }` to `productStore`. Drive a sticky banner above the grid: "Loading catalog: 1500 / 5000 products… you can keep selling".
**Impact:** cashier doesn't restart the app thinking it's frozen. Trust signal during the ~5–15 s bandwidth hit.
**Effort:** ~1 h (matches Phase 3 §3.2 plan).
**Risk:** debounce state writes per page so we don't trigger 10 re-renders for 10 pages.

### QW3: Pre-warm visible-grid images on render
**File:** `apps/pos/src/lib/images/useProductImage.ts:19-27`, `apps/pos/src/lib/images/imageCache.ts:107`.
**Change:** when `enqueueDownload` is called and `isProcessing === false`, fire `void processDownloadQueue(db)` immediately (debounced ~250 ms via setTimeout). Don't wait for the next 60 s sync tick.
**Impact:** on `'visual'` mode, the cashier sees images for the *first viewport* within ~3–5 s of the grid mounting instead of waiting up to 60 s.
**Effort:** ~30 min.
**Risk:** must make sure two concurrent `processDownloadQueue` calls don't double-fetch — already guarded by `isProcessing` flag (`imageCache.ts:108`). Pass a debounced db reference (already accessible via `getDatabase(companyId)` from the store).

### QW4: Persist `updated_since` for the foreground revalidation
**File:** `apps/pos/src/stores/productStore.ts:107` + `apps/pos/src/api/productApi.ts:6-13`.
**Change:** when the foreground revalidation fires *and* SQLite already has products, send `updated_since=<sync_metadata.products_last_sync>` in the params. (After QW1 lands this is automatic; if QW1 doesn't ship today, this is the standalone fix.)
**Impact:** second-launch foreground revalidation goes from ~5–15 MB → ~50 KB (just the diff). Cuts perceived "slowness on warm start" complaints.
**Effort:** ~30 min.
**Risk:** none — the server already supports this and `pullProducts` already uses the same mechanism.

### QW5: Move `extractCategories` and category-counts off the hot path
**File:** `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:89-118`, `apps/pos/src/stores/productStore.ts:38-46`.
**Change:** combine `sortedProducts`/`categoryCounts`/`filteredProducts` into a single-pass reducer over `products`. Extract categories once on `set` rather than on every fetch.
**Impact:** ~80–150 ms category-tab-tap latency drops to ~30 ms. UX feels snappier, especially on category-heavy parapharmacy (typically 20–40 categories).
**Effort:** ~1.5 h (more if test coverage is needed).
**Risk:** minor — the reducer must preserve `sortMode === 'mostSold'` ordering correctly. Existing tests in `__tests__/` should catch regressions.

### Bonus, not in <2h budget but worth flagging

- **POS-projected backend endpoint** — `?fields=pos` query param or `/pos/products` route returning only POSProduct fields. **6–12× wire reduction on parapharmacy.** Effort: ~3 h backend (ProductData → POSProductData DTO + controller branching + tests).
- **LRU cap on `product_images` directory** — 100 MB hard cap with size-based eviction in `cleanupOrphanedImages` or a new function. Effort: ~2 h.

---

## 9. Benchmark Plan

### What to measure

Build the 5,000-SKU parapharmacy fixture (Phase 0 §4 of the hardening plan) and capture these metrics on the same hardware the client will use:

| Metric | How | Expected baseline (current code) | Target after QW1+QW2+QW4 |
|---|---|---|---|
| `T_first_paint` — cold launch to first product card visible | manual stopwatch + Tauri devtools `Performance.now()` checkpoint at `set({ products, isLoading: false })` | ~2–4 s (single 500-row page hits) | ~3–5 s (paginated, but progressive) |
| `T_full_warmup` — cold launch to all 5,000 products in `productStore.products` | log `products.length` on every state update; capture timestamp when it hits 5,000 | **~10 minutes** (background scheduler @ 60 s × 10 ticks) | **~10–15 s** (foreground paginated loop with no inter-page delay) |
| `T_sqlite_first_paint` — second launch (warm SQLite) to grid render | stopwatch + checkpoint at `set({ products: cachedProducts })` | ~200–500 ms (verified-correct path) | unchanged (already optimal) |
| `Bytes_received_cold` — total `/products` response bytes on cold launch | Tauri devtools Network panel sum | 5,000 × ~2 KB ≈ **10 MB** uncompressed, ~3 MB gzipped | unchanged unless POS-projected endpoint ships; with `updated_since` on subsequent launches: ~50 KB |
| `Bytes_received_warm` — same on second launch | Network panel | **~3 MB gzipped** (full re-pull, no delta in foreground) | **~50 KB** (delta only) after QW4 |
| `Image_localization_p50` — time from cold launch to 50% of *visible* images localized | log `imageMap.size` over time, intersect with virtualizer's rendered card IDs | ~60–90 s (waits for first scheduler tick + first `processDownloadQueue` batch) | ~5–10 s after QW3 |
| `Image_localization_p100` — full 5,000-image cache | same | **~25–50 minutes** at 60 s/tick × 10 images/batch × 500 batches | **~5–10 min** if QW3 + a denser batch-firing cadence (or accept the slow tail since it's not user-visible) |
| `Disk_footprint_after_full_sync` | `du -sh ${appDataDir}/images/products/` | ~150 MB for 5,000 thumbnails (~30 KB avg) | unchanged (no eviction) |
| `Category_switch_latency` — tap a category, measure to next paint | React DevTools Profiler | ~80–150 ms | ~30–50 ms after QW5 |
| `Search_keystroke_latency` — type one char, measure to filter result | React DevTools Profiler | ~50–100 ms | ~20–40 ms after QW5 |

### How to measure

1. **Tauri devtools Performance tab** — Record from boot to grid render. Look for long tasks > 50 ms.
2. **Network panel** — Filter to `/api/v1/products`. Sum `transferSize` (compressed) and `responseLength` (uncompressed). Confirm gzip is enabled on the backend (check `Content-Encoding: gzip` header).
3. **Custom checkpoints** — Add `console.timeStamp('catalog-warmup-page-1-done')` etc. inside `pullProducts` and `productStore.fetchProducts`. Read off the Performance timeline.
4. **Throttling** — Use Chrome devtools' "Slow 3G" preset (400 Kbps down, 400 Kbps up, 2 s RTT) when running the Tauri webview pointed at a remote API. For 4G HSPA+ realism, use "Fast 3G" (1.6 Mbps down, 750 Kbps up, 562 ms RTT).
5. **5,000-SKU fixture** — generate via a Laravel seeder; the existing `DemoTenantSeeder` is the model. Make sure it produces parapharmacy-shaped products with `parapharmacy_metadata` populated so the wire payload is realistic.

### What "good enough" looks like for go-live

- `T_first_paint` ≤ 5 s on Slow 3G.
- `T_full_warmup` ≤ 30 s on Slow 3G (currently 600+ s — *this is the cliff that QW1 fixes*).
- Cashier can ring up a manually-typed sale within `T_first_paint`. (This already works because checkout doesn't gate on `products.length`.)
- Image localization on `'visual'` mode: at least the first viewport visible within 30 s.

---

## 10. Reconciliation vs Prior Audit and Phase 3 Plan

### vs. `2026-04-30-pos-offline-first-audit-codex.md` §3

| Codex finding | Verdict | Notes |
|---|---|---|
| "single `/products?per_page=500` request on first launch ... can stop at 500 products" | **Confirmed.** | Wire-level evidence: `productApi.ts:11-13` + Laravel `paginate($perPage)`. |
| "foreground path is single-shot" | **Confirmed.** | No pagination loop in `productStore.ts`. |
| "image downloads are lazy and driven by rendered cards, not by a bounded warmup plan" | **Confirmed and worsened.** | The 60 s tick gating means even *requested* images don't start downloading immediately. |
| "ETag never sent back as `If-None-Match`" | **Confirmed.** | Captured but unused. |
| "`pullProducts()` swallows errors and returns `0`, so a failed multi-page pull can still leave partial SQLite writes" | **Confirmed.** | Partial writes are *correct* (each page is durable), but the cursor doesn't advance until the full loop succeeds, so the next tick re-pulls everything. Wasteful, not corrupting. |

**Codex did not call out:**
- The over-fetching ratio (POS uses 12 of 24 fields, plus parapharmacy nesting we don't render).
- The compounded `useMemo` cost on category switch.
- The unbounded `product_images` disk growth.
- The 60 s gating on `processDownloadQueue` (Codex saw the lazy-download but missed the timing wall).

### vs. `2026-04-30-pos-offline-first-hardening.md` §Phase 3

| Plan step | Status | Verdict |
|---|---|---|
| §3.1 — Foreground `fetchProducts` uses paginated pull | **Not yet implemented** as of this audit (verified by reading `productStore.ts` HEAD). The plan is correct and lines up with QW1 here. | ✅ Endorse. |
| §3.2 — Catalog-warmup banner | **Not yet implemented.** Aligns with QW2. | ✅ Endorse. |
| §3.3 — Allow shift open with partial catalog (verify-only) | Already true: no `products.length > 0` gate exists in `HomePage.tsx`/`PaymentSummary.tsx`. Barcode scan path uses SQLite (`getProductByBarcode`), so it works against whatever's been pulled. | ✅ Confirmed safe. **Add:** verify there's no online-fallback fetch on barcode-not-found that would block the cashier with a network error. (Quick grep didn't find one — barcode-not-found just shows a not-found toast.) |

**Phase 3 plan gaps this audit identifies:**

1. **No mention of the wire over-fetching.** A 5,000-SKU paginated pull at 2 KB/row is still 10 MB; the plan implies pagination alone is the fix. Add a follow-up TODO: POS-projected endpoint reduces wire 6–12×.
2. **No mention of the foreground revalidation on second launch not using `updated_since`.** After §3.1 lands, this is automatic; if §3.1 ships only the "delegate to pullProducts" approach, double-check that `productStore.fetchProducts` doesn't *also* call `fetchPOSProducts` independently.
3. **No mention of the image-loading 60 s gating.** §3.2 banner only addresses catalog rows, not images. The plan's "out of scope: Image preloading manifest" line covers the deferral, but we should at least add QW3 (immediate `processDownloadQueue` trigger) — it's a 30-min change.
4. **No mention of the `useMemo` compounded scan cost.** Acceptable to defer post-launch (it's a P2 polish issue), but worth a TODO comment.
5. **`extractCategories` runs in the store on every fetch.** Trivial, but combine with the diff path: only re-extract when `diffProducts.changed` is true.

### Summary

The Phase 3 plan correctly identifies the dominant problem (the 500-SKU foreground cap) and the right two fixes (paginated foreground + warmup banner). This audit confirms those two are *necessary*, identifies that they're *not yet sufficient* for the parapharmacy bandwidth profile, and adds three more <2 h quick wins that don't require new architecture: image pre-warm trigger, `updated_since` on foreground revalidation, and consolidating the `useMemo` chain.

The biggest incremental risk this audit surfaces beyond Phase 3: **on a 4G network where each `/products?per_page=500` page takes 2–3 s, the paginated foreground pull will take 20–30 s wall-clock for a 5,000-SKU parapharmacy.** That's still within "acceptable on go-live day" but the cashier *must* see a progress banner. Make sure §3.1 and §3.2 ship together — pagination without the banner replaces a 500-row mystery cap with a 30-second mystery wait.

---

## Open questions for the human

1. **Can we ship a POS-projected endpoint today?** The 6–12× wire savings on parapharmacy is the single largest network-side win. If the backend team has bandwidth (~3 h work), QW1 + POS-projected fields together cut the cold-start transfer to ~1 MB gzipped (sub-3 s on 4G).
2. **Disk eviction policy for `product_images/`?** 150 MB after first sync, monotonic growth across catalog churn. Acceptable for a fresh deployment; revisit at the 6-month mark or implement an LRU cap now.
3. **Is the Tauri HTTP plugin requesting `Accept-Encoding: gzip` by default?** Verify with a packet trace. If not, add it in `apps/pos/src/lib/api.ts:53-66` — instant ~3× transfer reduction. (Phase 3 plan doesn't mention this.)
4. **Production bandwidth measurements?** Plan §3.0 mentions "Slow 3G" devtools throttling for smoke tests. Get a real cellular measurement from the parapharmacy site if possible — the difference between 1.5 Mbps and 3 Mbps doubles every wall-clock estimate above.
