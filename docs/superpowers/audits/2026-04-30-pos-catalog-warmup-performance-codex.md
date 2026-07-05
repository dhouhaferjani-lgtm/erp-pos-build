# POS Catalog Warmup Performance Audit - 2026-04-30 (Codex)

Scope: static audit of the Tauri POS desktop app (`apps/pos`) product catalog warmup path for a 5000+ SKU parapharmacy tenant. No runtime benchmark was executed; timing and byte estimates are inferred from code shape and should be validated with the benchmark plan below.

References reviewed:
- `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md` Phase 3
- `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md`
- `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md`
- `docs/superpowers/audits/2026-04-30-pos-catalog-warmup-performance-opus.md`

## Executive Summary

### Top 5 findings

1. `P0` Foreground retail warmup is still capped at 500 visible SKUs. `fetchProductsFromAPI()` calls `fetchPOSProducts({ limit: 500 })`, and `fetchPOSProducts()` turns that into one `/products?per_page=500` request. There is no foreground pagination, no streaming, and no partial-complete state. This confirms the offline-first audit's 500-SKU cap. `apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:6-13`

2. `P0` The only full 5000-SKU retail pull is the background sync path, but it refreshes the UI only after `runFullSync()` finishes. `pullProducts()` loops pages of 500 and upserts each page, then the scheduler calls `refreshFromSQLite()` after the whole sync result returns. SKU 501+ does not progressively appear per page. `apps/pos/src/lib/sync/syncService.ts:436-485`, `apps/pos/src/lib/sync/syncService.ts:1212-1234`, `apps/pos/src/lib/sync/syncScheduler.ts:72-79`

3. `P0` The desktop POS consumes the general `/products` DTO as if it were `POSProduct`. The local cache requires `stock_quantity`, `category`, and `image_url`, while `ProductData` exposes `primary_image_url` and parapharmacy metadata, and the desktop client does not map it like the web POS does. If the runtime response matches this DTO, SQLite upserts can fail or cache incomplete rows, and product cards receive undefined stock/category/image fields. `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:20-84`, `apps/pos/src/types/product.ts:3-16`, `apps/pos/src/lib/db/migrations.ts:13-30`, `apps/pos/src/lib/db/repositories/productRepository.ts:69-81`, `apps/web/src/features/pos/api/productApi.ts:34-43`

4. `P1` Warm-launch time-to-first-render is good when SQLite already has products, but network avoidance is poor. `fetchProducts()` renders SQLite immediately when rows exist, then still fires a foreground API refresh; terminal activation also starts the sync scheduler, which immediately ticks. A normal warm boot can duplicate product traffic and write work. `apps/pos/src/stores/productStore.ts:73-91`, `apps/pos/src/stores/productStore.ts:149-154`, `apps/pos/src/stores/terminalStore.ts:97-106`, `apps/pos/src/lib/sync/syncScheduler.ts:25-33`

5. `P2` Image loading is lazy and non-blocking, but it creates avoidable warmup pressure. Every mounted `ProductCard` calls `useProductImage()` even in grid mode, downloads are only drained from sync ticks, `etag` is stored but not reused, and orphan cleanup is not wired in production. `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:28-31`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:104-117`, `apps/pos/src/lib/images/useProductImage.ts:15-27`, `apps/pos/src/lib/images/imageCache.ts:157-203`, `apps/pos/src/lib/images/imageCache.ts:209-260`, `apps/pos/src/lib/sync/syncService.ts:1230-1234`

### Quick wins under 2 hours

1. Replace the foreground retail `fetchPOSProducts({ limit: 500 })` call with the existing paginated pull path, then refresh from SQLite. This removes the 500-SKU foreground cap. `apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/lib/sync/syncService.ts:436-485`

2. Add a POS-safe product mapper in `apps/pos/src/api/productApi.ts`: map `primary_image_url` to `image_url`, normalize missing `stock_quantity`, and decide the category source. This mirrors the web POS mapper and avoids silent SQLite/cache corruption. `apps/web/src/features/pos/api/productApi.ts:34-43`

3. Raise retail page size to 2000 where safe. The backend allows up to 2000, while the POS currently asks for 500. That cuts a 5000-row full pull from about 10 data pages to about 3. `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:62-63`, `apps/pos/src/lib/sync/syncService.ts:439-452`

4. Add a warmup banner with `loaded / expected` and "partial catalog" language. The Phase 3 plan already specifies this; it is required because the app currently has only a blocking spinner or silent incompleteness. `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:199-211`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-175`

