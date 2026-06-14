# POS Offline Variant Support — Design Spec (Spec A)

- **Date:** 2026-06-14
- **Branch:** `feat/pos-offline-variants` (forked off `dev` at `a9d483def`)
- **Status:** Draft — pending user review
- **Author:** brainstorming session (Claude)
- **Program:** This is **Spec A** of a 3-spec variant program ("close the variant gap once and for all"):
  - **A — POS offline variant support** (this doc): on-device catalog + offline picker + offline scan-to-variant.
  - **B — Authoring & onboarding polish** (web admin): reusable option-set UX, combinatorial warnings, per-combination delete, barcode-uniqueness enforcement, catalog onboarding step. *(separate spec)*
  - **C — Label & QR strategy**: per-variant barcode/QR label printing, GS1 Digital Link / Sunrise-2027 readiness. *(separate spec, depends on A+B)*

---

## 1. Problem & Motivation

The T2 product-variant model on `dev` is mature and industry-aligned — a full options→values→combinations model (`product_attributes` / `product_attribute_values` / `product_variant_attribute_values`), per-variant `barcode` (unique per tenant), a matrix generator, web-admin authoring, and variant-awareness across stock, transfers, batches, document lines, price lists, and the **signed fiscal receipt** (`SaleReceiptV2` carries `variant_id`/`name`/`sku`).

**But the POS is online-only for variants.** The variant *catalog* is fetched live (`GET /products/{id}/variants`) and is **not** persisted to local SQLite; only variant-grain *stock quantities* are cached (`location_stock.variant_id`). Consequences:
- A cashier **cannot pick a variant offline** — the picker fires a network request and fails when offline.
- There is **no scan-to-variant** — scanning a variant barcode doesn't resolve to the variant offline, and scanning a variant-bearing product is buggy (it tries to add the base product instead of opening the picker).

Industry research (Shopify/Square/Lightspeed/Clover/Toast/Odoo + GS1) is decisive: in real apparel/footwear/grocery retail, **scanning a per-variant barcode is the primary path** and the on-screen picker is the fallback. Leaders keep the **full variant catalog + a barcode index on-device** for O(1) offline resolution. Square resolves a scan **directly** to the variation; Shopify POS's parent-product detour is the #1 complaint — we build it the Square way.

## 2. Goals

- Variant **selling works fully offline**: the picker reads the on-device catalog; selling-once-picked already works (cart + signed fiscal already carry the variant).
- **Scan-to-variant**: a scanned variant barcode resolves **directly** to the exact variant offline and auto-adds it to the cart (zero extra taps).
- Fix the **variant-scan bug**: scanning a variant-bearing *parent* product opens the picker instead of adding the base product.
- Resolve the cross-location feature's deferred offline-variant follow-up for free.

## 3. Non-Goals (deferred to Spec B / C)

- **Option-axis chip picker** (tap Size → tap Color). Requires syncing the axis tables; v1 uses a **flat variant list** (the register-level norm at Shopify/Lightspeed). The on-device schema leaves room to add axes later.
- **GS1 Digital Link / Application-Identifier parsing.** v1 treats a scan as an opaque string matched against the barcode index (so an internal QR encoding a variant barcode/SKU "just works"). Full GS1 2D parsing → Spec C.
- **QR/barcode label printing** → Spec C.
- **Authoring / onboarding** improvements → Spec B.
- No change to the backend variant model, the matrix generator, or the fiscal payload (all already variant-aware).

## 4. Key Decisions (locked during brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| A | On-device storage | **Dedicated indexed `product_variants` SQLite table** (+ barcode index). Not a JSON blob (can't index barcodes → no offline scan). Axis tables deferred. |
| B | Picker layout | **Flat variant list** (existing `VariantPickerModal` / `ProductVariantStockView`), rewired to read local-first. Axis chips deferred. |
| C | Scan resolution | **Variant-barcode tier added to the existing tiered resolver**; a variant-barcode hit returns the exact variant. **Square-style**, no parent detour. |
| D | Parent-barcode scan on a variant product | **Open the picker** (fixes the current bug), matching tile-tap behavior. |
| E | Scan auto-add | **Auto-add the exact variant** with no confirmation step (owner-approved). |
| F | Sync | **Dedicated `GET /pos/variants` delta endpoint** mirroring `/pos/stock-levels` (full/delta/tombstone). |
| G | Permission | **None new** — variant catalog is core catalog (like products). Standard POS sync middleware. |
| H | 2D/QR | Opaque-string match against the barcode index in v1; GS1 parsing deferred to C. |

## 5. Current-State Findings (reconnaissance)

