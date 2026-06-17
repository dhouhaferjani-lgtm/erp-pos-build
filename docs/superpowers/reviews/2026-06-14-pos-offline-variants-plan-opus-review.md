# Adversarial Review — POS Offline Variants Implementation Plan

**Plan:** `docs/superpowers/plans/2026-06-14-pos-offline-variants.md`
**Spec:** `docs/superpowers/specs/2026-06-14-pos-offline-variants-design.md` (rev2)
**Reviewer:** Opus 4.8 (1M)
**Branch verified:** `feat/pos-offline-variants` @ `0ac72345` (worktree `apps/erp.pos-xloc-stock`)
**Method:** Every code reference in the plan checked against the actual source on this branch.

---

## VERDICT: **APPROVE-WITH-EDITS**

The architecture is sound and faithfully mirrors the existing `LocationStockReader` / `PosStockLevelController` / `pullLocationStock` patterns. The backend contract, controller, route insertion point, migration version (v53), `variantRepository` column/placeholder counts, the `location_stock` JOIN semantics, the tombstone delta logic, the `resolveScannedCode` integration point, the HomePage bug, the i18n namespace, and the Laravel factory/feature-test harness all check out against real code.

There are **two MEDIUM** issues that would make a literal-following subagent stumble (a wrong test-harness import, and a binding that lands in the "wrong" module's provider), plus several LOW polish items. None are architectural blockers.

---

## HIGH findings

_None._ All load-bearing references (contract shapes, DTO fields, controller mirror, route prefix/group, migration version, JOIN keys, sync symbols, factory) are correct.

---

## MEDIUM findings

### M1 — Frontend repo test imports a non-existent harness `makeTestDb`
**Where:** Plan Task FV1 Step 2 (test file), lines ~505–516.
**Evidence:** The plan writes `import { makeTestDb } from '@/lib/db/__tests__/testDb';` and `db = await makeTestDb()`. There is **no** `testDb` module and **no** `makeTestDb` export anywhere under `apps/pos/src` (`grep -rln makeTestDb` → 0 hits). The real harness — used by the very file the plan cites as the model (`apps/pos/src/lib/db/repositories/__tests__/locationStockRepository.test.ts`) — is:
```ts
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
```
with a `beforeEach`/`afterEach` that constructs a `SqliteTestAdapter`, runs `applyAllMigrations(adapter)`, and tears it down. The plan hedges with `// adjust to the real helper`, but a subagent copying the block verbatim gets an unresolved import and a red test that looks like an env failure.
**Fix:** Replace the `makeTestDb` import/usage in the FV1 (and FV2 if it uses a real DB) test snippets with the `SqliteTestAdapter` + `applyAllMigrations` pattern from `locationStockRepository.test.ts` / `crossLocationStockRepository.test.ts`. State the exact helper paths in the plan so the worker doesn't guess.

### M2 — `PosVariantFeedReader` binding is directed into the **Inventory** provider, not a Catalog provider
**Where:** Plan Task BV1 Step 6 + File Structure ("Modify: a service provider (the one binding `LocationStockReader`)").
**Evidence:** `LocationStockReader::class` is bound in exactly one place:
`apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:29`
```php
$this->app->bind(LocationStockReader::class, LocationStockQueryService::class);
```
The plan's instruction "bind it in the provider that binds `LocationStockReader`" therefore places a **Catalog** service (`PosVariantFeedService`) binding inside the **Inventory** module's provider. That works at runtime but smears module ownership — and the plan's `git add apps/api/app/Providers/*` comment (BV1 Step 8) points at the wrong directory (`app/Providers`), not `app/Modules/Inventory/Providers`, so the worker may commit the binding-less tree and the feature test will fail to resolve `PosVariantFeedReader`.
**Fix:** Bind `PosVariantFeedReader → PosVariantFeedService` in the **Catalog** module's service provider (locate/confirm `app/Modules/Catalog/Providers/*ServiceProvider.php`; if none registers bindings, add the bind there or in the module's existing provider). Correct the `git add` path in Step 8 to the actual provider file edited. Do **not** silently piggyback on `InventoryServiceProvider`.

---

## LOW findings

