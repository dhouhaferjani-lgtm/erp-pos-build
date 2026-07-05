# Opus adversarial cluster review — api.pricing

Review date: 2026-05-04
Branch tip reviewed: 12ec1df9 (fix); fe0a370e (submit-state)
Reviewer: opus
Owner: codex
Verdict: BLOCK
Commit reviewed: 12ec1df9

## Verdict

BLOCK

## Summary

The 10 inventoried callsites and the bundled hostile-grep blind spots (PriceList route-anchored finds + the `getQuantityBreaks` price_list_id validator) are all closed honestly: gates drop by exactly the expected amounts, the test-pin honesty check fails 14/14 with diagnostics that target the fix surfaces, PHPStan/Pint clean, no `app()` / `@phpstan-ignore` / `mixed` introduced, verify-history reports 0 problems. However, the api.loyalty round-3 lesson — "Codex repeatedly found in-controller blind spots Opus missed by walking EVERY route-mapped method" — surfaces a CRITICAL blind spot here: `PricingController::index` reads `PriceList::with([...])->paginate(20)` with NO tenant_id/company_id predicate, leaking every tenant's price-list catalog (code, name, currency, items) to any authenticated user holding `pricing.view`. This is a direct tenant-boundary breach via a route the scanner cannot see (no `exists:` rule, no `findOrFail`). Two further gaps in `PricingService` (`getDefaultPriceListPrice` + `getPartnerPrice` cross-tenant default-list / join leakage) and one mid-severity gap in `removeFromPartner` (`$partnerId` route param unvalidated) round out the BLOCK.

## Findings

### CRITICAL — `PricingController::index` is wholly unscoped (tenant-boundary breach)

- **Severity**: CRITICAL — direct exploit.
- **Location**: `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:32-47`
- **Issue**: The route `GET /api/v1/price-lists` (gated only by `auth:sanctum` + `can:pricing.view`) executes:
  ```php
  $query = PriceList::with(['company', 'items']);
  if ($request->has('is_active')) { $query->where('is_active', ...); }
  if ($request->has('currency'))  { $query->where('currency', ...); }
  $priceLists = $query->latest()->paginate(20);
  return response()->json($priceLists);
  ```
  No `tenant_id` / `company_id` filter, no `CompanyContext` scoping, no global scope on the `PriceList` model (`apps/api/app/Modules/Pricing/Domain/PriceList.php` has no `booted()` / `addGlobalScope`). The `SetPermissionsTeam` middleware sets the Spatie team id but does NOT inject any query-scoping. Verified by reading `apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php` end-to-end.
- **Impact**: Any tenant-A user with `pricing.view` (the standard read role for the Pricing feature) listing `/api/v1/price-lists` receives the paginated price-list catalog of EVERY tenant in the database, including price-list code, name, currency, validity windows, and (eagerly loaded) PriceListItem rows with `product_id` + `price`. The `with(['company'])` eager-load also exposes Company.name, Company.legal_name, Company.tax_id, Company.country_code for foreign tenants. This is the same class of leak Codex round-3 flagged in `LoyaltyMemberController` follow-ups but here on a route that the inline-validator + findOrFail scanner cannot see.
- **Why scanner missed**: Gate A (TenantScopedExistsRulesTest) only inspects `exists:` validator rules; Gate B (TenantScopedFindCallsTest) only inspects `find()` / `findOrFail()` calls on guarded models. Bare `Model::with(...)->paginate()` lists fall through both. The cluster commit's "hostile-grep blind spots" commentary (commit message lines 24-30) lists the show/update/destroy/addItem/etc. PriceList finds but does NOT mention `index`.
- **Fix required**: Add `CompanyContext::requireCompany()` at the top of `index` and chain `->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)` before `paginate(20)`. Add a regression test alongside the existing `test_show_price_list_query_includes_tenant_and_company_predicates` that asserts `index` returns only same-tenant rows AND a structural-SQL-log invariant that the listing query carries both `"tenant_id"` and `"company_id"` literals.

