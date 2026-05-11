# api.inventory cluster — Opus first-layer adversarial review (round 1)

Verdict: APPROVE
Commit reviewed: 1eada1cb

Reviewer: Opus 4.7 (1M context), adversarial first-layer
Branch: feat/tenant-isolation-sweep-execution
Date: 2026-05-04
Cluster: api.inventory (codex-owned, 28 inventoried callsites)
Test file: apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php

---

## Summary

The 28 inventoried callsites in api.inventory are correctly closed by commit
1eada1cb. FormRequest validators that previously took bare `exists:<table>,id`
rules now use `ScopedExists::tenantAndCompany`, `ScopedExists::company`, or
`ScopedExists::tenant` matching each table's actual scope columns
(products = tenant + company; locations = company; users = tenant). Service-tier
`findOrFail` / `find` calls in `StockReservationService::reserveWithFEFO`,
`WeightedAverageCostService::recordPurchase`/`recordSale`/`recordReturn`,
`GoodsReceiptService::receiveGoods`, and `StockAdjustmentService::getOrCreateStockLevel`
now scope the lookup by the input model's own tenant + company (defense-in-depth)
or by an upstream-scoped relation's company (`StockAdjustmentService` scopes
Product by Location.company_id so a forged cross-tenant productId throws
ModelNotFoundException instead of seeding a mixed-tenant StockLevel). Four
FormRequests were refactored to constructor-injected `CompanyContext` — no
`app()` helper drift.

The annotated callsites (.009/.010 breakdown body validators;
.018 recordMovement Location lookup) are correctly classified:

- .009/.010 (breakdown): The route ordering in
  `apps/api/app/Modules/Inventory/Presentation/routes.php` (lines 84 and 96)
  registers `GET /stock-reservations/{id}` before
  `GET /stock-reservations/breakdown`, so Laravel resolves the literal
  `breakdown` as an `{id}` parameter and 404s in `show()` (verified via
  `test_breakdown_route_is_shadowed_by_show_route`). The validator hardening
  in `breakdown()` is genuine defense-in-depth for any future route reorder.
- .018 (recordMovement): The private method receives `tenantId` from the
  upstream `StockLevel` returned by `getOrCreateStockLevel`, which itself
  scopes Product lookup by `Location.company_id`. The Location.findOrFail at
  line 536 cannot escalate beyond the already-tenant-coherent stock_level.

Test honesty check confirmed: reverting CreateCountingRequest,
AddProductToCountingRequest, and StockReservationController body validators
to pre-fix state breaks 9 of 13 tests with the right shape (422 → 201,
VALIDATION_ERROR → INSUFFICIENT_STOCK on reservations, missing tenant_id
predicate in structural-SQL invariant). Restoring brought 13/13 back to green.

PHPStan clean on `app/Modules/Inventory` + the new test file.
verify-history clean: 1089 events / 268 callsites / 0 problems.
POS surface diff `dev..HEAD` empty (POS, Voucher, apps/pos all untouched).

---

## Verification artifacts

```
$ vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php
OK (13 tests, 38 assertions)

$ ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
    app/Modules/Inventory tests/Feature/Inventory/InventoryTenantIsolationTest.php
[OK] No errors

$ php artisan sweep:inventory:verify-history --inventory-path=...
verified 1089 event(s) across 268 callsite(s); 0 problem(s).

$ git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
(empty)
```

Hostile greps inside `apps/api/app/Modules/Inventory` (excluding tests):

- Bare `->where('id'|'partner_id'|'product_id'|'location_id'|'user_id'|'reservation_id'|'counting_id', ...)` chains: 0 hits.
- Bare `exists:(partners|products|users|categories|locations|stock_reservations|inventory_countings|units)` rules: 0 hits.
- Body-supplied `$request->input/header/query` for `company_id` / `tenant_id` / `X-Company-Id`: 0 hits.
- Forbidden `app(CompanyContext` calls: 0 hits.

(One bare `exists` survives in `CountingItemController::triggerThirdCount` —
see Finding 1 below.)

---

## Findings

### Finding 1 — Sibling-controller blind spot (LOW severity, scanner-gap follow-up)

`apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:242`

```php
$request->validate([
    'item_ids' => 'required|array|min:1',
    'item_ids.*' => 'string|exists:inventory_counting_items,id',
]);
```

Bare `exists:inventory_counting_items,id` allows a tenant A admin to enumerate
tenant B's `inventory_counting_items` IDs and have them pass validation.
However, the actual mutation in
`InventoryCountingService::triggerThirdCount` (line 469) filters by
`counting_id` derived from a `forCompany($companyId)`-scoped `InventoryCounting`,
so the UPDATE never touches foreign rows — structurally protected at the SQL
mutate layer.

This controller is NOT in the inventoried 28 callsites and not in the
manual-stub. It is a sibling controller in the same `Presentation/Controllers/`
folder as the fixed `StockReservationController` and exactly the kind of
blind spot called out in
`docs/superpowers/audits/2026-05-04-bare-where-scanner-gap.md` (api.cart
Finding 3 pattern).