### Backend (`apps/api`)
- `product_variants` (Catalog module) — `variant_code, sku, barcode (unique per tenant, partial-unique WHERE barcode IS NOT NULL), name_suffix, is_default, is_active, display_order, price_override (decimal 15,4), cost_override (advisory), image_url`, soft deletes. Options modeled via `product_attributes` / `product_attribute_values` / `product_variant_attribute_values`.
- `ProductVariantData` DTO (`Catalog/Application/DTOs`) is the wire shape already returned by `GET /products/{id}/variants` (the `ProductVariantController@index`).
- `ProductVariantLookup` contract (`findById/findByBarcode/findBySku/listForProduct`) + `ProductVariantService::resolveBarcode(barcode, companyId)` already exist server-side.
- POS sync pull (`/pos/sync/pull`, and the dedicated `/pos/stock-levels`) does **not** currently include variants.

### POS (`apps/pos`)
- **No local variant table.** `src/lib/db/migrations.ts` latest version is **52** (cross-location cache). Only `location_stock.variant_id` carries variant *stock*.
- Variant catalog: `useProductVariants` → `fetchProductVariants` → `apiGet('/products/{id}/variants')` (live, React-Query 30s staleTime, **no persistence, no offline fallback**).
- Sell-once-picked works offline: cart line carries `variant_id/variant_name/variant_sku` (`types/cart.ts`), variant-aware dedup + pricing in `cartStore`, and `SaleReceiptV2Payload` signs `variant_*` into the canonical bytes.
- **Scan flow** (well-architected, reuse it):
  - `useBarcodeScanner` (keyboard-wedge, emits raw string) → `HomePage.handleBarcodeScan` → `dispatchScan` (routes QR **receipt tokens**; else falls through) → `handleProductBarcode` → `resolveScannedCode.ts`.
  - `resolveScannedCode` tiers: **recent-scan LRU → in-memory `productStore.products` → local SQLite `getProductsByBarcode` (indexed `products.barcode`) → server (5s) → miss**. Returns `hit` (auto-add) / `choose` (`BarcodeChooserModal`) / `miss` (toast).
  - **Bug:** `HomePage.addProductToCartWithToast` calls `addItemGated(product)` without checking `has_variants` — a scanned variant product is added as the base product instead of opening the picker. (Tile-tap `handleAddToCart` does it right.)
  - Sync patterns to mirror: `locationStockRepository` (decimal-string, `vid()` sentinel) + the `/pos/stock-levels` delta pull (full/delta/`as_of` cursor/tombstone) in `syncService.ts`.

## 6. Architecture

### 6.1 Data flow (offline-first)

```
SYNC (online, every 60s + full on claim/boot):
  GET /pos/variants?updated_since=<cursor>  → upsert product_variants (+ tombstone deletes)

PICK (offline-capable):
  tap product tile → has_variants? → VariantPickerModal
    → useProductVariants reads product_variants LOCAL-FIRST (instant, offline)
    → (online) background refresh
    → pick → addItemGated(product, { variant })  [variant-grain stock gate, already exists]

SCAN (offline-capable, PRIMARY path):
  scan barcode → resolveScannedCode tiers:
    … → VARIANT-barcode tier (product_variants.barcode, indexed) → exact variant → AUTO-ADD
    … → product tier → if product.has_variants → open VariantPickerModal (bug fix)
                       else → add product
```

### 6.2 Backend — `GET /api/v1/pos/variants`

- Mounted in the existing POS route group (`['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`). No new permission.
- Query: `updated_since` (optional ISO), `page` (pagination like stock-levels). Tenant + company scoped via `CompanyContext`.
- Returns `{ data: { variants: ProductVariantData[], deleted_ids: string[], as_of }, meta: { pagination } }` — same envelope shape as `/pos/stock-levels` (full snapshot on no-cursor; delta when `updated_since` given; `deleted_ids` = soft-deleted/deactivated since cursor). Quantities/prices as decimal **strings**.
- New `LocationStockReader`-style reader or a small `PosVariantQueryService` in the Catalog module (constructor-injected). Reuses `ProductVariantData`.

### 6.3 POS — local table (migration v53)

```sql
CREATE TABLE IF NOT EXISTS product_variants (
  id TEXT PRIMARY KEY,
  product_id TEXT NOT NULL,
  sku TEXT NOT NULL,
  barcode TEXT,
  name_suffix TEXT NOT NULL DEFAULT '',
  price_override TEXT,            -- decimal string or NULL
  image_url TEXT,
  is_default INTEGER NOT NULL DEFAULT 0,
  display_order INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_product_variants_product ON product_variants(product_id);
CREATE INDEX IF NOT EXISTS idx_product_variants_barcode ON product_variants(barcode);
```

`variantRepository.ts` (mirror `locationStockRepository`): `upsertVariants`, `getVariantsForProduct(productId)` (active, ordered by display_order), `getVariantByBarcode(barcode)`, `deleteVariantsForProducts(ids)`. Decimal strings preserved; never `parseFloat`.