### IMPORTANT — `PricingService::getDefaultPriceListPrice` selects across tenants

- **Severity**: HIGH (information disclosure; not value extraction).
- **Location**: `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:138-173`
- **Issue**: When `getPrice` falls past the partner-price branch, `getDefaultPriceListPrice` runs `PriceList::where('currency', $currency)->where('is_default', true)->where('is_active', true)->first()` with NO tenant_id / company_id predicate. The `PriceList` model has no global scope, so this picks an arbitrary same-currency default price list across ALL tenants.
- **Impact**: For a same-tenant `productId` (now scoped at the validator tier), the join through `getPriceFromList` to `price_list_items` yields no rows because no `price_list_items` row matches `(foreign-tenant-priceListId, same-tenant-productId)` — so the price value does not leak. BUT the response wrapping `getPrice` returns `'price_list_id' => $priceListId` from the foreign tenant whenever `getPriceFromList` happens to return a non-null value (which it can for shared product UUIDs across migrations or, hypothetically, structural collisions). At minimum, it leaks the existence of foreign tenants with default EUR/USD price lists via timing / response-shape probes.
- **Fix required**: Constructor-inject `CompanyContext` (already injected at line 19 — just reuse `$this->companyContext->requireCompany()`) and chain `->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)` on the `PriceList::where('currency', ...)` builder. Same fix in `getPartnerPrice` (DB-table query at line 93-117) — the join currently traverses `partner_price_lists` → `price_lists` with no tenant filter on either side.

### IMPORTANT — `PricingService::getPartnerPrice` join is cross-tenant unsafe

- **Severity**: MODERATE (defense-in-depth; same shape as the prior finding).
- **Location**: `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:93-117`
- **Issue**: `partner_price_lists` has no `tenant_id` column (verified at `database/migrations/2025_12_01_201044_create_partner_price_lists_table.php`). The transitive scope relies on `partner_id` being same-tenant (now enforced by the validator at `PricingController::getPrice`). However, the join to `price_lists` does NOT filter on `price_lists.tenant_id` / `price_lists.company_id`, so a same-tenant `partner_price_lists.partner_id` row referencing a foreign-tenant `price_list_id` (legacy admin error, race during multi-tenant data migration) would surface a foreign tenant's `price_list_id` in the response. As with finding #2, the price value falls through `getPriceFromList` because the product won't match, but the `price_list_id` field leaks.
- **Fix required**: Add `->where('price_lists.tenant_id', $company->tenant_id)->where('price_lists.company_id', $company->id)` to the join.

### IMPORTANT — `PricingController::removeFromPartner` does not validate `$partnerId`

- **Severity**: MODERATE (delete on bounded but unvalidated row).
- **Location**: `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:288-306`
- **Issue**: The method preloads `$priceList` tenant-scoped (good) and then runs `PartnerPriceList::where('price_list_id', $priceList->id)->where('partner_id', $partnerId)->firstOrFail()`. The `$partnerId` route segment is NOT validated against `partners.tenant_id` / `partners.company_id`. Practical exploitability is low — `firstOrFail` fails when no PartnerPriceList row exists matching `(same-tenant priceList, foreign-tenant partner_id)`. But the symmetric routes show/update/destroy explicitly comment-flag the route-lookup as a blind-spot fix; this one trusts the transitive scope.
- **Fix required**: Either (a) add an explicit `Partner::where('tenant_id', $company->tenant_id)->where('company_id', $company->id)->findOrFail($partnerId)` preload before the `PartnerPriceList` lookup, or (b) chain `->whereExists(fn ($q) => $q->from('partners')->whereColumn('partners.id', 'partner_price_lists.partner_id')->where('partners.tenant_id', $company->tenant_id)->where('partners.company_id', $company->id))` on the lookup. (a) is cleaner and matches the pattern already used in `assignToPartner`.

## Test honesty assessment

