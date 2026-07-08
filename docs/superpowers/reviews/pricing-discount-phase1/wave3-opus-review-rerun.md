# Wave 3 Re-Review — Product Pricing Panel + Discount Policy Cascade (Phase 1)

Gated against Rev 3. Verified each constraint against the diff.

## Gate compliance (verified)

- **No Product/Category/Company model imports in Pricing** — ✅ `DiscountPolicyService`/`DiscountCapResolver`/`DiscountPolicyController` resolve subjects via `DiscountPolicySubjectProviderInterface` (implemented in Product module). Controller imports `Company\Services\CompanyContext` (a service, not a model — permitted cross-module contract). Architecture test enforces this (`PricingDiscountPolicyBoundaryTest.php:15-27`).
- **Endpoint in api/v1 pricing group, guarded by `can:pricing.view_cost_prices`** — ✅ `routes.php:86-88`, inside the `api/v1` + `auth:sanctum` + `SetPermissionsTeam` + `EnforceTokenTenantClaim` group. Cashier-forbidden / manager-ok covered (`DiscountPolicyEndpointTest.php:76-99`).
- **Sibling-company cost non-exposure** — ✅ Controller pins `companyId` from `CompanyContext::requireCompanyId()` (`DiscountPolicyController.php:33`); provider filters `Product::where('company_id', $companyId)` (`DiscountPolicySubjectProvider.php:43`). `test_discount_policy_endpoint_denies_sibling_company_cost_access` asserts `COMPANY_ACCESS_DENIED`.
- **Floor reuses minimum-margin semantics + existing below-cost perms** — ✅ Uses only `pricing.sell_below_cost` / `pricing.sell_below_minimum_margin`; floor `wac*(1+m/100)` cross-checked against `MarginService::getMarginLevel` (`DiscountPolicyEndpointTest.php:135-150`).
- **Max-discount priority product→category→company, first-defined** — ✅ `effectiveMaxDiscountPercent()` unchanged; `DiscountCapResolverTest.php:13-18`.
- **`sale_price` is HT** — ✅ `salePriceNet` set from raw `sale_price` with no TTC conversion even under `PriceEntryMode::Ttc` (`DiscountPolicySubjectProviderTest.php:149-160`).
- **No new override permission** — ✅.
- **No POS / fiscal-chain work** — ✅.

## NO BLOCKER / MAJOR FINDINGS.

## MINOR

1. **Foreign-but-valid `product_id` yields HTTP 500, not 4xx.** A user legitimately scoped to company A who passes a `product_id` belonging to sibling company B (same tenant): provider throws `RuntimeException` (`DiscountPolicySubjectProvider.php:64`) → 500. No cost leak (correctly filtered), but it's an unhandled server error on attacker-controllable input. Prefer mapping the missing-subject case to 404/422. `DiscountPolicyService::resolveMany` similarly throws `InvalidArgumentException` (`DiscountPolicyService.php:52`).

2. **`quantity` is accepted and threaded into the context but never consumed** in resolution (`DiscountPolicyController.php:39`, `DiscountPolicyService`). Dead input for Phase 1 (unit-price floor only) — acceptable, but validate the intent so a future qty-break floor isn't silently assumed present.

3. **Architecture boundary test is exact-string / fixed-file-list only** (`PricingDiscountPolicyBoundaryTest.php:14-18`). It checks three FQCNs across three hardcoded files; an aliased import (`use ... Product as X`) or a new Pricing file would slip past. Adequate as a tripwire, but a directory-glob or reflection-based check would be more durable.

4. **`DiscountPolicyVerdict` carries redundant fields** — `mode` and `floorEnforcement` are both `discountFloorMode`; `severity` is derivable from `blocksSale`/`requiresPermission`. Not incorrect, but it invites drift between the three. Cosmetic.

## Note (not a finding)
A `DiscountCap` floor breach maps to `requiresPermission = pricing.sell_below_minimum_margin` (`DiscountPolicyService.php:113`). That's a deliberate reuse (no new permission per gate) rather than a bug — flagging only so the FE copy doesn't mislabel a cap breach as a margin breach.
