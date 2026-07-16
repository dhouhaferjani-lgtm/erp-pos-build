# Multi-Location §2 Inventory Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL — invoke `superpowers:test-driven-development` before writing ANY implementation code (write the failing test first, watch it fail, then make it pass), and `superpowers:verification-before-completion` before claiming any task done (run the exact commands in the task, paste the passing output — evidence before assertions). Do NOT run the full PHPUnit or Vitest suite (crashes the laptop / OOM zombies) — run tests BY PATH only, exactly as written per task. Every task ends with a commit. Do not batch tasks. Follow AutoERP CLAUDE.md rule 4 (no scope creep): touch only the files a task names.

---

## Goal

Deliver package §2 (Inventory visibility) of the Multi-Location Management spec (`docs/superpowers/specs/2026-07-16-multi-location-management-design.md`). Users must see per-location stock at a glance — a paginated product×location matrix with inline min/max threshold editing, a "Stock by location" page with a rebalancing view, suggested transfer sources, a real product-page stock section, an explicit receiving destination, and a server-side location filter on stock movements. This package consumes §1's scope foundation; it does not build scope plumbing.

This plan maps to spec §2 and closes gaps G3, G4, G5, G6, G7, G10 (destination half), G15, and adversarial-review findings I1–I9 and F7.

## Architecture

- **Backend:** Laravel 12, PHP 8.4 strict types, hexagonal (Domain / Application / Infrastructure / Presentation) per module. Inventory module owns the matrix, thresholds, receiving-destination reconciliation, and movements filter. Cross-module reads use query-builder against tables (never import another module's Eloquent model — CLAUDE.md rule 6; enums are the sanctioned shared vocabulary, mirrored from `LocationStockQueryService`).
- **Frontend:** React 19 / Vite 7 / TypeScript strict / TanStack Query 5. New shared organism `ProductLocationMatrix`; new "Stock by location" feature page; wiring edits to existing transfer / product / purchases surfaces.
- **The matrix query is a NEW data path** (I9). The replenishment grid it visually generalizes has no pagination, no search, and no stock data — do not try to reuse its endpoint.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.4 (strict types), PostgreSQL 16 (db-per-tenant) |
| Frontend | React 19, Vite 7, TS strict, TanStack Query 5, Tailwind 4 design tokens |
| Money/Qty | bcmath numeric strings; `QuantityScale` (scale 4) at rest; `CurrencyScaleResolverInterface` injected |
| Types | Generated in `packages/shared/types/` via `php artisan typescript:transform` (only when a PHP DTO changes) |

## Global Constraints (copied from repo rules — apply to EVERY task)

1. **Route middleware:** every Inventory route stays inside the existing group `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory']` in `app/Modules/Inventory/Presentation/routes.php`, and each new route adds its explicit `can:...` ability. Missing `api` → 401; missing `SetPermissionsTeam` → permission failures.
2. **Constructor injection only** — all dependencies `private readonly` via constructor. Never `app()`.
3. **No `mixed` (PHP) / no `any` (TS).** Use DTOs / `unknown` + type guards. PHPStan level 8, zero errors on new code.
4. **Quantities are decimals, never floats.** At rest `QuantityScale` (scale 4). Compare/aggregate with `bccomp`/`bcadd`/`bcsub` (PHP) and `bccomp`/`bcadd`/`bcsub` from `@/lib/decimal` (TS). **NEVER `parseFloat`/`Number(...)`/`(float)` on a quantity** (CLAUDE.md rule 19). Quantities travel as strings in every payload.
5. **FormRequest quantity ceiling:** keep `numeric` and ADD the regex `/^-?\d+(\.\d{1,4})?$/` per quantity field (thresholds may be negative-forbidden → use `/^\d+(\.\d{1,4})?$/`). Non-breaking.
6. **Scale resolver:** constructor-inject `CurrencyScaleResolverInterface`; never a bare no-arg `getScale()` in queued/console contexts. (§2 is HTTP-only with bound `CompanyContext`, but quantity aggregation uses `QuantityScale` constants, not currency scale.)
7. **Frontend text via `t()`** (react-i18next), RTL-safe logical properties, design tokens only (`@/lib/designTokens` — `tokens`, `textColors`, `borderColors`, `colors`), canonical components (`DataTable`, `PageHeader`, `Button`, `QuantityInput`, `Select`, `Modal`, `EmptyState`). `formatQuantity` for every quantity cell — never render a raw quantity string through arithmetic.
8. **Query keys:** every tenant-data `useQuery`/`useQueries` key uses `tenantScopedKey([...])`; every **location-consuming** query additionally uses `locationScopedKey(segments, scope)` (from §1) so the effective scope is a NON-leading segment and the resource literal stays `segments[0]` for cross-scope mutation invalidation.
9. **Tests BY PATH only.** Backend: `./vendor/bin/pest <path>` (or `php artisan test <path>`). Frontend: `pnpm vitest run <path>`. **Never** the bare suite. **Aggregate/pivot tests run on PostgreSQL** (RefreshDatabase against the tenant PG connection), never SQLite (I8) — SQLite's `SUM`/`ilike`/`whereNull` grouping diverges from PG.
10. **API responses:** `apiGet`/`apiPost` already unwrap `response.data.data`; for `{data, meta}` paginated endpoints use `api.get` and return `response.data`.

---

## Consumes from §1 (do NOT build — assume shipped and importable)

These are §1 deliverables. If a §2 task starts before §1 lands, that task is BLOCKED — flag it, do not stub these.

**Backend**
- `App\Modules\Company\Services\LocationScopeResolver::resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array` — returns the effective allowed location-id list (fail-closed `AuthorizationException` on out-of-scope ids; single-company; HTTP-only, requires bound `CompanyContext`). Every §2 read endpoint calls this with the request's `location_ids[]` and passes NO bypass permission (inventory visibility has no processor carve-out) unless a task states otherwise.
- `GET /company/locations` — module-agnostic, broad read gate, returns only the caller's allowed locations (the picker's allowed set). §2 backend never re-implements location listing.