5. Only enqueue product images when visual mode can render them, and trigger `processDownloadQueue()` after visible-card enqueue or after product pull completion instead of waiting for the next sync tick. `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:28-31`, `apps/pos/src/lib/images/imageCache.ts:107-203`

## 1. Current Pull Strategy

Retail foreground warmup is single-shot. `fetchProductsFromAPI()` chooses `/active-menu` only for companies with the `Menu` module; otherwise it calls `fetchPOSProducts({ limit: 500 })`. `fetchPOSProducts()` sends `per_page: limit`, reads one response, and returns only `data`. It discards pagination metadata and does not request page 2. `apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:6-13`

The cashier-visible launch path starts only after shift open. `HomePage` calls `fetchProducts()` when `shift` is truthy, along with payment config and company config refresh. `apps/pos/src/pages/HomePage.tsx:302-312`

What the cashier sees:
- Empty SQLite: `ProductGrid` shows a centered spinner while the one foreground request is in flight; then it renders the returned product array all at once. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-164`, `apps/pos/src/stores/productStore.ts:155-158`
- Populated SQLite: cached rows render immediately and the API refresh runs in the background. `apps/pos/src/stores/productStore.ts:73-91`, `apps/pos/src/stores/productStore.ts:149-154`
- Partial first launch: the UI has no state saying "500 of 5000 loaded"; missing SKUs just do not exist to search or scan. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`, `apps/pos/src/pages/HomePage.tsx:230-247`

The background sync path is better but not cashier-progressive. `pullProducts()` reads `products_last_sync`, calls `/products` with `per_page=500`, increments `page` until a short page, writes each page to SQLite, handles `deleted_ids`, and advances sync metadata only after successful work. `runFullSync()` then continues through payment config, operators, terminal state, tables, menu, vouchers, and image queue processing before returning. Only after that does `SyncScheduler` call `productStore.refreshFromSQLite()`. `apps/pos/src/lib/sync/syncService.ts:436-485`, `apps/pos/src/lib/sync/syncService.ts:1212-1234`, `apps/pos/src/lib/sync/syncScheduler.ts:72-79`

For exactly 5000 rows at page size 500, expect 10 data-page responses plus one terminating request if the server returns exactly 500 on page 10 and page 11 returns 0. The code uses `hasMore = products.length === 500`, so exact multiples incur the extra terminating round trip. `apps/pos/src/lib/sync/syncService.ts:449-468`

## 2. SQLite Cache

The local catalog table exists from migration v1 with indexes for SKU, barcode, and category. It stores the POS-facing fields plus timestamps. `modifier_groups` is added in migration v11. `apps/pos/src/lib/db/migrations.ts:13-30`, `apps/pos/src/lib/db/migrations.ts:228-242`

Reads are full-table and sorted by name: `SELECT * FROM products ORDER BY name`. On 5000 rows this is acceptable for warm-launch TTFR, but it is not paged or projected. `apps/pos/src/lib/db/repositories/productRepository.ts:39-42`

Writes are batched at 50 products per statement, with no explicit transaction around the full catalog. A 5000-product pull therefore performs about 100 `INSERT ... ON CONFLICT` statements. If a mid-pull failure occurs, earlier pages remain durable; if the process dies mid-batch, only that batch is at risk. `apps/pos/src/lib/db/repositories/productRepository.ts:53-104`, `apps/pos/src/lib/sync/syncService.ts:457-475`

Cache invalidation:
- No TTL.
- Background sync uses `sync_metadata.products_last_sync` and server `deleted_ids`. `apps/pos/src/lib/sync/syncService.ts:438-475`
- Foreground refresh ignores `updated_since` and blindly asks for one 500-row page. `apps/pos/src/stores/productStore.ts:104-158`

Cache hit behavior:
- Fresh install: UI cache hit rate is 0%.
- Previously warmed install: UI cache hit rate is effectively 100% when SQLite opens and contains rows, because the grid is seeded before any network response. `apps/pos/src/stores/productStore.ts:73-87`
- Network avoidance hit rate is still 0% on warm launch because the app revalidates through the foreground API and the sync scheduler starts its own immediate tick. `apps/pos/src/stores/productStore.ts:149-154`, `apps/pos/src/lib/sync/syncScheduler.ts:25-33`

