# Adversarial Design Review — POS Offline Variant Support (Spec A)

- **Spec under review:** `docs/superpowers/specs/2026-06-14-pos-offline-variants-design.md`
- **Branch / worktree:** `feat/pos-offline-variants` @ `apps/erp.pos-xloc-stock` (read-only verification)
- **Reviewer:** adversarial design review (Claude), every claim verified against source
- **Date:** 2026-06-14

---

## Summary Verdict: **APPROVE-WITH-EDITS**

The spec is well-grounded. I verified the load-bearing claims against the actual code and they hold:

- **The variant-scan bug is REAL** — `HomePage.addProductToCartWithToast` (`apps/pos/src/pages/HomePage.tsx:335-347`) calls `addItemGated(product)` with no `has_variants` check, while tile-tap `handleAddToCart` (`:838-855`) correctly opens the picker. A scanned variant-bearing product is silently added as the base product.
- **Migration latest is v52** (`apps/pos/src/lib/db/migrations.ts:1612` `create_product_stock_distribution_cache`), so a new `product_variants` table at **v53 is correct**.
- **Offline variant PRICING already works** — `cartStore.addItem` resolves `variant.price_override` (`apps/pos/src/stores/cartStore.ts:278-282`) and `ProductVariantStockView` does the same for display (`apps/pos/src/components/pos/ProductVariantStockView.tsx:44-47`). No pricing change needed.
- **Fiscal already carries the variant** — `SaleReceiptV2Payload.ts:26-31,61-77` signs `variant_id/variant_name/variant_sku`. No fiscal change needed.
- **Variant-grain stock gate already exists offline** — `addItemGated(product, { variant })` → `gateStockForAdd(product, variant.id, …)` reading `location_stock` (`apps/pos/src/lib/stock/cartIngress.ts:52-73`). Selling-once-picked is genuinely solved.
- **Backend surface exists** — `ProductVariantData` DTO, `ProductVariantController@index`, `ProductVariantService::resolveBarcode`, `ProductVariantLookup`, and the POS route group with `EnforceTokenTenantClaim` are all present as the spec claims.

However, the spec's central sync analogy is **partly inaccurate**, and that inaccuracy hides three correctness gaps (HIGH-1/2/3) that must be resolved in the plan before implementation. Hence APPROVE-WITH-EDITS rather than APPROVE.

---

## HIGH

### HIGH-1 — `/pos/stock-levels` has NO tombstone; the spec's "mirror full/delta/tombstone" is mirroring a pattern that doesn't exist
**Spec §4 (F), §6.2, §6.4** repeatedly say the new `/pos/variants` should mirror `/pos/stock-levels` which is described as "full/delta/tombstone". **It is not.** I read the entire `PosStockLevelController::index` (`apps/api/.../POS/Presentation/Controllers/PosStockLevelController.php:56-120`) and the `LocationStockReader` contract (`apps/api/app/Shared/Contracts/LocationStockReader.php`): the response envelope is `{ data: { stock, incoming, as_of }, meta: { pagination } }` with **no `deleted_ids` field anywhere**. `grep deleted_ids` over both files returns nothing.

Stock-levels needs no tombstone by design: a stock row hitting zero is a *quantity update on a surviving row*, not a deletion. Variants, by contrast, are genuinely removed (soft-delete) and deactivated, so the variant feed MUST carry a real tombstone. The actual precedent the plan should copy is **`/products` / `pullProductsCore`** (`apps/pos/src/lib/sync/syncService.ts:528-612`), which accumulates `deleted_ids` across pages and cascades `deleteProducts` + `deleteLocationStockForProducts` + `deleteDistributionForProducts` (`:589-597`).

**Fix:** Rewrite §6.2/§6.4 to mirror **`/products`** for the tombstone mechanics (paginated `deleted_ids` accumulator, applied only after the full page loop completes), while mirroring **`/pos/stock-levels`** only for the `as_of` server-issued cursor + pagination envelope. Be explicit that `incoming`-style wholesale-replace does NOT apply to variants.