**Frontend**
- `useViewScope(): { scope, effectiveLocationIds: string[], isAll, setScope }` from `apps/web/src/stores/viewScopeStore.ts` — the global multi-select view scope.
- `locationScopedKey(segments: readonly unknown[], scope): readonly unknown[]` from `apps/web/src/lib/locationScopedKey.ts` — wraps `tenantScopedKey`, bakes scope as a non-leading segment.
- The picker sends reads as `location_ids[]` query params. §2 pages read `effectiveLocationIds` from `useViewScope()` and pass them as `location_ids[]`.

---

## Task 1 — Bulk stock-matrix endpoint (I3, I4, I8, I9; G4)

**Files**
- `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php` (new)
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMatrixController.php` (new)
- `apps/api/app/Modules/Inventory/Presentation/routes.php` (edit — add route)
- `apps/api/tests/Feature/Inventory/StockMatrixEndpointTest.php` (new)

**Interfaces (PINNED CONTRACT — do not deviate)**

```
GET /api/v1/inventory/stock-matrix
  ?location_ids[]=<uuid>&search=<string>&page=<int>&per_page=<int>&include=incoming
  middleware: group + can:inventory.view; location_ids[] resolver-scoped (no bypass permission)

Response (hand-built JSON — NOT a spatie DTO, because `cells` is a dynamic-keyed record):
{
  "data": MatrixRow[],
  "meta": { "current_page": int, "last_page": int, "total": int }
}

MatrixRow = {
  "product_id": string,
  "variant_id": string | null,
  "name": string,
  "sku": string,
  "is_variant_parent": boolean,           // true on a product rollup that has variant children
  "cells": {                              // keyed by locationId, zero-filled for every scoped location
    [locationId: string]: {
      "on_hand": string,                  // quantity, scale-4 numeric string
      "reserved": string,
      "available": string,               // on_hand − reserved
      "min_quantity": string | null,     // null on variant-parent rollup rows (thresholds are per-grain)
      "max_quantity": string | null,
      "incoming"?: string                // present only when include=incoming
    }
  }
}
```

Response is hand-assembled (like `ProductController@stockLevels`), so **no `typescript:transform` needed** for this task — the FE declares `MatrixRow` manually in Task 3's api file.

**Query shape (I4 — paginate over PRODUCTS, then ≤3 grouped queries; NO per-cell query)**

```php
// StockMatrixQueryService::matrix(
//   string $tenantId, string $companyId, array $locationIds,
//   string $search, int $page, int $perPage, bool $includeIncoming
// ): array{data: list<array<string,mixed>>, meta: array{current_page:int,last_page:int,total:int}}

// (1) Paginate the PRODUCT dimension. Search joins products only.
$productsPage = DB::table('products')
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
    ->when($search !== '', function ($q) use ($search): void {
        $q->where(function ($w) use ($search): void {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $w->where('name', 'ilike', $like)
              ->orWhere('sku', 'ilike', $like)
              ->orWhere('barcode', 'ilike', $like);
        });
    })
    ->orderBy('name')->orderBy('id')                 // stable ordering for pagination
    ->paginate(perPage: $perPage, columns: ['id', 'name', 'sku'], pageName: 'page', page: $page);

/** @var list<string> $productIds */
$productIds = collect($productsPage->items())->pluck('id')->map(fn ($v) => (string) $v)->all();
if ($productIds === []) {
    return ['data' => [], 'meta' => $this->meta($productsPage)];
}

// (2) On-hand + reserved + thresholds for the page's products × scoped locations.
//     Grouped read — one query. Variant-grain rows kept distinct (variant_id preserved).
$stockRows = DB::table('stock_levels')
    ->where('tenant_id', $tenantId)->where('company_id', $companyId)
    ->whereIn('product_id', $productIds)
    ->whereIn('location_id', $locationIds)
    ->get(['product_id', 'variant_id', 'location_id', 'quantity', 'reserved', 'min_quantity', 'max_quantity']);