### L1 — Resolver snippet references a phantom helper `getProductsByBarcodeOrId`
**Where:** Plan Task FV4 Step 3, line ~952.
**Evidence:** `productRepository.ts` exports `getAllProducts`, `getProductByBarcode`, `getProductsByBarcode`, `upsertProducts`, … but **no** `getProductById` and **no** `getProductsByBarcodeOrId` (`grep "export async function" productRepository.ts`). The plan's own prose flags this ("verify it exists; else add … getProductById"), but the inline code calls `getProductsByBarcodeOrId(...)`, a name that exists nowhere — pseudo-code a worker could paste.
**Fix:** Pick one concrete path: resolve the parent from the in-memory snapshot (`deps.products.find(p => p.id === variant.product_id)`) and, for the cold SQLite fallback, add a real `getProductById(db, id)` to `productRepository.ts` (mirror `getProductByBarcode` but `WHERE id = $1`). Replace the `getProductsByBarcodeOrId` token with that real symbol. `ResolveScannedCodeDeps` already exposes `db` + `products`, so both halves are reachable.

### L2 — VariantPickerModal contract change is under-specified
**Where:** Plan Task FV3 Step 4.
**Evidence:** Today (`VariantPickerModal.tsx:32,49`) the hook is consumed as `{ data: variants, isLoading, isError }` and `handleConfirm` guards `if (!variants || selectedVariantId === null)` because `variants` can be `undefined`. The plan's new hook returns `variants` as an **always-array** plus `status` (no `isError`). The plan updates the render branch but says `handleConfirm` is "same as today" — it is not exactly: the `!variants` guard and the `variants ?? []` fallback (line 77) become dead/incorrect against the new always-array contract, and `isError` is gone.
**Fix:** In FV3 Step 4, explicitly instruct: drop the `isError` reference, change `handleConfirm`'s guard to `if (selectedVariantId === null) return;`, and pass `variants` directly (no `?? []`). Otherwise typecheck stays green but the dead guard is confusing.

### L3 — `pullProductVariants` gate skips on both `menu` and `defer`
**Where:** Plan Task FV2 Step 4, `if (gate.decision !== 'standard') return { count: 0 }`.
**Evidence:** `resolveCatalogTenantGate()` returns `decision: 'standard' | 'menu' | 'defer'` (`syncService.ts:662–690`). `!== 'standard'` correctly skips Menu tenants, but it also silently no-ops on `defer` (config-fetch failure) — consistent with treating variants as standard-retail-only, and harmless because the next tick retries. Just note it: a `defer` tick logs nothing for variants (unlike `pullProducts`, which logs a deferral). Acceptable for v1; call it out so it isn't mistaken for a missed pull during debugging.
**Fix (optional):** None required; optionally add a `logSyncOperation(..., 'product_variants', null, 'success', 'deferred')` on the non-standard branch for parity with `pullProducts`.

### L4 — `Number()` on `available` is NOT a new lint risk (confirms a plan claim)
**Where:** Plan "Notes" + FV1 `toNumber`.
**Evidence:** The codebase already does `Number()` on a variant quantity in `apps/pos/src/api/variantApi.ts:28–30` (`toStockQuantity`), and `POSProductVariant.stock_quantity` is typed `number` (`types/product.ts`). So `no-parsefloat-on-money` / `no-hardcoded-step` do not fire on the picker's variant `stock_quantity` path — the plan's mapping is consistent with the existing, lint-clean precedent. **No change needed**; recorded to close the question the brief raised.

### L5 — Feature-test variants need an explicit `barcode` if any case asserts barcode
**Where:** Plan Task BV2 test cases.
**Evidence:** `ProductVariantFactory::definition()` sets `'barcode' => null` by default (`ProductVariantFactory.php:40`). The slim-response test asserts `assertArrayHasKey('barcode', $resp)` (key present, value may be null — fine), but any future scan/resolution assertion that needs a non-null barcode must pass `['barcode' => 'BC1']` to the factory. Worth a one-line note so the worker doesn't get a null-barcode surprise.
**Fix:** Add a note to BV2 Step 1: "set `barcode` explicitly on variants used in barcode assertions; factory default is null."

---

## Confirmed-correct (audited, no action)

