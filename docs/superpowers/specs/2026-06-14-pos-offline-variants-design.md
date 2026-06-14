# POS Offline Variant Support — Design Spec (Spec A)

- **Date:** 2026-06-14
- **Branch:** `feat/pos-offline-variants` (forked off `dev` at `a9d483def`)
- **Status:** Draft (rev2 — incorporates adversarial review `docs/superpowers/reviews/2026-06-14-pos-offline-variants-adversarial-review.md`) — pending user review
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
| E | Scan auto-add | **Auto-add the exact variant** with no confirmation step (owner-approved). **Only `is_active` variants** auto-resolve (see I). |
| F | Sync | **Dedicated `GET /pos/variants` endpoint.** Delta **cursor** mechanics mirror `/pos/stock-levels` (server `as_of`); **tombstones** mirror the `/products` pull (`pullProductsCore` + `deleted_ids`) — **NOT** stock-levels, which has no tombstone. **Company-scoped, no `terminal_id`** (catalog is company-wide, unlike per-terminal stock). |
| G | Permission/auth | **No new permission.** Reuse `Gate::authorize('pos.operate_terminal')` (the existing POS sync gate) + standard POS route-group middleware. |
| H | 2D/QR | Opaque-string match against the barcode index in v1; GS1 parsing deferred to C. |
| I | Active-only safety | Local scan resolution + picker show **`is_active = 1` only**. A deactivated/soft-deleted variant must never scan-resolve-and-auto-add (would bypass the picker and sell a withdrawn SKU). |

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