// (3) OPTIONAL incoming (include=incoming) — two grouped queries restricted to page ids × scoped locations:
//     (a) in-transit stock_transfer_lines to destination in $locationIds, grouped product/variant/destination
//     (b) confirmed-PO unreceived remainder (document_lines.location_id in $locationIds), grouped product/location
//     Mirror LocationStockQueryService::incoming() exactly (TransferStatus::InTransit, PO Confirmed, remainder>received).
```

**Grain / no-double-count discipline (I3 — mirror `LocationStockQueryService.php:120-122`):**
- Build a variant-child row per `(product_id, variant_id NOT NULL, ...)` and a rollup parent per product.
- **Parent rollup cell** = `bcadd` over ALL of that product's `stock_levels` rows for the location (a well-formed product stores stock EITHER on the `variant_id IS NULL` row OR on variant rows, never both — so summing all rows does not double-count). `is_variant_parent = true` iff the product has ≥1 `variant_id IS NOT NULL` stock row. `min_quantity`/`max_quantity` on a variant-parent rollup cell = `null` (thresholds live at leaf grain).
- **Non-variant product** → single row (`variant_id: null`, `is_variant_parent: false`), thresholds from its `variant_id IS NULL` row.
- **Variant child rows** carry their own `variant_id`, thresholds, and per-location cells.
- Zero-fill: every scoped location gets a cell (`on_hand:'0.0000'`, etc.) even with no stock row.
- All quantity math via `bcadd`/`bcsub` at scale 4 (`QuantityScale`); `available = bcsub(on_hand, reserved, 4)`.

**Controller** resolves scope then delegates:

```php
public function index(Request $request): JsonResponse
{
    /** @var User $user */
    $user = $request->user();
    $company = $this->companyContext->requireCompany();
    /** @var list<string> $requested */
    $requested = array_values(array_filter((array) $request->input('location_ids', [])));
    $locationIds = $this->scopeResolver->resolve($user, $requested); // fail-closed; no bypass
    $search = trim((string) $request->query('search', ''));
    $page = max(1, $request->integer('page', 1));
    $perPage = min(max($request->integer('per_page', 25), 1), 100);
    $includeIncoming = str_contains((string) $request->query('include', ''), 'incoming');

    return response()->json($this->matrixQuery->matrix(
        $company->tenant_id, $company->id, $locationIds, $search, $page, $perPage, $includeIncoming,
    ));
}
```

**Route (edit `routes.php`, inside the existing group):**
```php
Route::get('/inventory/stock-matrix', [StockMatrixController::class, 'index'])
    ->middleware('can:inventory.view')
    ->name('inventory.stock-matrix');
```

**TDD steps**
- [ ] Write `StockMatrixEndpointTest` (PostgreSQL, `RefreshDatabase`, `RolesAndPermissionsSeeder`, valid UUIDs). Cases:
  - **pivot correctness:** 2 products × 2 locations with distinct on-hand/reserved → assert `cells[locA].available == on_hand−reserved` per row; zero-filled cell for a location with no row.
  - **variant no-double-count (I3):** a variant product with 2 variants (stock on variant rows) + a non-variant product (stock on null row) → assert parent rollup cell `on_hand == bcadd(variantA, variantB)`, `is_variant_parent==true`, parent `min_quantity==null`; two variant child rows present with their own thresholds; non-variant product single row, `is_variant_parent==false`.
  - **search + pagination stability:** seed 30 products, `per_page=10`, assert `meta.total==<matches search>`, `meta.last_page` correct, page 2 disjoint from page 1, `search` matches name/sku/barcode.
  - **resolver scoping:** user restricted (via §1 membership) to location A requesting `location_ids[]=B` → `403` (fail-closed); requesting nothing → cells only for A.
  - **include=incoming:** in-transit transfer to loc A + confirmed-PO remainder at loc A → `cells[A].incoming` equals their sum; absent when `include` omitted.
  - Run: `cd apps/api && ./vendor/bin/pest tests/Feature/Inventory/StockMatrixEndpointTest.php` → RED.
- [ ] Implement `StockMatrixQueryService`, `StockMatrixController`, route. Run the same command → GREEN.
- [ ] `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Inventory/Application/Services/StockMatrixQueryService.php app/Modules/Inventory/Presentation/Controllers/StockMatrixController.php` → 0 errors. `./vendor/bin/pint app/Modules/Inventory`.
- [ ] Commit: `feat(inventory): bulk product×location stock-matrix endpoint (multiloc §2 I4)`.

---

## Task 2 — Per-location min/max threshold editor endpoint (I1 — the keystone; G4)

Without a write path, `stock_levels.min/max_quantity` are NULL for every real tenant and the suggest-source + rebalancing features ship dead.

**Files**
- `apps/api/app/Modules/Inventory/Presentation/Requests/UpdateStockThresholdsRequest.php` (new)
- `apps/api/app/Modules/Inventory/Application/Services/StockThresholdService.php` (new)
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php` (edit — add `updateThresholds`)
- `apps/api/app/Modules/Inventory/Presentation/routes.php` (edit — add route)
- `apps/api/tests/Feature/Inventory/StockThresholdTest.php` (new)

**Interface**
```
PUT /api/v1/inventory/stock-levels/thresholds
  middleware: group + can:inventory.adjust
  body: {
    product_id: string(uuid),
    variant_id?: string(uuid)|null,
    location_id: string(uuid),
    min_quantity?: string|null,   // null clears
    max_quantity?: string|null
  }
  200 → { data: { product_id, variant_id, location_id, min_quantity, max_quantity } }
```

**FormRequest rules (rule 5 — regex ceiling; nullable-to-clear; both present ⇒ min ≤ max):**
```php
public function rules(): array
{
    return [
        'product_id'   => ['required', 'uuid'],
        'variant_id'   => ['nullable', 'uuid'],
        'location_id'  => ['required', 'uuid'],
        'min_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        'max_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
    ];
}
public function withValidator(Validator $v): void
{
    $v->after(function (Validator $v): void {
        $min = $this->input('min_quantity');
        $max = $this->input('max_quantity');
        if (is_string($min) && is_string($max) && bccomp($min, $max, 4) > 0) {
            $v->errors()->add('max_quantity', 'max_quantity must be ≥ min_quantity.');
        }
    });
}
```
Messages: `min_quantity.regex`/`max_quantity.regex` → "must have at most 4 decimal places."