- **Migration version v53** — latest in `migrations.ts` is v52 (`create_product_stock_distribution_cache`). v53 is the correct next version. ✔
- **`variantRepository` upsert arithmetic** — INSERT lists 10 columns, 10 `$`-placeholders per row, 10 pushed params, `UPSERT_PARAMS_PER_ROW = 10`. Counted exactly; consistent (mirrors `locationStockRepository`'s 6/6/6 pattern). ✔
- **`location_stock` JOIN** — v50 stores product-grain rows with `variant_id = ''` (sentinel) and variant rows with the variant UUID; PK `(product_id, variant_id)`. The plan's `LEFT JOIN location_stock ls ON ls.product_id = v.product_id AND ls.variant_id = v.id` joins variant rows correctly (`v.id` is the variant UUID; product-grain `''` rows never match, as intended for per-variant stock). ✔
- **`CompanyContext`** — `requireTenantId()` (line 63) and `requireCompanyId()` (line 45) both exist and are used identically by `PosStockLevelController`. ✔
- **`ProductVariant` model** — `SoftDeletes` (so `onlyTrashed()` is available), `updated_at` is a Carbon `@property` with default timestamps (so `?->toIso8601String()` works), casts `is_default`/`is_active`/`display_order`, leaves `price_override`/`cost_override` uncast strings. All DTO field reads valid. ✔
- **Tombstone delta consistency** — server emits `deleted_ids` only when `$updatedSince !== null && $page === 1`; client reads `deleted_ids` only on `page === 1`. Active query filters `is_active=true` (no `withTrashed`, so trashed rows excluded by default); deactivated query filters `is_active=false`. The two sets are disjoint; a reactivated variant lands in `variants` not `deleted_ids`. Soft-delete bumps `updated_at`, but trashed rows are excluded from the active query by the default global scope — no leak. ✔
- **`resolveScannedCode`** — real union is `hit | choose | miss`; `ResolveScannedCodeDeps` has `db`, `products`, `companyId`, `signal`. Variant tier belongs inside the Tier-2 SQLite block. Not caching `variant-hit` in the LRU is clean (LRU stores `POSProduct` only via `setCachedScan`). ✔ (see L1 for the helper-name nit)
- **HomePage** — `addProductToCartWithToast` (line 335) currently base-adds with **no `has_variants` check** → the bug is real. `setVariantPickerProduct` is in scope (state line 239). `handleProductBarcode` switch (lines 407–418) has miss/choose/hit; the `variant-hit` arm goes before `hit`. `handleAddToCart` (line 842) is the canonical `if (has_variants) setVariantPickerProduct(...)` pattern the fix mirrors. `addItemGated(product, { variant })` is the real signature (line 861). ✔
- **`runFullSync` wiring** — pull phase ends with `pullReceiptQrIndex` (line 1868); the `pullLocationStock` swallow-and-log block (1876–1883) is the exact template. `coerceSyncError`, `logSyncOperation`, `getSyncMetadata`, `setSyncMetadata`, `resolveCatalogTenantGate` all exist. `pullProductsCore` cascade insertion point (`deleteLocationStockForProducts(db, deletedIdsAccumulator)` at line 593, aliased from `deleteForProducts`) is correct for adding `deleteVariantsForProducts`. ✔
- **`apiGetRaw`** — exists (`api.ts:195`), signature `(url, params?, opts?)`; plan's `fetchVariants` mirrors `stockApi.fetchLocationStock` exactly. ✔
- **i18n** — `variants` namespace object exists in both `en/pos.json` and `fr/pos.json` (has `chooseVariant`, `selectVariant`, `loading`, `loadError`, `empty`, `inStock`, `outOfStock`, `addToCart`). Plan only ADDS `offlineNoCache`. `@/lib/i18n` resolves to `apps/pos/src/lib/i18n.ts`. `crossLocationStockI18n.test.tsx` (cited mirror) exists. ✔
- **Backend test harness** — `ProductVariantFactory` exists and accepts `tenant_id/company_id/product_id` overrides; `SyncShiftCloseTest.php` setUp (Tenant/Company/UserCompanyMembership/`setPermissionsTeamId`/Sanctum) is real and the correct model. Route lands in the `api/v1` group with `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`; insertion after `/pos/stock-levels` (line 106) is **outside** the `EnsureWebPosDemoTenant` demo group (closes line 83), so `/api/v1/pos/variants` is correct. ✔

---

## Recommendation
Approve once **M1** (real SQLite test harness) and **M2** (Catalog-owned binding + corrected `git add` path) are folded into the plan text, and **L1–L3, L5** notes are added. These are plan-text corrections, not design changes; the implementation can proceed milestone-by-milestone as written.