A.1 — All 14 test methods exercise the actual route → controller → service path. The 9 inline-validator tests POST to the real route names (`/api/v1/price-lists/{id}/items`, `/api/v1/pricing/get-price`, etc.); the 3 PriceList route-find tests use GET / PATCH / DELETE on `/api/v1/price-lists/{id}`; the 2 structural-SQL invariants enable `DB::enableQueryLog`, hit the route, and inspect the captured queries.

A.2 — Same-tenant controls: `test_add_item_rejects_cross_tenant_product_id`, `test_assign_to_partner_rejects_cross_tenant_partner_id`, `test_get_price_rejects_cross_tenant_product_id`, `test_check_margin_rejects_cross_tenant_product_id`, `test_show_price_list_rejects_cross_tenant_id` all include a sibling same-tenant assertion (201 / 200). The remaining 5 cross-tenant tests (`test_get_price_rejects_cross_tenant_partner_id`, `test_get_quantity_breaks_rejects_cross_tenant_*`, `test_get_bulk_prices_rejects_cross_tenant_*`) do NOT have explicit same-tenant siblings — acceptable because each cross-tenant assertion specifically pins the validator field name (`partner_id` / `price_list_id` / `product_id` / `product_ids.0`) so a regression that 422s on the same-tenant path would still show up via the `assertArrayHasKey` failing on the targeted key. The 3 mutating tests (update / destroy / show) include DB post-condition assertions (`->fresh()?->name === 'Price List B'`, `->fresh() !== null`) — bar-raising.

A.3 — Real two-tenant fixtures with permissions: `setUp` builds `$tenantA` + `$tenantB` + `$companyA` + `$companyB` with distinct slugs / tax_ids. The `pricing.view` and `pricing.manage` permissions are explicitly registered per-tenant via `Permission::findOrCreate(...)` because they are not in `RolesAndPermissionsSeeder` (verified accurate — these were confirmed missing from the canonical seeder). User A is granted both. The product / partner / priceList / priceListItem fixtures correctly assign `tenant_id` + `company_id` on both sides. The test exercises the isolation predicate, not a missing-permission gate.

A.4 — Test-pin honesty check (REQUIRED, performed):

```bash
git checkout 12ec1df9~1 -- apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php apps/api/app/Modules/Pricing/Domain/Services/PricingService.php
vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php
```

Result: **14/14 fail** with diagnostics that pin exactly the fix surfaces:
- `test_add_item_rejects_cross_tenant_product_id` — Expected 422, got 201 (validator was bare).
- `test_assign_to_partner_rejects_cross_tenant_partner_id` — Expected 422, got 201.
- `test_get_price_rejects_cross_tenant_product_id` / `_partner_id` — Expected 422, got 200.
- `test_get_quantity_breaks_rejects_cross_tenant_product_id` — Expected 422, got 200.
- `test_get_quantity_breaks_rejects_cross_tenant_price_list_id` — Expected 422, got 200 (this is the bundled hostile-grep blind-spot price_list_id; honestly tested).
- `test_get_bulk_prices_rejects_cross_tenant_product_id` / `_partner_id` — Expected 422, got 200.
- `test_check_margin_rejects_cross_tenant_product_id` — Expected 422, got 200.
- `test_show_price_list_rejects_cross_tenant_id` — Expected 404, got 200 (foreign price_list returned to caller).
- `test_update_price_list_rejects_cross_tenant_id` — Expected 404, got 200 (foreign price_list update succeeded — exploitable).
- `test_destroy_price_list_rejects_cross_tenant_id` — Expected 404, got 200 (foreign price_list deleted — exploitable).
- `test_check_margin_validator_query_includes_tenant_and_company_predicates` — captured SQL `select count(*) as aggregate from "products" where "id" = ?` lacks `"tenant_id"`.
- `test_show_price_list_query_includes_tenant_and_company_predicates` — captured SQL `select * from "price_lists" where "price_lists"."id" = ? limit 1` lacks `"tenant_id"`.

Restored production code with `git checkout 12ec1df9 -- ...`. Re-ran: **14/14 pass, 40 assertions** in 14.6s. Tests are honest — they fail in exactly the right places when the fix is removed.