**Service** — upsert the `stock_levels` row at `(tenant, company, product, variant IS NULL-safe, location)`; if no row exists yet, create one with `quantity:'0.0000', reserved:'0.0000'` and the thresholds (so thresholds can be set before any stock lands). `variant_id === null` MUST match `whereNull('variant_id')` (never collapse variant grains). Persist via `CurrencyScale`/`QuantityScale` — store `QuantityScale::bcformatStrict($value, 4)` (or `null`) — no float. Validate location membership by calling `LocationContext::validateLocationAccess($locationId, $companyId, $user)` (same pattern as `StockMovementController@receive`) so a user cannot edit thresholds outside their allowed set.

**Route:**
```php
Route::put('/inventory/stock-levels/thresholds', [StockLevelController::class, 'updateThresholds'])
    ->middleware('can:inventory.adjust')
    ->name('stock-levels.thresholds.update');
```

**TDD steps**
- [ ] Write `StockThresholdTest` (PG). Cases: set min+max on existing row; set thresholds when NO stock row exists (row created, qty 0); clear via null; `min>max` → 422; `max_quantity: "1.23456"` → 422 (ceiling); variant-grain isolation (setting variant A's threshold does not touch the null-variant row); out-of-scope location → 403. Run `./vendor/bin/pest tests/Feature/Inventory/StockThresholdTest.php` → RED.
- [ ] Implement request, service, controller method, route → GREEN.
- [ ] `./vendor/bin/phpstan analyse` the 3 new/edited files → 0; `./vendor/bin/pint app/Modules/Inventory`.
- [ ] Commit: `feat(inventory): per-location min/max threshold editor endpoint (multiloc §2 I1)`.

---

## Task 3 — `ProductLocationMatrix` organism + "Stock by location" page (F7; G4)

**Files**
- `apps/web/src/features/inventory/api/stockMatrix.ts` (new — fetchers + manual `MatrixRow` interface)
- `apps/web/src/components/organisms/ProductLocationMatrix/ProductLocationMatrix.tsx` (new)
- `apps/web/src/components/organisms/ProductLocationMatrix/index.ts` (new)
- `apps/web/src/features/inventory/pages/StockByLocationPage.tsx` (new)
- `apps/web/src/features/inventory/components/ThresholdEditCell.tsx` (new)
- `apps/web/src/i18n/locales/{en,fr,ar}/inventory.json` (edit — add `stockByLocation.*` keys)
- routing + nav registration (edit per `docs/conventions/02-NAVIGATION-ROUTING.md`)
- `apps/web/src/components/organisms/ProductLocationMatrix/ProductLocationMatrix.test.tsx` (new)
- `apps/web/src/features/inventory/pages/StockByLocationPage.test.tsx` (new)

**Interface (api/stockMatrix.ts — manual types matching Task 1's pinned JSON):**
```ts
export interface MatrixCell {
  on_hand: string; reserved: string; available: string
  min_quantity: string | null; max_quantity: string | null; incoming?: string
}
export interface MatrixRow {
  product_id: string; variant_id: string | null; name: string; sku: string
  is_variant_parent: boolean; cells: Record<string, MatrixCell>
}
export interface StockMatrixResponse { data: MatrixRow[]; meta: { current_page: number; last_page: number; total: number } }

export async function getStockMatrix(params: {
  locationIds: string[]; search: string; page: number; perPage: number; includeIncoming: boolean
}): Promise<StockMatrixResponse> {
  const qs = new URLSearchParams()
  params.locationIds.forEach((id) => qs.append('location_ids[]', id))
  if (params.search) qs.set('search', params.search)
  qs.set('page', String(params.page)); qs.set('per_page', String(params.perPage))
  if (params.includeIncoming) qs.set('include', 'incoming')
  const res = await api.get<StockMatrixResponse>(`/inventory/stock-matrix?${qs.toString()}`)
  return res.data // {data, meta} — do NOT use apiGet (drops meta)
}

export async function updateThresholds(body: {
  product_id: string; variant_id: string | null; location_id: string
  min_quantity: string | null; max_quantity: string | null
}): Promise<void> { await apiPost('/inventory/stock-levels/thresholds', body /* PUT */) }
```
(Use `api.put` for thresholds; keep the payload strings.)

**Component contract (F7 — canonical only):**
- Columns = scoped locations (from `useViewScope().effectiveLocationIds`, resolved to names via `useLocations()`), product/variant label column pinned start. Wide → wrap in `overflow-x: auto` (page body never scrolls horizontally).
- Rows = product-rollup; `is_variant_parent` rows are expandable (chevron) to reveal variant child rows (fetched inline within the same matrix response — variant rows are already in `data`, grouped by `product_id`; expand toggles visibility, no extra fetch).
- **Metric toggle** (available / on-hand / vs min-max) — segmented control using `tokens`; default `available`.
- Every quantity through `formatQuantity` — NEVER `parseFloat`.
- **Deficit/surplus tinting via tokens:** cell below `min_quantity` → `tokens.alert.warning` (or `textColors.error`); above `max_quantity` → `tokens.alert.success`; comparisons via `bccomp` from `@/lib/decimal`, guarded on non-null thresholds. No hardcoded Tailwind color classes (rule 18).
- Build on `DataTable` where the tabular shape fits, or a token-styled CSS grid mirroring `ReplenishmentQueuePage`'s matrix (`grid`, `minmax`, `borderColors.light`) — but tokens/`t()`/RTL throughout.
- Inline threshold editing: `ThresholdEditCell` renders `QuantityInput` (min="0", `decimalPlaces={4}`) for min & max on leaf-grain rows, gated behind `RequirePermission permission="inventory.adjust"`; on blur/submit calls `updateThresholds` mutation and invalidates the matrix query. Variant-parent rollup cells show `—` for thresholds (not editable).

**Page (`StockByLocationPage`):**
- `PageHeader` (title `t('stockByLocation.title')`, subtitle). Search input (debounced) → `search` param. Pagination via `OffsetPagination`. Query key: `locationScopedKey(['inventory-stock-matrix', search, page, perPage], scope)`. Reads `effectiveLocationIds` from `useViewScope()`.
- Empty state via `EmptyState`.
- The rebalancing view (Task 7) mounts as a section on this page.

**Routing + nav** (per `docs/conventions/02-NAVIGATION-ROUTING.md`): register route `/inventory/stock-by-location` (lazy import), add a sidebar/nav entry gated by `inventory.view`, wire breadcrumb. Confirm the exact router + nav files by reading the convention doc; add there only (no bespoke nav).

**TDD steps**
- [ ] Write `ProductLocationMatrix.test.tsx` (Vitest + RTL; may `vi.mock` `useViewScope`, `useLocations`, and the matrix query hook per memory's frontend-test convention): renders one column per scoped location; renders `formatQuantity` output (assert rendered text, not classes — rule 17); metric toggle switches displayed value; below-min cell gets the warning token (assert via rendered element/role, not raw class string where avoidable); expand reveals variant rows; threshold input hidden without `inventory.adjust`. Run `pnpm vitest run src/components/organisms/ProductLocationMatrix/ProductLocationMatrix.test.tsx` → RED.
- [ ] Write `StockByLocationPage.test.tsx`: search updates query; empty state; pagination. RED.
- [ ] Implement component, page, api, i18n keys, routing/nav → GREEN both files.
- [ ] `cd apps/web && pnpm typecheck && pnpm lint` (or scoped `pnpm lint src/components/organisms/ProductLocationMatrix src/features/inventory`) → 0.
- [ ] Commit: `feat(web): ProductLocationMatrix + Stock-by-location page with inline thresholds (multiloc §2 F7)`.

---

## Task 4 — Transfer page suggested-source (G3, I1 fallback, I6)

Port the `RequestContextPanel` per-location vector into `CreateStockTransferPage` line entry so the user sees full stock before choosing a source.

**Files**
- `apps/web/src/features/stock-transfers/components/TransferSourceSuggestion.tsx` (new)
- `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` (edit — line entry, replace/augment `AvailabilityCell`)
- `apps/web/src/features/stock-transfers/lib/suggestSource.ts` (new — pure fn)
- `apps/web/src/features/stock-transfers/lib/suggestSource.test.ts` (new)
- `apps/web/src/i18n/locales/{en,fr,ar}/stock-transfers.json` (edit)

**Suggested-source rule (pure, bccomp only — NO parseFloat/float):**
```ts
// suggestSource(locations, requestingLocationId, requestedQty): { locationId: string; reason: 'surplus'|'fallback' } | null
// locations: { location_id, available, max_quantity: string|null }[]
// 1) SURPLUS: candidates where max_quantity != null && bccomp(available, max_quantity) > 0,
//    exclude the destination, pick the LARGEST excess = bcsub(available, max_quantity, 4) via bccomp reduce.
// 2) FALLBACK (I1 — thresholds unset): among sources with bccomp(available, requestedQty) >= 0,
//    exclude destination, pick the LARGEST available via bccomp reduce.
// 3) none qualify → null (explicit empty state).
// available already excludes reserved and in-transit (I6) — do NOT re-subtract anything.
```

**UI:** beneath each transfer line, `TransferSourceSuggestion` renders the full per-location vector (reuse the `getProductStock` fetch already in the file via `productStock.ts`) with destination stock + min/max shown, suggested source highlighted (token tint), and an "Use this source" action that sets the line's source. When `suggestSource` returns null, show `t('create.suggestion.none')` empty state. Highlighting: destination row `tokens.alert.warning`, suggested source `tokens.alert.success` — mirror `RequestContextPanel`.

**TDD steps**
- [ ] Write `suggestSource.test.ts`: surplus wins over fallback; largest-excess tie-break; NULL-threshold fallback picks largest available; below-need excluded; empty → null. `pnpm vitest run src/features/stock-transfers/lib/suggestSource.test.ts` → RED.
- [ ] Implement `suggestSource.ts` → GREEN.
- [ ] Wire `TransferSourceSuggestion` into `CreateStockTransferPage`; extend the page test if present (assert suggestion shown, "use source" sets source). Reuse `formatQuantity` for all values.
- [ ] `pnpm typecheck && pnpm lint src/features/stock-transfers` → 0.
- [ ] Commit: `feat(web): suggested transfer source with NULL-threshold fallback (multiloc §2 G3)`.

---

## Task 5 — Product page stock section (G5, I5 — wiring only)

The `/products/{id}/stock-levels` endpoint already returns `reserved`, `min_quantity`, `max_quantity`, `incoming`, `projected_available` per location (`productStock.ts:3-15`). Promote the collapsed `<details>` breakdown in `ProductStockLevels.tsx` to a real per-location section with a reserved column and a "transfer from here" CTA.

**Files**
- `apps/web/src/features/inventory/components/ProductStockLevels.tsx` (edit)
- `apps/web/src/i18n/locales/{en,fr,ar}/inventory.json` (edit)
- `apps/web/src/features/inventory/components/ProductStockLevels.test.tsx` (new or edit)

**Changes**
- Replace the `<details>`-gated collapse (line 165-196) with an always-rendered section (still collapsible is fine, but a real header, not buried) using `DataTable` or a token grid: columns = Location, On hand, Reserved, Available, Incoming, Min/Max. Every value via `formatQuantity`.
- Add a per-row "Transfer from here" CTA → navigates to the transfer create route with query prefill (`?source_location_id=<loc>&product_id=<id>`); `CreateStockTransferPage` reads these to pre-seed the first line + source (small read-side addition in that page — prefill only, no new endpoint). Gate CTA behind `RequirePermission permission="inventory.transfers.create"`.
- Inline threshold editing here too (reuse `ThresholdEditCell` from Task 3) so the product page is a second entry point for I1.

**TDD steps**
- [ ] Test: reserved column renders `formatQuantity(loc.reserved)`; CTA present with correct href/prefill; hidden without transfer permission. `pnpm vitest run src/features/inventory/components/ProductStockLevels.test.tsx` → RED → GREEN.
- [ ] `pnpm typecheck && pnpm lint src/features/inventory` → 0.
- [ ] Commit: `feat(web): real per-location stock section on product page with transfer CTA (multiloc §2 G5)`.

---

## Task 6 — Receiving destination (G6, G10, I2)

**Reconciliation choice (stated per code reality) — OPTION A: update the received PO line's `document_lines.location_id` to the receipt destination, inside the existing `processReceiptLines` transaction; persist the destination on the receipt too.**

Why: the incoming projection is hardcoded to read `document_lines.location_id` (`LocationStockQueryService.php:260`, `ProductController.php:1011`), and `goods_receipts` has **no** `location_id` column today. `GoodsReceiptService::post` posts ALL lines to a single `$location = $purchaseOrder->location ?? getDefaultLocation()` (`:214-215`), while the PO line's `location_id` was written at PO-create time from the company default. Making the projection "project from receipt destination" would force it to join `goods_receipts` and disambiguate multiple partial receipts to different destinations — the projection's remainder needs exactly ONE destination per line. Writing the chosen destination back onto each received PO line keeps a single source of truth at the exact grain the projection already reads, re-homes the unreceived remainder to the actual receiving store, and requires zero projection change. Standalone receipts already carry a required `location_id` (`CreateStandaloneReceiptRequest.php:23`) written to their fresh lines — no divergence there; that half is UI-context only.

**Files**
- `apps/api/database/migrations/tenant/2026_07_16_120000_add_location_id_to_goods_receipts.php` (new — nullable indexed `location_id`)
- `apps/api/app/Modules/Inventory/Domain/GoodsReceipt.php` (edit — fillable + prop)
- `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php` (edit — accept destination, update PO line location, persist on receipt)
- `apps/api/app/Modules/Inventory/Presentation/Controllers/GoodsReceiptController.php` (edit — accept + validate `location_id` on post)
- `apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx` (edit — destination `<Select>` with per-location context)
- `apps/web/src/features/purchases/StandaloneReceiptPage.tsx` (edit — enrich existing location `<Select>` with stock/min-max context)
- `apps/api/tests/Feature/Inventory/GoodsReceiptDestinationTest.php` (new)

**Migration (self-guarding, additive):**
```php
Schema::table('goods_receipts', function (Blueprint $t): void {
    $t->uuid('location_id')->nullable()->after('company_id');
    $t->index(['tenant_id', 'company_id', 'location_id'], 'goods_receipts_tenant_company_location_idx');
});
```
(NOTE deploy: `tenants:migrate` at deploy — record in the §2 deploy checklist. Push=deploy, so migration must be reversible + idempotent; provide `down()` dropping index+column.)

**Backend behavior:**
- `GoodsReceiptController@post` validates optional `location_id` (`['nullable','uuid']`), calls `LocationContext::validateLocationAccess` when present, passes it to `GoodsReceiptService::post(GoodsReceipt $receipt, string $actorId, ?string $destinationLocationId = null, bool $failClosedGrir = false)`.
- In `post()`: resolve destination = `$destinationLocationId ? Location::findScoped(...) : ($purchaseOrder->location ?? getDefaultLocation())`. Persist `$lockedReceipt->location_id = $location->id`. Pass `$location` to `processReceiptLines` (unchanged signature already takes `Location $location`).
- In `processReceiptLines`, for each PO line that actually receives (paid or free qty > 0), set `$line->location_id = $location->id` and save (the line is already loaded/locked in scope). This is the reconciliation write. Guard: only update when the destination differs, to avoid needless writes.

**Frontend:**
- `ReceiveGoodsDialog`: add a destination `<Select>` (canonical `Select`) at the top of the form, options from `useLocations()` (allowed set), default = PO location if provided. Show per-location context (on-hand / min-max) for the selected destination by reading the receipt's product stock (optional inline panel; keep it lightweight — a single `getProductStock` per line is already the AvailabilityCell pattern). Thread `location_id` into `ReceiveGoodsRequest` and the post call.
- `StandaloneReceiptPage`: the location `<Select>` (line 377-388) already exists and is required; add the per-location stock/min-max context hint beside it (reuse `getProductStock` for the first line's product or a compact helper). No contract change (already sends `location_id`).

**TDD steps**
- [ ] Write `GoodsReceiptDestinationTest` (PG): confirmed PO with header/default location A; post receipt with `location_id = B` → assert (a) the stock movement `location_id == B` (via `stock_movements`), (b) each received `document_lines.location_id == B`, (c) `goods_receipts.location_id == B`, (d) projected incoming for the unreceived remainder reads B (query `LocationStockQueryService` or the incoming projection). Also: omitting `location_id` → falls back to PO/default A (unchanged behavior). Out-of-scope B → 403. Run `./vendor/bin/pest tests/Feature/Inventory/GoodsReceiptDestinationTest.php` → RED.
- [ ] Implement migration, model, service, controller → GREEN. Run migration on the test PG connection via `RefreshDatabase`.
- [ ] `./vendor/bin/phpstan analyse` edited backend files → 0; `./vendor/bin/pint`.
- [ ] FE: add destination select + context; extend `ReceiveGoodsDialog` test to assert `location_id` in the emitted request. `pnpm vitest run src/features/purchases` (scoped), `pnpm typecheck && pnpm lint src/features/purchases`.
- [ ] Commit: `feat(inventory): explicit receiving destination reconciled with incoming projection (multiloc §2 I2)`.

---

## Task 7 — Rebalancing view (G7)

Owner-facing "below-min/out at A, surplus at B" grouped-by-product list on the Stock-by-location page, with a prefilled-transfer CTA and empty states.

**Files**
- `apps/web/src/features/inventory/components/RebalancingView.tsx` (new)
- `apps/web/src/features/inventory/lib/rebalance.ts` (new — pure grouping fn)
- `apps/web/src/features/inventory/lib/rebalance.test.ts` (new)
- `apps/web/src/features/inventory/pages/StockByLocationPage.tsx` (edit — mount section)
- `apps/web/src/i18n/locales/{en,fr,ar}/inventory.json` (edit)

**Logic (pure, bccomp only):** consume the same `MatrixRow[]` (with `include=incoming` optional). For each leaf row, classify each location cell:
- **deficit:** `min_quantity != null && bccomp(available, min_quantity) < 0` (or `available <= 0` = out).
- **surplus:** `max_quantity != null && bccomp(available, max_quantity) > 0`.
Emit only products having ≥1 deficit AND ≥1 surplus location (a genuine rebalance opportunity). Fallback when thresholds unset mirrors Task 4's rule (largest-available donor vs zero/negative receiver). Sort by severity (largest deficit first).

**UI:** grouped list per product → "Move from {surplus store} → {deficit store}" with quantities via `formatQuantity`; CTA "Create transfer" prefills `CreateStockTransferPage` (`?source_location_id=&destination_location_id=&product_id=&quantity=`). Empty state `t('stockByLocation.rebalance.empty')` when no opportunities. Gate CTA behind `inventory.transfers.create`.

**TDD steps**
- [ ] `rebalance.test.ts`: deficit+surplus pair emitted; product with only deficit (no donor) excluded; NULL-threshold fallback; severity sort. `pnpm vitest run src/features/inventory/lib/rebalance.test.ts` → RED → GREEN.
- [ ] Implement `RebalancingView`, mount on the page. `pnpm typecheck && pnpm lint src/features/inventory` → 0.
- [ ] Commit: `feat(web): rebalancing view (below-min/surplus) on Stock-by-location page (multiloc §2 G7)`.

---

## Task 8 — G15: server-side movements location filter + bccomp cleanup

**Files**
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` (edit — `index` accepts `location_ids[]`, resolver-scoped)
- `apps/web/src/features/inventory/components/ProductMovementsTab.tsx` (edit — send `location_ids[]`, delete client filter, replace `parseFloat`)
- `apps/api/tests/Feature/Inventory/StockMovementLocationFilterTest.php` (new)

**Backend:** in `index()`, after the existing `product_id`/`movement_type` filters, accept `location_ids[]`:
```php
$requested = array_values(array_filter((array) $request->input('location_ids', [])));
if ($request->has('location_id')) { $requested[] = (string) $request->input('location_id'); } // back-compat single
if ($requested !== []) {
    $scoped = $this->scopeResolver->resolve($user, array_values(array_unique($requested))); // fail-closed
    $query->whereIn('location_id', $scoped);
}
```
Inject `LocationScopeResolver` and the `User` (`$request->user()`) — keep constructor injection for the resolver.

**Frontend (`ProductMovementsTab.tsx`):**
- Send every selected id as `location_ids[]` (loop `params.append('location_ids[]', id)`), delete lines 114-120 (single-only) and the client-side `useMemo` filter (130-137) — the server now filters. Use `movements = data?.data ?? []` directly.
- Replace `const qty = parseFloat(movement.quantity)` (line 268) with a sign check via `bccomp`: `const isPositive = bccomp(movement.quantity, '0') >= 0` (import `bccomp` from `@/lib/decimal`). Keep `formatQuantity(movement.quantity)` for display.
- Query key becomes `locationScopedKey(['product-movements', productId, page, perPage], scope)` — the scope segment carries the selected ids (or drive the tab's local multi-select through `useViewScope` narrowing per §1 F3; at minimum stop keying on the raw array as a leading segment).

**TDD steps**
- [ ] `StockMovementLocationFilterTest` (PG): movements at A, B, C; request `location_ids[]=A,B` → only A,B returned; out-of-scope id → 403; no param → all (company-scoped). Run `./vendor/bin/pest tests/Feature/Inventory/StockMovementLocationFilterTest.php` → RED → GREEN.
- [ ] Edit `ProductMovementsTab`; extend/adjust its test to assert multi-location request hits the server (no client filter) and no `parseFloat` remains (`grep -n parseFloat` on the file returns nothing). `pnpm vitest run src/features/inventory/components/ProductMovementsTab.test.tsx` if present.
- [ ] `./vendor/bin/phpstan analyse app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` → 0; `pnpm typecheck && pnpm lint src/features/inventory`.
- [ ] Commit: `fix(inventory): server-side movements location filter + bccomp cleanup (multiloc §2 G15)`.

---

## Gates & verification

Run at the END of the package, after all 8 tasks are committed on the feature branch (branch off `origin/dev` per CLAUDE.md rule 21; worktree via `superpowers:using-git-worktrees`).

**Type generation:** if any PHP DTO was added/changed (Tasks 1/2/6 are designed to be hand-built JSON + FormRequests to avoid this — verify no new `#[TypeScript]` DTO slipped in). If one was introduced, run `cd apps/api && php artisan typescript:transform` and commit the regenerated `packages/shared/types/`.

**Per-FE-task (already run inline):** `pnpm typecheck` + scoped `pnpm lint`.

**Reviewer gates (standing rule — every milestone; never auto-merge):**
- [ ] `inventory-costing-reviewer` — matrix pivot/variant no-double-count, threshold write path, receipt-destination reconciliation, quantity precision (bcmath, no float). Save review to a file per memory rule (not inline).
- [ ] `frontend-conventions-reviewer` — canonical components, tokens only, `t()`/RTL, `formatQuantity` (no `parseFloat`), `locationScopedKey` on location-consuming queries, no hardcoded colors.
- [ ] Address findings via `superpowers:receiving-code-review` (verify, don't blindly implement).

**Playwright e2e (critical paths — spec §2/§5):**
- [ ] Stock-by-location matrix renders per-location data for the scoped locations; a threshold inline edit round-trips (edit → refetch → persisted value shown).
- [ ] Transfer creation shows a suggested source for a product with a surplus location.
- [ ] Receive flow with an explicit destination selection; assert the movement landed at the chosen store (via product movements tab filtered to that store).
- [ ] Visual pass of the matrix page in BOTH light and dark themes (F7 — tint tokens must read correctly in both).

**Backend end-to-end (rule 5):** run each task's `pest` file by path once more together (still by path, not the suite): `./vendor/bin/pest tests/Feature/Inventory/StockMatrixEndpointTest.php tests/Feature/Inventory/StockThresholdTest.php tests/Feature/Inventory/GoodsReceiptDestinationTest.php tests/Feature/Inventory/StockMovementLocationFilterTest.php` → all green; `./vendor/bin/phpstan analyse app/Modules/Inventory` → 0.

**Deploy owes (record in a §2 deploy checklist, do not run on staging without owner sign-off):** `tenants:migrate` for `goods_receipts.location_id` (Task 6). No permission reseed (Tasks reuse existing `inventory.view`/`inventory.adjust`/`inventory.transfers.*`). Historical `document_lines.location_id` on pre-cutover POs stays default-attributed (documented caveat, no destructive backfill — consistent with §3's documents-header ruling).

---

## Self-review (coverage vs spec §2 + findings)

- **G3 / I6 / I1-fallback** → Task 4 (suggested source, available-excludes-reserved pinned, NULL-threshold fallback).
- **G4 / I3 / I4 / I8 / I9** → Tasks 1+3 (bulk endpoint paginated over products, ≤3 grouped queries, variant no-double-count, Postgres tests, new data path budgeted).
- **G5 / I5** → Task 5 (wiring only; backend already serves the fields).
- **G6 / G10 / I2** → Task 6 (per-receipt destination, reconciled via PO-line `location_id` update — choice stated with code rationale; projection test).
- **G7** → Task 7 (rebalancing view, empty states, NULL-threshold fallback).
- **G15 / I7** → Task 8 (server-side `location_ids[]`, client filter deleted, `parseFloat`→`bccomp`).
- **I1 (keystone)** → Task 2 endpoint + Task 3/Task 5 inline editors (thresholds no longer NULL-only).
- **F7** → Task 3 contract (DataTable/token grid, PageHeader, `t()`/RTL, `formatQuantity`, token tinting) + light/dark visual gate.
- **§1 dependencies** → "Consumes from §1" section (resolver signature `resolve(user, requestedIds, ?bypassPermission)`, `useViewScope`, `locationScopedKey`, `GET /company/locations`) — all consumed, none rebuilt; signatures match §1 pinned contracts.
- No placeholders, no `// TODO`; every task has failing-test-first steps, exact by-path commands, real contract/migration/query code, and a commit.