### HIGH-2 — `deleted_ids` must capture BOTH soft-delete AND `is_active=false`, but the backend read path that exists captures NEITHER for the delta case
The spec (§6.2, testing item 2) correctly *states* `deleted_ids` = "soft-deleted/deactivated since cursor". But there is **no existing reader** that does this — `ProductVariantController@index` (`apps/api/.../ProductVariantController.php:37-55`) returns ALL variants (active and inactive — the `is_active` filter is done **client-side** in `fetchProductVariants`, `apps/pos/src/api/variantApi.ts:62-65`) and excludes soft-deleted via the default Eloquent scope. So a new `PosVariantQueryService` must be written from scratch with three non-obvious behaviors the plan must pin:

1. **Soft-delete tombstone:** query `ProductVariant::onlyTrashed()->where('deleted_at','>',$cursor)` to find rows soft-deleted since the cursor and emit their ids into `deleted_ids`. `deleted_at` is a real column (`...create_product_variants_table.php:30 softDeletes()`).
2. **Deactivation tombstone:** a variant flipped `is_active=false` is NOT soft-deleted, so it survives the default scope and `deleted_at` is null. It must ALSO land in `deleted_ids` (the device should evict it so the picker/scan never surface it). Detect via `is_active=false AND updated_at > cursor`. **This is the easy one to miss** and the spec only names it in prose — the plan must make it a first-class test.
3. **Reactivation:** symmetric inverse — a variant flipped back to `is_active=true` must come back in the *upsert* list (`updated_at > cursor`), not be stranded as evicted. The upsert query therefore must NOT pre-filter `is_active=true` server-side in delta mode (otherwise a reactivation never re-syncs). Decide: either (a) send all `updated_at>cursor` rows (active + inactive) and let the device store `is_active` and filter at read time, or (b) split active→upsert / inactive→deleted_ids. Option (a) is simpler and matches the local schema (the v53 table has an `is_active` column per §6.3).

**Fix:** Specify the exact query set (active-since-cursor → upsert; trashed-since-cursor → deleted_ids; deactivated-since-cursor → deleted_ids) and add the deactivation + reactivation round-trip as explicit backend tests. Note the soft-delete `deleted_at` watermark and the `is_active` `updated_at` watermark may diverge — both must be `> cursor`.

### HIGH-3 — `resolveBarcode` does NOT filter `is_active`, and is scoped by `company_id` while the barcode UNIQUE index is per-`tenant_id` — a deactivated variant will scan-resolve and auto-add
Verified `EloquentProductVariantRepository::findByBarcode` (`apps/api/.../EloquentProductVariantRepository.php:18-23`): it filters `barcode` + `company_id` only — **no `is_active` filter, no `whereNull(deleted_at)` beyond the default scope.** Two consequences for the new scan-to-variant tier (§6.6), which the spec says auto-adds with no confirmation (Decision E):

1. **Deactivated variant auto-sells.** Since the local `getVariantByBarcode` (§6.3) and the server fallback both must mirror the catalog's "active only" rule, the local repo query MUST add `WHERE is_active = 1`. Otherwise a discontinued size scans straight into the cart with zero taps and no error. The picker already protects against this (client-side `is_active` filter), but the *scan auto-add path bypasses the picker entirely* — this is a new, sharper exposure than today.
2. **Scope mismatch.** The barcode UNIQUE index is `(tenant_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL` (`...create_product_variants_table.php:40-41`) — i.e. unique **per tenant**. But `findByBarcode` filters by **company_id**. In a multi-company tenant, the same barcode cannot exist twice tenant-wide, so company-scoping is *stricter than uniqueness* and is correct for isolation — but the new `/pos/variants` feed and the local `getVariantByBarcode` must use the **company** scope consistently (the local DB is already per-company via `getDatabase(companyId)`), and the plan should note the tenant-unique/company-query asymmetry so a future cross-company barcode reuse (allowed by the index) doesn't silently resolve the wrong company's variant offline.