**Recommendation**: track in `tenant-isolation-sweep-manual-callsites.yml`
as `cluster_id: api.inventory`, severity low, expected fix
`scope item_ids.* via ScopedExists::company('inventory_counting_items', $company->id)`
or scope by counting parent. NOT a blocker for closing api.inventory cluster
since the actual mutation path is already tenant-coherent.

### Finding 2 — System path uses unscoped `User::first()` fallback (MEDIUM severity, manual-stub candidate)

`apps/api/app/Modules/Inventory/Application/Services/FraudTriggeredCountingService.php:56-57`

```php
$systemUser = User::where('email', 'system@autoerp.local')->first()
    ?? User::first(); // Fallback to first user if system user doesn't exist
```

`User::first()` returns an arbitrary user from any tenant. The result becomes
`createdBy` for an `InventoryCounting` row whose `company_id` is taken from
`$alert->company_id` — so the row itself lands in the correct tenant, but
the `created_by_user_id` and the propagated `tenant_id` (via
`InventoryCountingService::create` → `tenant_id => $createdBy->tenant_id`)
could be from a different tenant entirely. This corrupts cross-tenant audit
trails when the fraud-detection pipeline triggers without a system user
seeded.

This is system-initiated (called from
`Compliance/Services/AnomalyDetectionService`) and not user-controlled, so
it cannot be exploited as an HTTP cross-tenant leak. It is, however, a
correctness bug that breaks audit attribution and tenant_id integrity if
production lacks the `system@autoerp.local` row.

**Recommendation**: track in manual-stub for api.inventory; either require
seeding of the system user (assert non-null) or scope the fallback to
`User::where('tenant_id', $alert->tenant_id)->first()` — but then `$alert`
must carry `tenant_id`. NOT a blocker for closing api.inventory cluster.

### Finding 3 — `createDraft` persists InventoryCounting without `tenant_id` (LOW severity, hygiene)

`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:421-444`

`createDraft` builds an `InventoryCounting` from request input and `$companyId`
but does not set `tenant_id` on the new row. The migration
`2026_03_04_100000_add_tenant_id_and_counting_number_to_inventory_countings`
made `tenant_id` nullable, so persistence succeeds with NULL.

`scopeForCompany` filters by `company_id` only, so subsequent reads remain
tenant-coherent (since `company_id` itself is tenant-scoped through
`UserCompanyMembership`). However:

- Cross-tenant queries that filter on `tenant_id` (e.g. fiscal verify-chains,
  audit-event correlation, `InventoryCountingService::triggerThirdCount`'s
  fallback `$counting->tenant_id ?? $user->tenant_id`) will see NULL for
  draft countings.
- Compare to `InventoryCountingService::create` which explicitly sets
  `tenant_id => $createdBy->tenant_id ?? null` — same column, two paths,
  inconsistent.

**Recommendation**: add `$counting->tenant_id = $companyContext->requireCompany()->tenant_id`
before save. Hygiene fix; not a security leak in the current code paths.
NOT a blocker for closing api.inventory cluster.

### Finding 4 — `StockReservationService::reserveWithFEFO` is dead code (INFORMATIONAL)

The api.inventory.011 fix scopes
`Product::findOrFail($productId)` at line 372 by tenant + company. Solid fix.
However, `reserveWithFEFO` has no external callers anywhere in the codebase
(`grep -rn 'reserveWithFEFO' apps/api/app` returns only the definition).
The store endpoint calls `reserve`, not `reserveWithFEFO`. The fix is
defense-in-depth for future use, but it is currently unreachable.

NOT a blocker. Worth a single-line comment in the source noting "no current
callers; kept for future FEFO endpoint" so future devs don't delete it as
dead code. NOT required for cluster closure.

### Finding 5 — `StockMovementController` validators take bare uuid only, no `exists` (INFORMATIONAL — defense-in-depth holds)

`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:60-61, 99-100, 153-155, 211-212`

The `receive`, `issue`, `transfer`, and `adjust` endpoints validate
`product_id` as `'required', 'string', 'uuid'` — no `exists:` rule and no
`ScopedExists`. Forging a productId from another tenant would pass
validation. However:

- `LocationContext::validateLocationAccess` then verifies the location_id
  belongs to the caller's company (HTTP 500 RuntimeException otherwise).
- `StockAdjustmentService::getOrCreateStockLevel` re-scopes Product by
  `Location.company_id`, throwing ModelNotFoundException for cross-tenant
  productIds.

So the cross-tenant attack is blocked at the service layer. This is exactly
the api.document round-2 Codex Finding 1 pattern (raw foreign FK
persistence) but here the persistence helper performs the scope check, so
no leak.

