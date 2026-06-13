VERDICT: NEEDS-WORK

The core in-transit premise checks out: `in_transit` transfers decrement the source and do not credit the destination until completion. The design still needs changes before implementation because it overstates offline gate readiness, omits the POS route hardening middleware used elsewhere, has a real keyboard event conflict on the proposed eye button, and leaves variant/location/cache semantics under-specified.

## HIGH

### H1 - Eye-button keyboard activation can still add the product to cart

Evidence:

- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:137-144`

```tsx
const onKeyDown = useCallback(
  (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      activate();
    }
  },
  [activate],
);
```

- `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:147-154`

```tsx
<div
  role="button"
  tabIndex={isActivationBlocked ? -1 : 0}
  aria-disabled={isActivationBlocked}
  aria-label={product.name}
  onClick={activate}
  onKeyDown={onKeyDown}
```

- The existing nested customize button only stops click propagation, not key events: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:167-174`

```tsx
<button
  type="button"
  data-testid="customize-button"
  onClick={(e) => {
    e.stopPropagation();
    onCustomize(product);
  }}
```

Why this matters:

The spec says the eye icon should be keyboard-accessible and "does not trigger add-to-cart (stop propagation)." That is incomplete for this component. Because the card root is a `div role="button"` with an `onKeyDown`, pressing Space or Enter while focus is on an inner eye button can bubble to the parent and call `activate()`, adding the product to cart.

Recommended fix:

Require the eye button to stop both click and keydown propagation, or change the parent handler to ignore bubbled key events with `if (e.currentTarget !== e.target) return;`. Add a regression test for keyboard activation of the eye button proving it opens the drawer and does not call `onAddToCart`.

### H2 - Offline-safe gate claim is false for company config

Evidence:

- Auth user permissions are persisted and hydrated offline: `apps/pos/src/stores/authStore.ts:148-160`

```ts
const token = await getStoredValue<string>(StorageKeys.TOKEN);
const user = await getStoredValue<User>(StorageKeys.USER);
const companyId = await getStoredValue<string>(StorageKeys.COMPANY_ID);
const companies = await getStoredValue<Company[]>(StorageKeys.COMPANIES);

if (token && user) {
  set({
    token,
    user,
    companyId,
    companies: companies ?? [],
    isAuthenticated: true,
  });
```

- Company config refresh only updates in-memory product store state: `apps/pos/src/stores/authStore.ts:418-423`

```ts
refreshCompanyConfig: async () => {
  try {
    const config = await apiGet<import('@/types/companyConfig').CompanyConfig>('/company/config');
    const { useProductStore } = await import('@/stores/productStore');
    useProductStore.setState({ companyConfig: config });
```

- `productStore` fetches config from the API when absent and does not persist it: `apps/pos/src/stores/productStore.ts:191-199`

```ts
let config = get().companyConfig;
if (!config) {
  try {
    config = await fetchCompanyConfig();
    set({ companyConfig: config });
  } catch {
    config = null;
  }
}
```

- The only observed persisted company-config cache is inside a one-off migration helper, not normal boot/config refresh: `apps/pos/src/lib/migration/c2BareCartLineDump.ts:164-177`

```ts
const config = await fetchCompanyConfig();
useProductStore.setState({ companyConfig: config });
if (companyId) {
  await setStoredValue(companyConfigCacheKey(companyId), config);
}
...
const cached = await getStoredValue<CompanyConfig>(companyConfigCacheKey(companyId));
```

Why this matters:

The spec says both gate inputs are "already cached locally" and gate evaluation is offline-safe. Permissions are cached, but company config is not generally persisted or hydrated from a normal config cache. After app restart while offline, the feature can fail closed even when `allow_cross_location_stock_view` was previously enabled and the stock-distribution payload is cached.

Recommended fix:

Add a normal per-company config cache key and hydrate `productStore.companyConfig` from it during auth/product bootstrap. Persist successful `/company/config` responses from `refreshCompanyConfig()` and `fetchCompanyConfig()` callers. Specify fail-closed behavior only for "no cached config exists," not for all offline cases.

### H3 - New POS endpoint middleware omits existing token-tenant enforcement

Evidence:

- Existing POS routes use `EnforceTokenTenantClaim` in the group: `apps/api/app/Modules/POS/routes.php:34`

```php
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
```