- Mounted in the existing POS route group (`['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`). **`Gate::authorize('pos.operate_terminal')`** — no new permission.
- **Company-scoped, NO `terminal_id`** (rev2). The variant catalog is company-wide; unlike `/pos/stock-levels` (which requires a `terminal_id` to resolve the device's location), this feed must not require or accept a terminal. Tenant + company resolved via `CompanyContext`.
- Query params: `updated_since` (optional ISO), `page` (pagination — a **full snapshot must paginate**, like `/products`). 
- Returns `{ data: { variants: PosVariantData[], deleted_ids: string[], as_of }, meta: { pagination } }`:
  - **No cursor** → full snapshot of all `is_active` variants for the company (paginated; `deleted_ids` empty).
  - **`updated_since` given** → `variants` = rows with `updated_at > cursor` (this naturally includes **reactivations**, since flipping `is_active` back to true bumps `updated_at`); `deleted_ids` = **tombstones** = variant ids that became unsellable since the cursor, i.e. `deleted_at > cursor` (soft-delete) **OR** `is_active = false AND updated_at > cursor` (deactivation). A row appears in **exactly one** of `variants` / `deleted_ids`.
  - `as_of` = **server** timestamp watermark for the next pull's `updated_since` (clock-skew-safe; never device time).
- **Slim device DTO `PosVariantData`** (rev2 — do NOT reuse the full `ProductVariantData`, which carries `tenant_id`/`company_id`/`cost_override` the device must not see): `{ id, product_id, sku, barcode, name_suffix, price_override, image_url, is_default, display_order, updated_at }`. Prices as decimal **strings**.
- New `PosVariantQueryService` in the Catalog module (constructor-injected), exposing the snapshot + delta + tombstone queries above. Tombstone capture has no existing precedent here — it must be implemented and tested explicitly (`onlyTrashed`/`withTrashed` for soft-deletes + the `is_active=false` arm).

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

`syncService.ts`: add `pullVariants(db)`. The **delta/cursor/pagination** loop mirrors `pullLocationStock` (full on first/claim, delta on tick, server `variants_as_of` cursor). The **tombstone** handling mirrors `pullProductsCore` (the products pull is the real tombstone precedent — stock-levels has none): apply `deleted_ids` via `deleteVariantsById(ids)` after each delta. **Two distinct deletion paths:** (a) `deleted_ids` from the feed (variant deactivated/soft-deleted), and (b) **cascade** on product tombstone — when `pullProductsCore` removes a product, also `deleteVariantsForProducts([...])` (alongside the existing `deleteLocationStockForProducts`). New `variantSyncApi.fetchVariants(params)` using `apiGetRaw` (preserves `meta.pagination`).

### 6.5 POS — picker rewire

`useProductVariants(productId)` becomes local-first: read `getVariantsForProduct` (active only) from SQLite (offline-capable); if online, kick a background refresh that upserts and re-reads. Map the local row → `POSProductVariant` (the existing type).

**The hook contract changes (rev2 — not literally "unchanged").** The old shape is React-Query `{ data, isLoading, isError }`; the new local-first hook must expose enough to distinguish three states the modal now needs:
- **have local variants** → render the list (works offline);
- **none locally + online** → cold-start: live-fetch fallback (loading) then upsert;
- **none locally + offline** → "connect to load variants" message (new i18n key).

So `VariantPickerModal` / `ProductVariantStockView` get a **small additive change** (the offline-no-cache state + key); their core flat-list + per-variant-stock rendering (stock from `location_stock`) is otherwise the same. Note: a "synced but legitimately empty" product and a "not-yet-synced" product must be distinguishable (e.g. a `hasSyncedOnce`/source flag) so we don't show "connect to load" for a product that genuinely has zero active variants.

### 6.6 POS — scan-to-variant

`resolveScannedCode.ts`: add a **variant-barcode tier** and a new result kind `{ kind: 'variant-hit', product, variant }`. Concretely:
- **Local SQLite tier:** in the same tier as the existing product `getProductsByBarcode`, also run `getVariantByBarcode(code)` (one extra indexed query). `getVariantByBarcode` **MUST filter `is_active = 1`** (rev2 / HIGH-3) — a deactivated/withdrawn variant must never resolve-and-auto-add, which would bypass the picker and sell a withdrawn SKU. (The backend `findByBarcode` does *not* filter active and is company- vs the tenant-scoped unique index — so we do not rely on it; the local query owns this guard.)
- **Collision:** in practice a scanned code is a product barcode XOR a variant barcode. If both match (data error), **prefer the variant** (more specific) for v1. `BarcodeChooserModal` currently renders **products only**, so a genuine product-vs-variant collision is out of its scope in v1 — we prefer-variant and log; extending the chooser to mixed results is a follow-up.
- **Result-kind ripple (rev2 / MED-1):** adding `'variant-hit'` touches the `result.kind` switch in `HomePage` (the `hit`/`choose`/`miss` handling) and the `autoAddToCart` gate. **Do NOT cache a variant-hit in the Tier-0 recent-scan LRU** — that cache stores `POSProduct` only and can't represent a variant; caching it would lose the variant on a repeat scan. The variant lookup is an indexed SQLite hit, so skipping the LRU is cheap.
- `HomePage` handles `variant-hit` by `addItemGated(product, { variant })` + success toast (reusing the variant-grain stock gate + variant pricing already in `cartStore`).
- **Bug fix:** `addProductToCartWithToast` (the auto-add path) must mirror `handleAddToCart`: if the resolved product `has_variants` and we did NOT arrive via a `variant-hit`, open `VariantPickerModal` instead of adding the base product. (Non-double-fire: a `variant-hit` adds directly and never reaches this branch; a plain product `hit` with `has_variants` opens the picker.)

### 6.7 Cross-location knock-on

Once variants are local, the cross-location section's deferred offline-variant fallback (Spec: cross-location §9) is satisfied directly: the variant selector reads the local catalog offline. Remove the now-redundant `variant_label`-from-cache workaround when convenient (tracked, not required by this spec).

## 7. Offline-first / device-authority

Read-only catalog cache, per-device, refreshed on sync — no writes, no fiscal/shift interaction, no device-authority reconciliation. Selling a variant already works offline (cart + signed fiscal carry it); this spec only makes *selecting* it work offline. Stock counts shown are last-synced (same contract as all POS stock).

## 8. Testing (TDD)

### Backend (PHPUnit, scoped with `--filter`)
1. `/pos/variants` full snapshot returns `is_active` variants for the company (tenant + company scoped; another tenant's/company's excluded); pagination works for a large snapshot.
2. Delta (`updated_since`) returns only `updated_at > cursor` variants; `as_of` is the server watermark.
3. **Tombstone capture (all three triggers):** `deleted_ids` includes a **soft-deleted** variant (`deleted_at > cursor`) AND a **deactivated** variant (`is_active=false, updated_at > cursor`); a **reactivated** variant (`is_active` flipped back true) appears in `variants`, NOT `deleted_ids`; a row is in exactly one list.
4. Endpoint is **company-scoped with no `terminal_id`** (does not require/accept it). Reuses `pos.operate_terminal`; `EnforceTokenTenantClaim` mismatch → 401.
5. The slim `PosVariantData` does NOT leak `tenant_id`/`company_id`/`cost_override`; `price_override` is a decimal string.

### Frontend (Vitest)
1. `variantRepository`: upsert/get-by-product (active, ordered by display_order)/`getVariantByBarcode` (**`is_active=1` only**)/delete-by-id/delete-for-products; decimal strings preserved; v53 migration runs in the real in-memory harness.
2. `pullVariants`: full vs delta; applies `deleted_ids` (delete-by-id) AND cascades on product tombstone (`deleteVariantsForProducts`); uses the server cursor.
3. `useProductVariants` reads local-first (offline returns cached active variants, no network); online triggers a background refresh; distinguishes **synced-empty** (no message) from **not-yet-synced offline** ("connect to load variants").
4. `resolveScannedCode`: a variant-barcode scan returns `variant-hit` with the exact variant and does NOT fall through to the base product; **a deactivated variant's barcode does NOT resolve (no auto-add)**; a product-barcode scan for a `has_variants` product surfaces the picker, not a base-product add; a `variant-hit` is **not written to the recent-scan LRU**.
5. `HomePage` scan handling: `variant-hit` → `addItemGated(product, { variant })`; parent-barcode + `has_variants` → picker (no double-fire); unknown → existing miss toast.
6. Cold start (online, variants not yet synced) → picker live-fetch fallback; offline + no cache → "connect to load variants".

### Quality gates
- Backend: PHPStan L8 clean on new code; Pint. Never run the full PHPUnit suite (scope with `--filter`).
- Frontend: `pnpm typecheck`, `pnpm lint` (token + no-parsefloat), scoped Vitest.

## 9. Rollout

No flag needed — this is a strict capability addition (offline + scan) on top of existing online behavior. Variant sync is additive; tenants without variants get empty pulls. Ships safely; manual Tauri smoke for scan-to-variant + offline picker before relying on it in a demo.

## 10. Open questions / residual items (to handle during the plan)

- **`display_order` tie-break:** order the local picker by `(display_order, id)` for deterministic ordering when `display_order` collides.
- **NULL-barcode rows:** variants may have `barcode = NULL`; ensure `getVariantByBarcode` never matches NULL/empty scans (guard empty string) and the barcode index tolerates NULLs.
- **Product-loses-all-variants** (transient): a product whose variants are all removed should fall back to non-variant behaviour or show "no active variants"; covered by the synced-empty vs not-synced distinction (§6.5).
- Whether to **drop** the cross-location `variant_label`-from-cache workaround in this spec or as a follow-up (low priority — the knock-on §6.7 makes it redundant either way).
- Backend reader placement: `PosVariantQueryService` in the Catalog module vs a POS-module reader — decide against the real module boundaries (Catalog owns variants; the POS module consumes via a contract) during the plan.

> Adversarial review verified-correct (no action needed): the variant-scan bug is real; v53 is the right migration version; offline variant **pricing** (`cartStore` resolves `variant.price_override`), **fiscal** variant signing (`SaleReceiptV2Payload`), offline **variant-grain stock gating**, and the `location_stock` variant-UUID **join** all already work; route-group middleware, `apiGetRaw`, and `getDatabase` are as described.