This controller is NOT in the inventoried 28. Worth tracking in manual-stub
as low-severity for cluster api.inventory follow-up — defense-in-depth could
fail if `getOrCreateStockLevel` is ever inlined or a new public method
bypasses it.

NOT a blocker.

---

## Audit exhaustiveness

Verified:

- All 28 inventory entries (api.inventory.001 through api.inventory.028) carry
  `fix_commit: 1eada1cb` and `regression_test: apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php`.
- Each route-anchored callsite has a corresponding HTTP test that asserts a
  422 with the matching field-error key.
- Two structural-SQL-log invariant tests pin the validator's actual SQL
  shape (tenant_id + company_id for products; tenant_id only for users).
- The annotated callsites (.009/.010, .018) have explicit reachability
  documentation that I independently verified by reading
  `Presentation/routes.php` and tracing `recordMovement` callers.
- Service-tier callsites (.011-.018) are reached through documented public
  paths or are private helpers re-scoped by upstream-trusted entities.

Areas not covered by this commit (legitimately out of scope for api.inventory
or already cited above as scanner-blind):

- `CountingItemController::triggerThirdCount` (Finding 1) — sibling
  controller, scanner-blind, structurally safe.
- `FraudTriggeredCountingService` (Finding 2) — system-initiated, not
  HTTP-reachable.
- `StockMovementController` validators (Finding 5) — sibling controller,
  defense-in-depth holds via `getOrCreateStockLevel`.
- `InventoryOpeningService` Company::findOrFail at line 220 — Accounting
  module call, out of cluster.
- `CountingItemController::override` `whereHas('counting'...)` — already
  company-scoped via relation.

---

## Test honesty check (executed)

Reverted three files to pre-fix state:

```
git checkout 1eada1cb^ -- \
  apps/api/app/Modules/Inventory/Presentation/Requests/CreateCountingRequest.php \
  apps/api/app/Modules/Inventory/Presentation/Requests/AddProductToCountingRequest.php \
  apps/api/app/Modules/Inventory/Presentation/Controllers/StockReservationController.php
```

Test result: `Tests: 13, Assertions: 23, Failures: 9`.

Failures observed (matching the right shape):

1. `test_create_counting_rejects_cross_tenant_product_id` — expected 422, got 201
   (no validator → counting created with foreign productId)
2. `test_create_counting_rejects_cross_tenant_location_id` — same shape
3. `test_create_counting_rejects_cross_tenant_count_user_id` — same shape
4. `test_add_product_rejects_cross_tenant_product_id` — same shape
5. `test_add_product_rejects_cross_tenant_location_id` — same shape
6. `test_create_reservation_rejects_cross_tenant_product_id` — got 422 with
   error.code = `INSUFFICIENT_STOCK` instead of `VALIDATION_ERROR` (request
   reached service, service threw RuntimeException for missing stock).
7. `test_create_reservation_rejects_cross_tenant_location_id` — same shape.
8. `test_create_reservation_validator_query_includes_tenant_and_company_predicates`
   — captured query was `select count(*) as aggregate from "products" where "id" = ?`
   with no `tenant_id` predicate, confirming the structural SQL invariant
   correctly detects the bare `exists` shape.
9. `test_create_counting_user_validator_query_includes_tenant_predicate` —
   request passed without 422, confirming bare validator absence.

Restored files via `git checkout 1eada1cb -- <files>`; re-ran test:
`OK (13 tests, 38 assertions)`. No state drift.

The tests are pinned to the actual cross-tenant rejection shape, not to a
side-effect query. If a future developer removes the validator and the
service-layer error swallows the cross-tenant error as a generic 422, tests
6 and 7 would still fail (they assert `error.code = VALIDATION_ERROR`). Solid
honesty.

---

## Confidence statement

I attempted to find leaks via:

- Reading every file modified in commit 1eada1cb end-to-end.
- Hostile-grep audits per the bare-where-scanner-gap doc — clean within
  the inventoried surface.
- Sibling-controller sweep of every `*.php` in
  `Inventory/{Presentation,Application,Domain}` — found 5 caveats, all
  classified above.
- Test honesty check by reverting three representative fixes and observing
  9 of 13 tests fail with the correct shape.
- Reachability traces for service-tier fixes (StockReservationService,
  WeightedAverageCostService, GoodsReceiptService, StockAdjustmentService).
- Route-ordering verification for the .009/.010 unreachable-route claim.
- PHPStan, verify-history, and POS-surface-diff confirmation.

No blocking finding. The 5 observations are scanner-blind sibling surfaces,
system-initiated paths, hygiene issues, or dead code — none constitute
exploitable cross-tenant leaks in the current code paths. They should be
tracked in `tenant-isolation-sweep-manual-callsites.yml` for follow-up but do
not block the api.inventory cluster moving from `in_progress` to
`under_review` or `closed`.

I am confident the api.inventory cluster fix commit 1eada1cb is correct,
complete for the inventoried surface, and structurally protected at the
sibling surfaces I examined.

**Verdict: APPROVE.**