### 6.4 POS — sync wiring

`syncService.ts`: add `pullVariants(db)` mirroring `pullLocationStock` (full on first/claim, delta on tick, `variants_as_of` cursor, tombstone via `deleteVariantsForProducts`). Cascade-evict on product tombstone alongside the existing `deleteLocationStockForProducts`. New `stockApi`-style `variantSyncApi.fetchVariants(params)` using `apiGetRaw` (preserves `meta.pagination`).

### 6.5 POS — picker rewire

`useProductVariants(productId)` becomes local-first: read `getVariantsForProduct` from SQLite (synchronous-feeling, offline); if online, kick a background refresh that upserts and re-reads. Map the local row → `POSProductVariant` (the existing type). `VariantPickerModal` / `ProductVariantStockView` are **unchanged** (same props, same flat list + per-variant stock from `location_stock`). The online-only error branch is replaced by: local data if present, else (offline + no cache) a "connect to load variants" message.

### 6.6 POS — scan-to-variant

`resolveScannedCode.ts`: insert a **variant-barcode tier**. After the product in-memory/SQLite tiers miss (or before — see ordering note), query `getVariantByBarcode(code)`. On a hit, return a new result kind `{ kind: 'variant-hit', product, variant }`. `HomePage` handles `variant-hit` by `addItemGated(product, { variant })` + success toast (reusing the variant-grain stock gate).
- **Ordering:** check the variant barcode index in the same SQLite tier as the product lookup (one extra indexed query). A code is either a product barcode or a variant barcode (variant barcodes are unique per tenant); if both somehow match, prefer the variant (more specific) — or surface `BarcodeChooserModal`.
- **Bug fix:** `addProductToCartWithToast` (the auto-add path) must mirror `handleAddToCart`: if the resolved product `has_variants` and we did NOT arrive via a variant-hit, open `VariantPickerModal` instead of adding the base product.

### 6.7 Cross-location knock-on

Once variants are local, the cross-location section's deferred offline-variant fallback (Spec: cross-location §9) is satisfied directly: the variant selector reads the local catalog offline. Remove the now-redundant `variant_label`-from-cache workaround when convenient (tracked, not required by this spec).

## 7. Offline-first / device-authority

Read-only catalog cache, per-device, refreshed on sync — no writes, no fiscal/shift interaction, no device-authority reconciliation. Selling a variant already works offline (cart + signed fiscal carry it); this spec only makes *selecting* it work offline. Stock counts shown are last-synced (same contract as all POS stock).

## 8. Testing (TDD)

### Backend (PHPUnit, scoped with `--filter`)
1. `/pos/variants` full snapshot returns active variants for the company (tenant/company scoped; another tenant's excluded).
2. Delta (`updated_since`) returns only changed variants + `deleted_ids` for soft-deleted/deactivated since the cursor.
3. Decimal `price_override` serialized as string; pagination envelope matches `/pos/stock-levels`.
4. No new permission required (standard POS middleware); `EnforceTokenTenantClaim` mismatch → 401.

### Frontend (Vitest)
1. `variantRepository`: upsert/get-by-product (active, ordered)/get-by-barcode/delete-for-products; decimal strings preserved; v53 migration runs in the real in-memory harness.
2. `pullVariants`: full vs delta vs tombstone (mirror the stock-pull tests).
3. `useProductVariants` reads local-first (offline returns cached variants with no network); online triggers background refresh.
4. `resolveScannedCode`: a variant-barcode scan returns `variant-hit` with the exact variant and does NOT fall through to the base product; a product-barcode scan for a `has_variants` product surfaces the picker, not a base-product add.
5. `HomePage` scan handling: `variant-hit` → `addItemGated(product, { variant })`; parent-barcode + `has_variants` → picker; unknown → existing miss toast.
6. Cold start (online, variants not yet synced) → picker live-fetch fallback; offline + no cache → "connect to load variants".

### Quality gates
- Backend: PHPStan L8 clean on new code; Pint. Never run the full PHPUnit suite (scope with `--filter`).
- Frontend: `pnpm typecheck`, `pnpm lint` (token + no-parsefloat), scoped Vitest.

## 9. Rollout

No flag needed — this is a strict capability addition (offline + scan) on top of existing online behavior. Variant sync is additive; tenants without variants get empty pulls. Ships safely; manual Tauri smoke for scan-to-variant + offline picker before relying on it in a demo.

## 10. Open questions / to confirm during implementation

- **Scan ordering** detail (variant tier before vs alongside product tier) — finalize in the resolver to keep a single indexed SQLite round-trip.
- Whether to **drop** the cross-location `variant_label`-from-cache workaround in this spec or as a follow-up (low priority).
- Backend reader placement: a small `PosVariantQueryService` in Catalog vs extending an existing POS sync service — decide against the real module boundaries during the plan.