Critical cache contract risk: the desktop code assumes `/products` returns `POSProduct`, but the backend product list is the general product controller. `ProductData` does not expose the desktop field names for images (`primary_image_url` vs `image_url`) and does not visibly include `stock_quantity` or display `category` in its constructor. The web POS has a mapper for `primary_image_url`; the desktop POS does not. Because `upsertProducts()` writes `p.stock_quantity` into a NOT NULL column and catches SQLite upsert errors silently in the foreground path, this can turn a catalog pull into a transient in-memory list with no durable warm cache. `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:62-120`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:20-84`, `apps/pos/src/lib/db/repositories/productRepository.ts:69-81`, `apps/pos/src/stores/productStore.ts:109-116`, `apps/web/src/features/pos/api/productApi.ts:34-43`

## 3. Image Loading

Images are not part of catalog warmup. Product rows carry an image URL field when present, but image binaries are fetched lazily from mounted product cards. `ProductCard` calls `useProductImage(product.id, product.image_url)` before checking display mode. The image is only rendered in visual mode, but grid mode still enqueues on cache miss. `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:28-31`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:104-117`

`useProductImage()` synchronously checks the in-memory `imageMap`; on miss it calls `enqueueDownload()`. That only queues work. Downloads begin when `processDownloadQueue(db)` is called. `apps/pos/src/lib/images/useProductImage.ts:15-27`, `apps/pos/src/lib/images/imageCache.ts:70-100`

Production only calls `processDownloadQueue()` from `runFullSync()` near the end of a sync tick. A mounted card can therefore enqueue immediately but wait for the current or next sync cycle before its binary download starts. `apps/pos/src/lib/sync/syncService.ts:1230-1234`

The downloader processes all queued items in batches of 10 concurrent fetches, writes files to `${appDataDir}/images/products`, stores manifest rows, and caches converted asset URLs in memory. It stores `etag`, but no code sends `If-None-Match` on later downloads. `apps/pos/src/lib/images/imageCache.ts:157-203`

Eviction is effectively absent. `cleanupOrphanedImages()` exists but grep found only tests and the function definition, no production callsite. It also removes only orphans; there is no LRU, TTL, or size cap. `apps/pos/src/lib/images/imageCache.ts:209-260`

Estimated footprint for 5000 SKUs:
- JS memory: one productId to asset-URL entry per downloaded image; likely low single-digit MB.
- Browser decode memory: bounded by virtualization because only mounted visual cards render `<img>`.
- Disk: inferred 100-250 MB if 5000 thumbnail files average 20-50 KB each, with unbounded growth until products are explicitly orphan-cleaned.

## 4. Render Performance

The grid uses `@tanstack/react-virtual` with fixed estimated row height. Grid mode uses 140 px card height; visual mode uses 220 px; the virtualizer estimates `rowHeight + GAP` and overscans 3 rows. At 5000 products this means about 1000 logical rows in grid mode on desktop (5 columns) or 1250 in visual mode (4 columns). Rendered DOM is bounded and the virtualizer itself is not the bottleneck. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:38-55`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:144-153`, `apps/pos/src/components/molecules/ProductCard/cardSizing.ts:9-28`