- The existing POS stock feed lives inside that group: `apps/api/app/Modules/POS/routes.php:96-97`

```php
// Location stock feed for the device sync (spec 2026-06-11 §4.1)
Route::get('/pos/stock-levels', [PosStockLevelController::class, 'index']);
```

- `EnforceTokenTenantClaim` rejects mismatched tenant claims: `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:94-102`

```php
$expectedTenantId = substr($tenantAbility, strlen('tenant:'));

if ($expectedTenantId !== $user->tenant_id) {
    return response()->json([
        'error' => [
            'code' => 'TOKEN_TENANT_MISMATCH',
            'message' => 'Token tenant claim does not match user tenant.',
        ],
    ], 401);
}
```

Why this matters:

The spec's middleware list is `['api', 'auth:sanctum', SetPermissionsTeam::class]`, which is not the current POS route convention. Even if company context and Spatie team scoping still protect most data paths, this endpoint should not be weaker than `/pos/stock-levels`.

Recommended fix:

Mount `GET /api/v1/pos/products/{product}/stock-distribution` inside `apps/api/app/Modules/POS/routes.php`'s existing route group, or explicitly include `EnforceTokenTenantClaim::class` in the middleware list. Add a route test for token tenant mismatch returning `401 TOKEN_TENANT_MISMATCH`.

### H4 - Drawer reuse would regress own-location stock truthfulness

Evidence:

- Product tiles now use the location-aware stock slice and decimal-string comparison: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:81-90`

```tsx
} else if (locationStock !== null) {
  isOutOfStock = bccomp(locationStock.available, '0') <= 0;
  isLowStock = !isOutOfStock && bccomp(locationStock.available, '10') <= 0;
  stockLabel = isOutOfStock
    ? t('products.outOfStock')
    : isLowStock
      ? t('products.lowStock')
      : (t as unknown as TranslateWithStringCount)('products.stock', {
          count: formatAvailableQty(locationStock.available),
        });
```

- The orphaned drawer still computes stock from legacy `product.stock_quantity`: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:23-28`

```tsx
const stockColor =
  product.stock_quantity <= 0
    ? 'text-red-600 bg-red-50'
    : product.stock_quantity <= 10
      ? 'text-amber-600 bg-amber-50'
      : 'text-green-600 bg-green-50';
```

- It renders that legacy number directly: `apps/pos/src/components/pos/ProductDetailDrawer.tsx:109-119`

```tsx
<div className="flex items-center justify-between text-sm">
  <span className="text-gray-500">{t('productDetail.stock')}</span>
  <span
    className={cn(
      'rounded-full px-2.5 py-0.5 text-xs font-semibold',
      stockColor,
    )}
  >
    {product.stock_quantity}
```

Why this matters:

The spec says the modal opens for everyone and "own-location stock stays visible." Reusing the orphaned drawer as-is would show stale/legacy `stock_quantity` instead of the currently shipped own-location `available`, `incoming_transfer`, and `incoming_po` data used by the grid.

Recommended fix:

Make `ProductDetailDrawer` accept the same `LocationStockDisplay | null | undefined` slice as `ProductCard`, render available quantity through `formatAvailableQty`, and show existing own-location incoming semantics before adding the cross-location section. Add tests for `locationStock` present, null/exempt, and undefined legacy fallback.

## MEDIUM

### M1 - Product-grain aggregation is unsafe for variant products

Evidence:

- Stock is stored per product and optional variant: `apps/api/app/Modules/Inventory/Domain/StockLevel.php:20-24`

```php
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string $location_id
```

- Variant-aware stock uniqueness was added so one product can have multiple variant stock rows per location: `apps/api/database/migrations/tenant/2026_06_02_100005_add_variant_id_to_stock_levels.php:52-57`

```php
DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_non_variant
               ON stock_levels (tenant_id, product_id, location_id)
               WHERE variant_id IS NULL');
DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_with_variant
               ON stock_levels (tenant_id, product_id, variant_id, location_id)
               WHERE variant_id IS NOT NULL');
```

- Transfers are also variant-aware: `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:21-25`

```php
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property numeric-string $quantity
```

- The transfer service rejects product-level transfer lines when active variants exist: `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:871-879`

```php
if ($variantId === null) {
    if ($this->variantLookup->listForProduct($productId, true)->isNotEmpty()) {
        throw new InvalidArgumentException(
            "Product {$productId} has active variants; a variant_id is required for the transfer line."
        );
    }
```

Why this matters:

The spec's v1 response is product-grain and says `variant_id` is optional but ignored. For a variant product, summing all variants can tell a cashier "this product is available somewhere" when the specific requested variant is not. It also conflicts with the transfer service's own invariant that active-variant products require variant-grain stock movement.

Recommended fix:

Do one of these before implementation: require `variant_id` for variant products and return variant-grain distribution; hide the section for products with active variants until variant support lands; or explicitly label the result as "all variants combined" and add tests proving that behavior. Do not silently ignore a supplied `variant_id`.

### M2 - "Incoming" semantics conflict with the existing POS stock feed

Evidence:

- Existing POS location stock incoming includes in-transit transfers and confirmed PO remainders: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:84-88`

```php
 * Complete incoming set for the location: in-transit transfer quantities
 * (variant-grain) merged with confirmed-PO unreceived remainders
 * (product-grain — they land on the variantId = null key).
```

- The service returns both `incomingTransfer` and `incomingPo`: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:164-169`

```php
$rows[] = new LocationIncomingRowDTO(
    productId: $sums['productId'],
    variantId: $sums['variantId'],
    incomingTransfer: $sums['transfer'],
    incomingPo: $sums['po'],
);
```

- The POS tile sums both legs for its "Arriving" badge: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:99-116`

```tsx
const total = bcsum(
  [locationStock.incoming_transfer, locationStock.incoming_po],
  QTY_SCALE,
);
...
if (bccomp(locationStock.incoming_transfer, '0') > 0) {
...
if (bccomp(locationStock.incoming_po, '0') > 0) {
```

Why this matters:

The design proposes a cross-location response field named `incoming` but defines it as transfer-only and explicitly excludes PO incoming. That is a different meaning from the existing POS "incoming/Arriving" term and from `LocationStockQueryService`'s DTO. This is likely to confuse users and future maintainers.

Recommended fix:

Use explicit response fields: `incoming_transfer` and, if intentionally hidden from the first UI, either omit `incoming_po` from the UI or return it separately. If v1 is transfer-only, label the UI and API field `in_transit` or `incoming_transfer`, not generic `incoming`.

### M3 - "All active locations" ignores `pos_enabled` and non-shop location types

Evidence:

- Locations include type, `is_active`, and `pos_enabled`: `apps/api/app/Modules/Company/Domain/Location.php:24-40`

```php
 * @property string|null $code Internal location code
 * @property LocationType $type Location type (shop, warehouse, office, mobile)
...
 * @property bool $is_active Whether the location is active
 * @property bool $pos_enabled Whether POS is enabled at this location
```

- The migration creates those separate columns: `apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:47-53`

```php
// Settings
$table->boolean('is_default')->default(false);
$table->boolean('is_active')->default(true);

// For shops: POS settings
$table->boolean('pos_enabled')->default(false);
```

Why this matters:

The spec says to include "all active company locations" and frames the cashier question as other branches. In the real model, active locations can be warehouses, offices, mobile locations, or shops with POS disabled. Showing all of them may be correct for stock visibility, but the spec does not make that product decision explicit.

Recommended fix:

Decide and document the row set: all `is_active` stock-holding locations, only `type in (shop, warehouse)`, only `pos_enabled` shops, or a grouped/filtered presentation. Add backend tests covering inactive locations, POS-disabled active shops, and warehouse rows.

### M4 - Naive reuse of `LocationStockQueryService::read()` would create an N+1 all-location query

Evidence:

- The existing reader is explicitly single-location: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:40-47`

```php
public function read(
    string $tenantId,
    string $companyId,
    string $locationId,
    ?CarbonImmutable $updatedSince,
    int $page,
    int $perPage,
): LocationStockPageDTO {
```

- Its stock query filters one location: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:53-57`

```php
$paginator = StockLevel::query()
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
    ->where('location_id', $locationId)
```

- Its incoming helper also filters one destination location: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:91-102`

```php
private function incoming(string $tenantId, string $companyId, string $locationId): array
{
...
    ->where('stock_transfers.destination_location_id', $locationId)
    ->where('stock_transfers.status', TransferStatus::InTransit->value)
```

Why this matters:

The spec says to extract an all-locations product query from this service, which is sound. But it does not explicitly forbid calling the existing single-location reader once per location. That would run one stock query plus transfer and PO incoming queries per location and would scale poorly on multi-branch tenants.

Recommended fix:

Add an implementation constraint and test expectation: one locations query, one grouped stock-level query by `location_id`, and one grouped transfer query by `destination_location_id` for the requested product/variant. Do not call `read()` in a loop.

### M5 - Tenant-scoped permission is not company-scoped in multi-company tenants

Evidence:

- `SetPermissionsTeam` sets the Spatie team to the user's tenant, not company: `apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:24-29`

```php
/** @var User|null $user */
$user = $request->user();

if ($user !== null) {
    setPermissionsTeamId($user->tenant_id);
}
```

- Company access is resolved separately from `X-Company-Id`: `apps/api/app/Http/Middleware/CompanyContextMiddleware.php:104-111`

```php
$headerCompanyId = $request->header('X-Company-Id');
if ($headerCompanyId !== null && $headerCompanyId !== '') {
    return $headerCompanyId;
}

// Priority 2: User's default company
return $this->companyContext->getDefaultCompanyForUser($user);
```

Why this matters:

The spec says the feature is gated by a per-user permission and a per-company flag. In the actual system, the permission side is tenant-team-scoped. A user with `pos.view_cross_location_stock` and memberships in multiple companies will carry that permission across those companies; only the company flag varies per company. That may be intended, but the spec should not imply company-specific permission grants.

Recommended fix:

Clarify that `pos.view_cross_location_stock` is tenant-scoped and the company flag is the per-company control. If company-specific role grants are required, add an explicit membership/role check or a company-scoped permission model instead of relying on Spatie team scoping.

## LOW

### L1 - Proposed cache lacks an age/expiry policy for stale offline snapshots

Evidence:

- The existing local stock cache stores server timestamps but has no expiry policy; values are advisory and overwritten by sync: `apps/pos/src/lib/db/migrations.ts:1570-1595`

```ts
// Task 8 (2026-06-12 location-aware stock): cache the terminal's own
// location stock pulled from GET /pos/stock-levels. All quantities are
// TEXT decimal strings (scale-4) — never floats.
...
updated_at TEXT,
PRIMARY KEY (product_id, variant_id)
```

- The existing stock repository warns consumers not to compare decimal strings by equality: `apps/pos/src/lib/db/repositories/locationStockRepository.ts:18-31`

```ts
 * Quantities are decimal STRINGS.
...
 * This is intentional and harmless: EVERY consumer compares quantities with
 * the scale-agnostic bcmath helpers (`bccomp` / `bcsub` in `@/lib/decimal`),
...
 * string equality (`=== '0'`) — that is the one way the tolerance bites.
```

Why this matters:

The spec's `product_stock_distribution_cache` can display arbitrarily old cross-location snapshots when offline. It does include an "as of" label, but it does not define warning or expiry thresholds. Since this feature answers a time-sensitive stock question across locations, an indefinite cached answer is riskier than the own-location operational cache.

Recommended fix:

Define a stale threshold and UI state, for example fresh under 15 minutes, stale warning after 15 minutes, and "connect to refresh" after a configurable maximum. Preserve decimal strings inside `payload` and require consumers to format/compare via existing decimal helpers.

### L2 - Spec should add i18n smoke coverage for the new cross-location keys

Evidence:

- Existing i18n smoke tests assert keys resolve in both locales: `apps/pos/src/__tests__/syncIndicatorI18n.test.tsx:8-16`

```ts
 * corresponding entry in BOTH `en/pos.json` and `fr/pos.json` with
 * non-key fallback text. The component tests intentionally mock i18n;
 * this direct `i18n.t()` resolution check would fail the
```

- The POS app currently has both English and French POS locale files with `productDetail` keys: `apps/pos/src/locales/en/pos.json:446` and `apps/pos/src/locales/fr/pos.json:446`

```json
"productDetail": {
```

Why this matters:

The spec says "All user-facing strings via `t()`" but does not require the existing two-locale smoke-test pattern. Component tests in this repo often mock i18n, so missing `en`/`fr` keys can ship unless directly tested.

Recommended fix:

Add a small i18n smoke test for every new cross-location stock key in both `en/pos.json` and `fr/pos.json`, following the existing direct `i18n.t()` pattern.