**Fix:** Local `getVariantByBarcode` filters `is_active = 1`. The server `/pos/variants` feed only upserts active variants to the device (see HIGH-2). Add a test: scanning a deactivated variant's barcode → miss (or picker), never auto-add.

---

## MEDIUM

### MED-1 — Inserting a `'variant-hit'` result kind into `resolveScannedCode` ripples into 3 consumers; the spec under-specifies the contract change
`ResolveScannedCodeResult` is a 3-arm union (`apps/pos/src/lib/scan/resolveScannedCode.ts:64-67`). Adding `{ kind: 'variant-hit'; product; variant }` is a discriminated-union widening that forces updates at every `switch`/`if` on `result.kind`:
- `HomePage.handleProductBarcode` (`apps/pos/src/pages/HomePage.tsx:407-418`) handles `miss`/`choose`/`hit` — needs a `variant-hit` arm calling `addItemGated(product, { variant })` + toast. **Verified it does not exist yet.**
- The **LRU cache** (`scanResolutionCache`) stores `POSProduct` only (`setCachedScan(code, product, companyId)`). A variant-hit cannot be represented in the current cache value shape. Spec §6.6 is silent on caching variant-hits. **Recommendation:** either extend the cache value to `{ product, variant? }`, or (simpler, lower-risk) **do NOT cache variant-hits in Tier 0** — accept the one extra indexed SQLite lookup on repeat variant scans (it is O(1) on `idx_product_variants_barcode`). Document the choice; silently reusing `setCachedScan` would cache the parent product and re-introduce the base-product bug on the second scan.
- `addProductToCartWithToast` early-returns when `autoAddToCart` is off (`:337-338`). The variant-hit path must respect the same `autoAddToCart` scanner setting for behavioral parity.

**Fix:** Spell out the full union change, the HomePage `variant-hit` arm, the `autoAddToCart` gate, and the explicit decision to not cache variant-hits (or to widen the cache). Add the `homePageIngressPin.test.ts`-style structural guard so the new arm routes through `addItemGated`, not a raw `cart.addItem`.

### MED-2 — Scan tier ordering: a code that is BOTH a product barcode and a variant barcode
Spec §6.6 leaves ordering as an open question (§10) and proposes "prefer the variant, or surface the chooser". Concrete risks against the real resolver:
- Tier 1 (in-memory products) and Tier 2 (SQLite `getProductsByBarcode`) run BEFORE any variant lookup today (`resolveScannedCode.ts:111-137`). If you query variants only *after* a product miss, a product whose `products.barcode` collides with some variant's barcode will auto-add the **product** and never reach the variant tier — re-creating a subtle variant-scan miss.
- If you query variants *first/alongside* and a product also matches, "prefer variant" silently shadows a legitimately-scanned parent product.

The safest contract: in the SAME SQLite tier, run both `getProductsByBarcode` and `getVariantByBarcode`. If exactly one of the two matches → resolve it (`variant-hit` or `hit`). If BOTH match (genuinely ambiguous) → surface `BarcodeChooserModal` rather than auto-resolving either, consistent with the existing collision UX (`:119-124,135-137`). The chooser currently only renders `POSProduct[]` candidates (`BarcodeChooserModal` `onPick(product)` at `HomePage.tsx:1543-1572`), so a mixed product+variant chooser is a non-trivial UI change — if you don't want to extend the chooser now, document "prefer variant, log the collision" as the v1 rule and ticket the chooser extension.

**Fix:** Lock the single-tier dual-query ordering and the both-match policy in the spec, and note the `BarcodeChooserModal` only-accepts-products limitation.