A.5 — Service-tier defense-in-depth: the test suite exercises the validator tier (which is the user-facing exploit path); the service-tier `Product::findOrFail` change in `PricingService::getPrice:62` is technically not pinned by a service-direct test. This is documented in the commit message ("service-direct callers (queue jobs, cross-module orchestrators)") and consistent with how the loyalty cluster handled service-tier defense-in-depth. Acceptable as defense-in-depth, not test-anchored.

The test honesty itself is fine. The honesty check does not catch the `index`-method blind spot because no test exists for the `index` route.

## Hostile grep results

```bash
grep -rnE -- "->where\(['\"](id|partner_id|product_id|price_list_id|item_id)['\"]" \
  app/Modules/Pricing/ | grep -v '/tests/'
```
- `Domain/Services/PricingService.php:181` `->where('product_id', $productId)` in `getPriceFromList` — **(b) tenant-anchored transitively** via the (currently unscoped — see Finding #2/#3) priceListId argument. **NEEDS scoping when Finding #2/#3 is fixed.**
- `Domain/Services/PricingService.php:295` `->where('product_id', $productId)` in `getQuantityBreaks` — same shape; called from controller path that already validates both `price_list_id` and `product_id` at validator tier with ScopedExists. Defense-in-depth gap (cross-tenant product_id can reach this method only via a service-direct caller).
- `Presentation/Controllers/PricingController.php:213` / `:236` `->where('id', $itemId)` — **(b) transitively scoped** via tenant-loaded `$priceList->id`.
- `Presentation/Controllers/PricingController.php:298` `->where('partner_id', $partnerId)` — **(d) NEW BLIND SPOT** — see Finding #4 above (`removeFromPartner` `$partnerId` unvalidated).

```bash
grep -rnE -- "exists:(products|partners|price_lists|partner_price_lists)" \
  app/Modules/Pricing/ | grep -v '/tests/'
```
- (no matches) — every `exists:` rule has been replaced by `ScopedExists::tenantAndCompany`. CLEAN.

```bash
grep -rnE -- "::find(OrFail)?\(" app/Modules/Pricing/ | grep -v '/tests/'
```
- (no matches via the `Model::find` form) — but `findOrFail($id)` chained off `Model::where(...)` patterns yield 11 hits, all already preceded by `where('tenant_id', ...)->where('company_id', ...)` chains. CLEAN.

```bash
grep -rnE -- '\$request->(input|header|query)\(.{0,30}(company_id|tenant_id|X-Company-Id)' \
  app/Modules/Pricing/ | grep -v '/tests/'
```
- (no matches) — no request-supplied tenant/company override paths. CLEAN.

```bash
grep -rn 'app(' app/Modules/Pricing/Presentation/ app/Modules/Pricing/Domain/Services/ \
  | grep -v '/tests/'
```
- (no matches). CLEAN.

```bash
grep -rn '@phpstan-ignore' app/Modules/Pricing/
```
- (no matches). CLEAN.

```bash
grep -rn 'new PricingService(' .
```
- (no matches) — no direct instantiation, all callers go through container resolution. The new `CompanyContext` constructor dependency is safe.

**Walk EVERY route in `apps/api/app/Modules/Pricing/Presentation/routes.php`:**

| Route | Method | Classification |
|---|---|---|
| GET /price-lists | `index` | **(d) NEW BLIND SPOT — Finding #1, CRITICAL** |
| GET /price-lists/{priceList} | `show` | (a) tenant-scoped on PriceList lookup |
| POST /price-lists | `store` | scoped — creates with CompanyContext-derived tenant_id+company_id; uniqueness on `code` is global but that's a UX issue, not a tenant breach |
| PATCH /price-lists/{priceList} | `update` | (a) tenant-scoped |
| DELETE /price-lists/{priceList} | `destroy` | (a) tenant-scoped |
| POST /price-lists/{priceList}/items | `addItem` | (a) + (c) — ScopedExists product_id + tenant-scoped PriceList |
| PATCH /price-lists/{priceList}/items/{item} | `updateItem` | (a) + (b) — tenant-scoped PriceList, `$item` transitively scoped via `price_list_id = $priceList->id` |
| DELETE /price-lists/{priceList}/items/{item} | `removeItem` | (a) + (b) — same shape as `updateItem` |
| POST /price-lists/{priceList}/partners | `assignToPartner` | (a) + (c) |
| DELETE /price-lists/{priceList}/partners/{partner} | `removeFromPartner` | (a) for priceList; **`$partnerId` UNVALIDATED — Finding #4** |
| POST /pricing/get-price | `getPrice` | (c) ScopedExists for both product_id + partner_id; **but service path falls into Finding #2/#3** |
| POST /pricing/quantity-breaks | `getQuantityBreaks` | (c) ScopedExists for product_id + price_list_id |
| POST /pricing/calculate-line | `calculateLineTotal` | safe — pure functional, no DB read |
| POST /pricing/bulk-prices | `getBulkPrices` | (c) ScopedExists for product_ids.* + partner_id; **service path falls into Finding #2/#3** |
| POST /pricing/check-margin | `checkMargin` | (c) + tenant-scoped Product findOrFail |

**Result: 1 NEW CRITICAL blind spot (`index`) + 1 NEW IMPORTANT blind spot (`removeFromPartner.$partnerId`) + 2 IMPORTANT service-tier blind spots (`getDefaultPriceListPrice` cross-tenant default + `getPartnerPrice` join unfiltered).** This mirrors the api.loyalty cluster's round-1 → round-3 escalation where Codex repeatedly surfaced same-controller blind spots not in the inventory.

## Architecture gate drops

Pre-cluster baseline (rolled back via `git checkout 5e765516~1 -- apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php apps/api/app/Modules/Pricing/Domain/Services/PricingService.php`, then `vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`):

- Gate A — unscoped exists rules in Presentation tier: **77** → **69** (post-fix). **-8 drop.**
- Gate B — unscoped find()/findOrFail() on guarded models: **79** → **77** (post-fix). **-2 drop.**

Reconciliation:
- Gate A: 8 inline `exists:products|partners` rules removed (addItem.product_id, assignToPartner.partner_id, getPrice.product_id, getPrice.partner_id, getQuantityBreaks.product_id, getBulkPrices.product_ids.*, getBulkPrices.partner_id, checkMargin.product_id). Matches.
- Gate B: 2 `Product::findOrFail` removed (PricingController::checkMargin + PricingService::getPrice fallback). The 7 `PriceList::findOrFail` rewrites do NOT show in Gate B because `PriceList` is **not** in `GUARDED_MODELS` (verified at `tests/Architecture/TenantScopedFindCallsTest.php:46-84`). Matches.
- The bundled `getQuantityBreaks` `price_list_id` ScopedExists is also not counted in Gate A because `price_lists` is not in the `GUARDED_TABLES` list (`tests/Architecture/TenantScopedExistsRulesTest.php:51-92`). The fix is real but invisible to the gate. This is a gate-coverage gap, not a fix-honesty gap; flagging for separate scope.

Numbers honest. Drops match expected fix counts.

## Sibling-controller / cross-cluster survey

`apps/api/app/Modules/Pricing/Presentation/Controllers/` contains exactly one file: `PricingController.php`. No sibling controllers in the Pricing module. The blind-spot survey (above) is exhaustive for the controller surface.

`apps/api/app/Modules/Pricing/Domain/Services/` contains exactly one file: `PricingService.php`. No sibling services. The service-tier blind spots (#2 `getDefaultPriceListPrice`, #3 `getPartnerPrice`) are CO-LOCATED with the fixed `getPrice` fallback — they are not "cross-cluster" but in-cluster blind spots that the api.pricing fix should have addressed.

No other Pricing-module surfaces (e.g., `Application/`, `Console/`, `Infrastructure/`) exist. Verified by `ls apps/api/app/Modules/Pricing/`.

PriceList consumers outside the Pricing module: `grep -rn "PriceList::" app/Modules/ | grep -v Pricing/` returns 0 hits. No cross-module Pricing readers — so the in-cluster fix is the entire Pricing-domain surface.

## What looks good

- **Honest inline-validator surface**: 9 inventoried + 1 blind-spot validators all use `ScopedExists::tenantAndCompany`; no `exists:` regression.
- **Honest PriceList route-find surface**: every `PriceList::findOrFail` is preceded by `where('tenant_id')->where('company_id')`; the show/update/destroy / addItem-priceList / assignToPartner-priceList / updateItem / removeItem hostile-grep blind spots are bundled and tested (3 with DB post-condition assertions for mutating paths).
- **Bar-raising structural-SQL invariants**: `test_check_margin_validator_query_includes_tenant_and_company_predicates` and `test_show_price_list_query_includes_tenant_and_company_predicates` capture queries via `DB::enableQueryLog` and assert BOTH `"tenant_id"` AND `"company_id"` literals — matching the Treasury / Cart / Loyalty pattern, gives durable regression coverage even if the fix shape changes.
- **Constructor injection is clean**: `PricingService` adds `private readonly CompanyContext $companyContext` via constructor, no `app()` helper, no service-locator.
- **Test setUp registers `pricing.view` + `pricing.manage` permissions** that are missing from the canonical seeder — exercises the isolation predicate, not a permission gate. Documented in setUp comments.

## Verification commands

- Test suite (`vendor/bin/phpunit tests/Feature/Pricing/PricingTenantIsolationTest.php`): **14/14 pass, 40 assertions, 14.6s**.
- Test-pin honesty check (revert production diff, run tests, restore, rerun): **14/14 fail pre-fix → 14/14 pass post-restore**. Diagnostics target exactly the fix surfaces.
- PHPStan (`vendor/bin/phpstan analyse app/Modules/Pricing tests/Feature/Pricing/PricingTenantIsolationTest.php --no-progress`): **[OK] No errors**.
- Pint (`vendor/bin/pint --test app/Modules/Pricing tests/Feature/Pricing`): **{"result":"pass"}**.
- verify-history (`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`): **867 events / 268 callsites / 0 problems**.
- Architecture gates (`vendor/bin/phpunit --testsuite=Architecture --group=sweep-progress`): Gate A 69, Gate B 77 (deltas −8 / −2 vs pre-cluster baseline of 77 / 79).
- POS surface diff (`git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher`): **empty**.
- Cross-cluster regression (`vendor/bin/phpunit tests/Feature/Pricing tests/Feature/Loyalty tests/Feature/Cart tests/Feature/Contact tests/Feature/Treasury/TreasuryTenantIsolationTest.php`): **183/183 pass, 637 assertions, 2m18s**.
- `grep -rn 'new PricingService(' .`: **no matches** — no caller depends on direct construction; the new `CompanyContext` constructor parameter is safe.

## Confidence

High on the inventoried-callsite work: every documented callsite is honestly fixed and honestly tested; the test-pin honesty check is unambiguous; the gate drops match the expected fix counts; PHPStan/Pint/verify-history all clean. High on the BLOCK verdict: the api.pricing cluster invariant ("price_lists, products, partners must be scoped by tenant_id + company_id at every Pricing-module read") is violated at four call sites the cluster commit did not touch — `PricingController::index` (CRITICAL exposure), `PricingService::getDefaultPriceListPrice` + `getPartnerPrice` (HIGH/MODERATE in-cluster service-tier leaks), and `PricingController::removeFromPartner` `$partnerId` (MODERATE unvalidated route param). The api.loyalty round-3 lesson — "walk EVERY route-mapped method, not just the inventoried ones" — must be applied here before the cluster flips to `fixed`. Recommend a round-2 fix that scopes `index`, validates `removeFromPartner.$partnerId`, and chains tenant predicates on the two service-tier `PriceList` / DB-table reads, with regression tests for at minimum the `index` (cross-tenant pagination must return 0 rows) and `removeFromPartner` (cross-tenant partner_id must 404) surfaces.
