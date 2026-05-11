# Opus adversarial review — api.cart cluster (round 1)

Review date: 2026-05-04
Branch tip reviewed: 65f291a2 (with subsequent compliance hotfix at 009bd760; review-relevant commit unchanged)
Reviewer: opus (first-layer adversarial review)

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED
Commit reviewed: 65f291a2

## Summary

The api.cart cluster fix at 65f291a2 cleanly closes both inventoried Partner-read callsites (api.cart.001 in `convertToPurchaseOrder`, api.cart.002 in `convertToSalesOrder`) by leading with `where('tenant_id', $company->tenant_id)->where('company_id', $company->id)` against the constructor-injected `CompanyContext`. The 9 hostile-grep `CatalogCart::query()` chains in `CatalogCartController` (show / update / destroy / addItem / updateItem / removeItem / convert / marketplaceCheckout) all now lead with `where('tenant_id', ...)` predicate, satisfying the cluster invariant Codex established in Treasury round-3 Finding 14. The `convert()` validator was hardened with `ScopedExists::tenantAndCompany('partners', ...)` on `customer_id`, giving two-tier defense (validator + service) matching the Treasury template. The 7-test regression suite is honest — pre-fix re-run confirms 4 of 7 tests fail correctly (cross-tenant customer_id was 201/accepted; cross-tenant supplier was linked into the PO; show query lacked tenant_id predicate; convert validator did not run any partners exists query). PHPStan clean, Pint clean. Inventory.yml flips at 65f291a2 are limited to api.contact (the Codex-owned api.cart.001/002 entries remain at `in_progress` awaiting Codex-side `sweep:inventory:submit` — that's the expected workflow stage, not a missing flip).

## Findings

1. **Severity: NICE-TO-HAVE** — `CatalogCartController::index()` (line 42–48) is the only `CatalogCart::query()` chain in the controller that does NOT lead with `where('tenant_id', $company->tenant_id)`; it remains bare `where('company_id', $company->id)`. This is a list query (no route-param anchor), so it does not violate the strict reading of the Treasury Finding-14 invariant (BOTH predicates on every read whose anchor came from a route param). It is structurally protected: `CompanyContextMiddleware` validates `UserCompanyMembership` before `requireCompany()` resolves, so a cross-tenant `X-Company-Id` header is rejected at the membership tier. Same pattern exists in `ContactController::index()` (a2436417, accepted in Opus api.contact round-1 review as Finding 2). Flagging only because the same controller has 9 sibling chains that all DID get the `tenant_id` predicate added — adding it to `index()` for a one-line consistency win would be cheap and would future-proof against a membership-table side-channel that hands a tenant-A user access to a tenant-B company.
   - File: `apps/api/app/Modules/Cart/Presentation/Controllers/CatalogCartController.php:42-48`
   - Suggested fix: prepend `->where('tenant_id', $company->tenant_id)` to the chain. Or defer with a `// structurally_protected_by_upstream_membership_guard` annotation. Not blocking.

2. **Severity: NICE-TO-HAVE (defense-in-depth gap)** — `addItem()` validator (line 174–188) accepts `preferred_supplier_partner_id` as `nullable|string|uuid` with NO `ScopedExists::tenantAndCompany` check, while the new `convert()` validator DID get hardened on `customer_id`. A tenant-A user can therefore POST a tenant-B partner UUID and have it persisted on a `CatalogCartItem` row. The api.cart.001 service-layer fix in `convertToPurchaseOrder` correctly catches this at conversion time (cross-tenant `Partner::find` returns null and the existing fallback creates a same-tenant generic supplier — the test confirms this), so there is no cross-tenant data leak in the PO output. But the cart row carries a foreign tenant's UUID until conversion, which (a) violates the same two-tier defense template the customer_id callsite just got, and (b) creates a side-channel for marketplace flows where the same FK could be read by other modules. The customer_id and preferred_supplier_partner_id symmetry argues for hardening both validators.
   - File: `apps/api/app/Modules/Cart/Presentation/Controllers/CatalogCartController.php:185`
   - Suggested fix: `'preferred_supplier_partner_id' => ['nullable', 'string', 'uuid', ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id)]`. Add a regression test asserting cross-tenant `preferred_supplier_partner_id` on `addItem` returns 422. Not blocking (covered structurally by the api.cart.001 service-layer fix), but matches the Treasury two-tier template and would close the symmetry gap.

3. **Severity: OUT-OF-SCOPE referrals (not api.cart blind spots)** — Three Cart-module bare-find calls anchored on user-controlled FK columns belong to other clusters and should NOT be folded into api.cart but should be tracked elsewhere if not already inventoried:
   - `MarketplaceCheckoutService.php:50` — `StockReservation::find($item->reservation_id)` (Inventory cluster).
   - `MarketplaceCheckoutService.php:60,83` — `MarketplaceListing::find($item->marketplace_listing_id)` (Marketplace cluster, two callsites).
   - `CartService.php:87,116` — `MarketplaceListing::findOrFail`, `StockReservation::find` (same).
   These are owned by Marketplace / Inventory clusters per the inventory.yml `cluster_id` discipline; not regressions introduced by this commit.

## Audit exhaustiveness

- Hostile-grep result count: 6 `where('id|partner_id|cart_id|item_id|customer_id|preferred_supplier_partner_id', ...)` matches in `apps/api/app/Modules/Cart/` (excluding tests):
  - 4 in services / controller using `where('cart_id', $cart->id)` — all category (b) structurally protected (the `$cart` is loaded via the now-scoped `CatalogCart::query()->where('tenant_id')->where('company_id')->...->findOrFail($id)` chain upstream, so cart_id is provably tenant-scoped).
  - 2 are the `CatalogCartItem::where('cart_id', $cart->id)->findOrFail($itemId)` lines in `updateItem` / `removeItem` — same category (b), upstream cart is tenant-scoped.
- Wider `where(...)` grep (excluding tenant_id / company_id / user_id / cart_id / source / deleted_at) returned only 2 hits in `CatalogCart` model scopes (`status` + `is_shared` predicates) — both compose onto an already tenant-scoped query.
- `Partner::(find|findOrFail|first|firstOrFail|where)` grep across Cart module returned ONLY the 2 fixed callsites. No leftover Partner reads.
- `(CatalogCart|CatalogCartItem)::` grep across Cart module — every static read accounted for (controller chains all leading with tenant + company; service `whereIn('id', $itemIds)->where('cart_id', $cart->id)` chains all category (b) structurally protected).
- `CartService::createCart` (line 33–41) sets `tenant_id` from `$user->tenant_id`, not from `$company->tenant_id`. Structurally safe because middleware enforces user-company-tenant coherence via `UserCompanyMembership`. Worth noting only if a future change weakens that middleware.
- Test setUp honesty: `supplierB`, `customerB` created with `tenant_id => tenantB->id` and `company_id => companyB->id` (lines 157–174). Cross-tenant assertions are real, not false positives.
- Pre-fix bug-reproducibility: I checked out the parent commit's versions of `CartConversionService.php` and `CatalogCartController.php`, ran `vendor/bin/phpunit tests/Feature/Cart/CartTenantIsolationTest.php`, observed **4 / 7 tests fail** with the exact assertion shapes claimed by the fix narrative (cross-tenant customer_id was 201, cross-tenant supplier was linked, show SQL lacked `"tenant_id"`, convert never ran a partners exists query). Restored HEAD; suite passes 7 / 7 / 22 assertions. Tests are not vacuous.
- Structural-SQL-log honesty:
  - `test_show_query_includes_tenant_and_company_predicates` filters on `from "catalog_carts" + limit 1`, correctly capturing the `findOrFail` query (not the eager-load `with('items')` query, which targets `catalog_cart_items`).
  - `test_convert_validator_query_includes_tenant_and_company_predicates` filters on `from "partners" + "id" = + (exists | count(*))`, correctly capturing the `Rule::exists` validator query (the service-layer `Partner::where(...)->findOrFail` would miss the `exists`/`count(*)` substring filter, so the test pins the validator-tier query specifically — exactly the right grain).
- ScopedExists semantics: the helper composes `Rule::exists(table, col)->where('tenant_id', ...)->where('company_id', ...)` → SQL `select count(*) from "partners" where "id" = ? and "tenant_id" = ? and "company_id" = ? and "deleted_at" is null`. Validator runs pre-controller, so cross-tenant customer_id surfaces as 422 with `error.errors.customer_id` (test asserts this exact shape on line 229).
- Tests run:
  - `vendor/bin/phpunit tests/Feature/Cart/CartTenantIsolationTest.php` → **OK (7 tests, 22 assertions)**
  - PHPStan `app/Modules/Cart + new test` → **[OK] No errors**
  - Pint `app/Modules/Cart + new test` → **{"result":"pass"}**

## Confidence

High confidence the api.cart cluster is closed against the Codex Finding-14 invariant for both inventoried callsites (api.cart.001/002) and the 9 hostile-grep controller blind spots. The two structural-SQL-log tests pin SQL shape (not just behavior), so a future regression that drops one of the predicates would fail loudly. Pre-fix bug-reproduction confirms the tests exercise real defects, not vacuous green lines.

What I could have missed:
- The two NICE-TO-HAVE findings (index() bare where, addItem missing ScopedExists) are not exploitable today via inventoried surfaces — both are structurally protected — but they break symmetry with the rest of this cluster's hardening and with the Treasury two-tier template. If the orchestrator wants strict invariant adherence (every CatalogCart::query() chain in the file leads with both predicates; every partner-id body param is two-tier guarded), they should be folded back in. If the orchestrator accepts category (b)/(c) deferrals consistent with `api.contact` round-1 Finding 2, neither is blocking.
- I did not run the broader Treasury / Compliance regression sweep; the in-tree commit message claims they were run and clean, and the Cart-only suite is the cluster gate per inventory.yml. Confidence on broader regressions inherits from the commit message rather than re-verification.
- I did not exhaustively trace event listeners that consume Cart events from other modules. If a `CartItemAdded` listener reads `Partner::find($item->preferred_supplier_partner_id)` outside this module, that read is a separate cluster's responsibility (Inventory/Marketplace) and would already be tracked in Gate B's 102-unscoped-find counter.