### MED-3 — Picker rewire vs React Query semantics: cold-start (online, not-yet-synced) and offline-no-cache are under-specified
`useProductVariants` is a React Query hook exposing `isLoading`/`isError`/`data` (`apps/pos/src/hooks/useProductVariants.ts`), consumed by `VariantPickerModal` which branches `isLoading → isError → list` (`apps/pos/src/components/pos/VariantPickerModal.tsx:32,66-81`). Making it "local-first" changes the state machine:
- **Cold start (online, variants table empty for this product):** local read returns `[]`. `ProductVariantStockView` renders the empty-state `variants.empty` message (`ProductVariantStockView.tsx:33-36`) — which would look like "this product has no variants" rather than "loading". The spec (§6.5, test 6) says fall back to a live fetch, but the modal must distinguish "synced-and-genuinely-empty" from "not-yet-synced" — those render identically today. Needs a `hasSynced`/`isLoading` signal from the rewired hook.
- **Offline + no cache:** spec wants a "connect to load variants" message; `VariantPickerModal` currently has only `isError` (red `variants.loadError`). New copy + a new i18n key are required (`variants.offlineNoCache` or similar) — flag for the i18n rule (CLAUDE.md #11). The spec says the modal is "unchanged"; it is NOT — at minimum the empty/error branches change.

**Fix:** Define the rewired hook's return contract (e.g. `{ variants, isLoading, source: 'local'|'live', isOfflineNoCache }`) and enumerate the three picker states (synced-empty / loading-cold / offline-no-cache) with their i18n keys. Acknowledge `VariantPickerModal` is not literally unchanged.

### MED-4 — `ProductVariantData` includes `tenant_id`/`company_id`/`cost_override`; the device feed should drop them
The DTO (`apps/api/.../ProductVariantData.php:22-37`) carries `tenant_id`, `company_id`, and `cost_override`. The local v53 schema (§6.3) correctly omits all three, and `variantApi.ts` already drops `cost_override` at the boundary (comment at `:5-10`). But the spec says the new endpoint "reuses `ProductVariantData`" — if it serializes the DTO verbatim, the device pulls tenant/company/cost on every variant on every sync tick (bandwidth + a faint cost-leakage smell, since `cost_override` is advisory-internal). Minor, but for a feed pulled every 60s across the full catalog it adds up.

**Fix:** Either add a slim `PosVariantData` projection (id, product_id, sku, barcode, name_suffix, is_default, is_active, display_order, price_override, image_url, updated_at) or explicitly note the device discards the extra fields. Prefer the slim projection for the sync feed.

### MED-5 — `updated_at` watermark precision and the empty-tombstone-page cursor advance
`pullProductsCore` writes the cursor only after the full loop AND only when `totalPulled > 0 || deletedIdsAccumulator.length > 0` (`syncService.ts:599-600`). `pullLocationStock` instead always advances on a server `as_of` (`:959-961`). For variants, copy the **`as_of` server-issued watermark** (clock-skew-safe, per `stockApi.ts:22-30` and the `STOCK_CURSOR_KEY` rationale at `syncService.ts:797-804`) — do NOT use device time. If the variant feed instead reuses an `updated_at`-of-last-row cursor, two rows sharing the same millisecond `updated_at` straddling a page boundary can drop the second; the `as_of` snapshot approach avoids this. The spec says "as_of" in the envelope (§6.2) — good — but §6.4 mentions a `variants_as_of` cursor without pinning that it is the SERVER value. Make that explicit.

---

## LOW

### LOW-1 — A product losing ALL its variants
If `has_variants` flips false server-side (all variants soft-deleted), the device gets `deleted_ids` for the variants AND a product upsert with `has_variants=false`. Order matters: if the product upsert (clearing `has_variants`) and the variant tombstone arrive in different sync sub-steps, there's a transient window where `has_variants=true` but the local variant table is empty → tile tap opens an empty picker. Low impact (self-heals next tick; the empty-state message renders), but worth a sentence: rely on `has_variants` from the product feed as the gate, and treat empty-local-variants as "not yet synced / none" gracefully (ties to MED-3).

### LOW-2 — Barcode index performance claim is fine, but note NULL barcodes
`CREATE INDEX idx_product_variants_barcode ON product_variants(barcode)` (§6.3) indexes NULLs too. Most variants may have NULL barcode (barcode is nullable, `...create_product_variants_table.php:21`). SQLite handles this fine and `getVariantByBarcode` should `WHERE barcode = ? AND barcode IS NOT NULL` (or just `= ?`, which never matches NULL) — just don't let a scanned empty string match NULL-barcode rows. The existing scan guard rejects codes `< 2 chars` (`resolveScannedCode.ts:62,87-89`), so this is already mostly covered; note it for the local query.

### LOW-3 — Cross-location `variant_label` workaround removal is correctly deferred
§6.7/§10 propose dropping the `product_stock_distribution_cache.variant_label` workaround (migration v52, `migrations.ts:1612-1624`). Verified that column exists and is populated from the cross-location cache. Leaving it as a tracked follow-up is the right call — removing it touches `crossLocationStockRepository` and is out of scope for Spec A. No action; just confirming the deferral is sound.

### LOW-4 — `display_order` tie-break determinism
Local `getVariantsForProduct` orders by `display_order` (§6.3). The server `listForProduct`/`index` also orders by `display_order` only (`ProductVariantController.php:49`, `EloquentProductVariantRepository.php:43`). With equal `display_order` the order is engine-dependent. Harmless for a flat picker, but if Spec C adds label printing keyed on row order, pin a secondary sort (`display_order, id`) now in the local query for stable output.

### LOW-5 — Tombstone for a variant whose product is also tombstoned (double cascade)
When `pullProductsCore` tombstones a product it cascades `deleteLocationStockForProducts` + `deleteDistributionForProducts` (`syncService.ts:589-597`). The new `pullVariants` must also be cascaded on product tombstone (the spec says so in §6.4: "cascade-evict on product tombstone alongside the existing `deleteLocationStockForProducts`"). Verify ordering: product tombstone cascade should call `deleteVariantsForProducts(ids)` in the SAME deleted-ids block, so a deleted product's variants never linger and scan-resolve to a ghost. Already in the spec — just make it a test (mirror the existing cascade tests).

---

## Verified-correct claims (no action)

- Bug location and nature (HomePage `addProductToCartWithToast` vs `handleAddToCart`) — **real**, evidence above.
- v53 is the next migration version — **correct** (latest is v52).
- Offline variant pricing via `price_override` — **already works** (`cartStore.ts:278-282`).
- Fiscal payload already signs variant identity — **confirmed** (`SaleReceiptV2Payload.ts`).
- Variant-grain offline stock gate already exists — **confirmed** (`cartIngress.ts` + `gateStockForAdd`).
- `location_stock` variant rows are keyed by the variant UUID (not the `''` product-grain sentinel) — **confirmed** (`locationStockRepository.ts:88-90` `vid()`; gate passes `variant.id`). The join lines up: a picked/scanned variant gates against `location_stock WHERE variant_id = <uuid>`.
- POS route group has `EnforceTokenTenantClaim` + `SetPermissionsTeam` + `api` + `auth:sanctum` — **confirmed** (`POS/routes.php:36`); no new permission needed since `pos.operate_terminal` Gate already guards stock-levels (`PosStockLevelController.php:58`) — the plan should reuse that same Gate, which the spec omits to name.
- `apiGetRaw` / `getDatabase` exist and preserve `meta.pagination` — **confirmed** (`stockApi.ts:14,45`).

## Two omissions the plan should add

1. **Gate authorization:** `/pos/stock-levels` calls `Gate::authorize('pos.operate_terminal')` (`PosStockLevelController.php:58`). The spec says "no new permission" (Decision G) but never says which existing Gate `/pos/variants` enforces. Reuse `pos.operate_terminal`.
2. **Terminal-scoping:** stock-levels resolves location from `terminal_id` server-side (trust boundary). Variants are NOT location-scoped (catalog is company-wide), so `/pos/variants` should NOT require `terminal_id` — it is company+tenant scoped via `CompanyContext` only. The spec implies this but should state it, since copying the stock-levels controller verbatim would wrongly demand a `terminal_id`.