The main-thread cost is synchronous derivation:
- `sortedProducts` clones and sorts the full product list when products, sort mode, or most-sold counts change. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:88-107`
- `categoryCounts` scans the full list on product changes. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:109-118`
- `filteredProducts` scans the sorted list for category, stock, and search. Search lowercases each product name/SKU/barcode on every keystroke. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`
- `extractCategories()` scans all products and sorts categories after cache/API refreshes. `apps/pos/src/stores/productStore.ts:38-46`

At 5000 rows these are not catastrophic, but category switches and search keystrokes are still several O(n) passes on the UI thread. Expected jank is most likely on low-end Windows terminals and when the product array changes from a background refresh.

Partial-catalog scan rescue is weaker than Phase 3 assumes. Product barcode handling searches the in-memory `products` array only; it does not query local SQLite via `getProductByBarcode()` and does not fall back to `fetchProductByBarcode()` online. `apps/pos/src/pages/HomePage.tsx:230-247`, `apps/pos/src/lib/db/repositories/productRepository.ts:44-50`, `apps/pos/src/api/productApi.ts:16-18`

## 5. Network Bandwidth Profile

The desktop POS hits the general product endpoint, not a POS-projected catalog endpoint. The backend permits `per_page` up to 2000 but the POS voluntarily uses 500 in both foreground and background product pulls. `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:62-63`, `apps/pos/src/stores/productStore.ts:57`, `apps/pos/src/lib/sync/syncService.ts:439-452`

For parapharmacy tenants, the product controller eagerly loads `category`, `primaryImage`, and nested parapharmacy metadata relations. The `ProductData` DTO carries many fields the POS grid does not use, including purchase/cost/margin fields and nested ingredients/key components/health claims/certifications. `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:65-72`, `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:20-84`, `apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:30-49`, `apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:67-142`

Estimated transfer size for 5000 parapharmacy SKUs:
- Lean metadata: roughly 0.8-1.5 KB raw JSON per product; 1-2 MB compressed total.
- Rich metadata: roughly 2-5 KB raw JSON per product; 2.5-7.5 MB compressed total.
- POS-projected DTO: likely 200-400 bytes raw per product, or a few hundred KB compressed for 5000 rows.

Request profile today:
- Empty-cache foreground: one `/products?per_page=500` request.
- Full background pull: about 10 data requests plus a possible 11th terminating request at 5000 exact rows.
- Warm launch: at least one redundant foreground `/products?per_page=500` plus scheduler product sync.

No catalog ETag/304 path exists in the desktop API helper. `api.ts` sends content headers, auth, company, and query params, then parses JSON; there is no `If-None-Match`, `If-Modified-Since`, or 304 handling. `apps/pos/src/lib/api.ts:53-123`

The API wrapper sets `connectTimeout: 10000` but no read timeout. A slow 4G response that connects and then stalls can keep the foreground spinner up indefinitely until the lower network stack fails. `apps/pos/src/lib/api.ts:87-92`

## 6. Failure Modes

Slow first pull (15s+):
- Empty SQLite: cashier sees an opaque spinner with no elapsed/progress text. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-164`
- If the first 500 eventually return, cashier sees a partial catalog with no warning. Search and category filters operate only on those rows. `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:120-142`
- If the request fails and no local data exists, the store surfaces `error`, clears loading, and the grid shows empty state because `products.length === 0`; there is no retry button in the grid. `apps/pos/src/stores/productStore.ts:136-146`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:166-175`

Slow background pull:
- Pages already upserted are durable, but the product store is not refreshed until the full sync tick returns. `apps/pos/src/lib/sync/syncService.ts:457-475`, `apps/pos/src/lib/sync/syncScheduler.ts:72-79`
- `pullProducts()` catches errors, logs sync operation failure, and returns 0. Earlier page writes may remain in SQLite, but the UI has no "partial sync" state. `apps/pos/src/lib/sync/syncService.ts:486-493`

API shape mismatch:
- If `/products` omits `stock_quantity`, `upsertProducts()` writes an undefined/null value into a NOT NULL SQLite column. Foreground catches the SQLite error silently and still sets in-memory products from the API result; background `pullProducts()` catches at the pull level and returns 0. `apps/pos/src/lib/db/migrations.ts:13-30`, `apps/pos/src/lib/db/repositories/productRepository.ts:69-84`, `apps/pos/src/stores/productStore.ts:109-116`, `apps/pos/src/lib/sync/syncService.ts:486-493`

Partial catalog sales:
- Shift open and checkout are not gated on catalog completeness, which is the right default for cashiers. `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:213-220`
- But barcode scan is in-memory only today, so missing warmup rows cannot be recovered by scanning unless they are already in `productStore.products`. `apps/pos/src/pages/HomePage.tsx:230-247`

Image failures:
- Failed image HTTP responses and exceptions delete the queue item and do not retry or log. The product still renders with remote URL fallback initially or package placeholder if absent, but localization silently stops. `apps/pos/src/lib/images/imageCache.ts:167-198`

## 7. Benchmark Plan

### What to measure

1. Time-to-first-render:
   - Start: `fetchProducts()` invocation after shift open on empty SQLite.
   - End: first `ProductGrid` commit with at least one card visible.

2. Full catalog warmup:
   - Start: first product network request after terminal activation.
   - End: SQLite `products` count and product store length both reach 5000, no product pages in flight.

3. SQLite cache hit rate:
   - UI hit: `getAllProducts(db)` returns rows before first network response.
   - API avoidance: no `/products` request on warm launch. Current expected value: 0%.

4. Network profile:
   - Total bytes for foreground `/products`.
   - Total bytes for background `/products`.
   - Page count and duplicate first-page count.
   - Image download bytes during first 5 minutes after shift open.

5. Main-thread responsiveness:
   - Category tab switch latency.
   - Search keystroke latency at 1, 3, and 5 characters.
   - Display mode toggle latency.
   - Time spent in `sortedProducts`, `filteredProducts`, `categoryCounts`, and `extractCategories`.

6. Failure-mode behavior:
   - Slow 4G at 256 kbps / 1 Mbps / 3 Mbps.
   - 15s stalled read after TCP connect.
   - Mid-page API 500 on page 4 of 10.
   - Product DTO missing `stock_quantity`.

### How to measure

- Add temporary `performance.mark()` probes around `fetchProducts()` start/end, each `pullProducts()` page, `upsertProducts()` batch loop, `refreshFromSQLite()`, and first non-empty grid render.
- Capture Chromium/Tauri devtools network logs with throttling and export HAR.
- Capture SQLite counts before launch, after foreground request, after each sync tick, and after completion.
- Record store snapshots at 0s, 1s, 5s, 10s, 30s, 60s, and completion.
- Use the existing `docs/testing/import-samples/products-10k.csv` or a dedicated 5000-SKU parapharmacy seed with representative metadata and thumbnail URLs.

### Expected baselines from current code

- Fresh empty SQLite:
  - TTFR: one 500-row request + one 500-row SQLite upsert attempt + diff/render.
  - Visible catalog after TTFR: at most 500 retail products.
  - Full warmup: only after background sync completes all pages and scheduler refreshes from SQLite.

- Warm SQLite:
  - TTFR: sub-second if SQLite opens normally.
  - Network: still one foreground product request plus scheduler activity.

- Slow 4G:
  - User-visible state: spinner first, then silent partial catalog.
  - No progress count, no partial catalog warning, no barcode fallback beyond in-memory rows.

## 8. Reconciliation

### Versus offline-first Codex audit

Agree: the foreground 500-SKU cap is real and still present. The cited path remains `productStore.fetchProducts()` -> `fetchPOSProducts({ limit: 500 })`. `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md:45-75`, `apps/pos/src/stores/productStore.ts:52-58`

Extend: the earlier audit treated background `pullProducts()` as the eventual full-catalog path. That is true only if the `/products` payload matches the local `POSProduct` schema well enough to upsert. Current desktop code lacks the web POS `primary_image_url` mapper and appears to expect fields not present in `ProductData`. `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:20-84`, `apps/web/src/features/pos/api/productApi.ts:34-43`

Agree: second launch is SQLite-first for rendering. Clarification: it is not network-first, but it is still network-noisy because foreground and scheduler pulls can both run.

### Versus offline-first Claude audit

Agree: the 500 cap is P0 and product-store refresh from SQLite after sync is good but not page-progressive. `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md:53-80`

Agree with the practical priority: payment config may be a more visible checkout blocker, but catalog truncation is the largest parapharmacy go-live data-risk because it makes most SKUs undiscoverable.

Extend: Claude noted images are lazy; this audit adds that grid mode still enqueues image work even when images are not rendered and that cleanup is not wired in production.

### Versus Phase 3 plan

Phase 3 Step 3.1 is directionally correct: foreground retail warmup must reuse paginated pull logic. But the implementation must also solve payload mapping and UI progress. Calling `pullProducts(db)` then doing one final `refreshFromSQLite()` fixes completeness but not progress; page-by-page progress needs either returned accumulation, per-page refresh, or a warmup state updated from counts. `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:185-197`

Phase 3 Step 3.2 is required, not cosmetic. Without a banner, partial catalog state is silent and looks like missing data. `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:199-211`

Phase 3 Step 3.3 should be corrected: current barcode scan does not hit SQLite or `/products?barcode=...` on miss; it searches the in-memory product array. Partial-catalog selling is therefore weaker than the plan assumes. `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:213-220`, `apps/pos/src/pages/HomePage.tsx:230-247`

The plan's decision to defer "image preloading manifest" is reasonable for go-live, but a <2h image enqueue guard is still worth doing because it reduces contention during catalog warmup without building a full manifest system.

## Bottom Line

The current POS is fast only for cached catalogs and only complete after background sync succeeds. On a fresh 5000-SKU parapharmacy launch, the cashier-visible path returns 500 products, hides the remaining 4500 behind background work, and gives no truthful progress state. The next fix should combine paginated foreground warmup, a POS-safe product DTO/mapper, and a partial-catalog banner before optimizing images or render derivations.
