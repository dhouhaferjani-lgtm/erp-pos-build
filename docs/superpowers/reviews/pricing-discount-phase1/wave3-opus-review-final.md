Reviewing the Wave 3 diff adversarially against the Rev 3 gates. Findings below, severity-tagged, with diff citations.

## BLOCKER

None.

## MAJOR

**M1 — Minimum-margin floor uses markup-on-cost, may not match "existing minimum-margin semantics" (Rev 3 gate).**
`DiscountPolicyService::floorContributions` computes the min-margin floor as `wac × (1 + minMargin/100)`:
```php
$factor = bcadd('1', bcdiv($minimumMarginPercent, '100', $intermediate), $intermediate);
// floor = wac * factor
```
This is **markup-on-cost**. The feature test encodes the same: cost `100.000` + `10.00%` → floor `110.00` (`DiscountPolicyEndpointTest` `assertJsonPath('data.floorPriceNet', '110.00')`). If the pre-existing minimum-margin system defines margin as **margin-on-selling-price** — the far more common ERP convention — the floor should be `cost / (1 − margin) = 100 / 0.90 = 111.11`, not `110.00`. The gate requires reusing *existing* semantics, and this is the single most consequential place they could diverge. **Verify against the existing `MarginResolver` / minimum-margin floor definition before merge.** If the existing system is margin-on-price, this floor silently under-protects every product and the gate is violated.

**M2 — Cross-company cost exposure: `X-Company-Id` header is trusted without membership verification.**
`DiscountPolicyController::__invoke` reads the company from the request header and only 422s when it's absent:
```php
$companyId = $request->header('X-Company-Id');
if (! is_string($companyId) || $companyId === '') { ... 422 ... }
```
Nothing verifies the acting user is a member of that company. The `can:pricing.view_cost_prices` gate is tenant/team-scoped, not company-scoped. In a multi-company tenant, a user with the permission can pass an arbitrary sibling company's id plus one of *its* product ids and receive that company's cost-derived floor (`floorPriceNet`, discount cap, WAC-driven `effectiveUnitPriceNet`). Since the whole endpoint exists to gate *cost* data, this is an authorization gap. If no existing company-scope middleware backstops this route (the group only has `SetPermissionsTeam` + `EnforceTokenTenantClaim`), enforce membership on the resolved company. **Verify / add company-membership check.**

## MINOR

**m1 — `pricing.sell_below_minimum_margin` permission is referenced but not shown to exist.**
The verdict returns `requiresPermission: 'pricing.sell_below_minimum_margin'` (`DiscountPolicyService::PERMISSION_BELOW_MINIMUM_MARGIN`) but no seeder change appears in the diff. Rev 3 forbids a *new* override permission. Either it already exists (fine, gate satisfied) or it's dangling (nobody can override, and it's effectively a new perm without a seeder). Confirm it's a pre-existing permission in `RolesAndPermissionsSeeder`.

**m2 — Cost-floor breach can be mislabeled `minimum_margin_floor`.**
In `resolveForSubject` the below-floor reason is `$floorBasis === DiscountCap ? 'discount_cap' : 'minimum_margin_floor'`. When min-margin is null, the `Cost` contribution can win (`floorBasis='Cost'`), and a breach then reports reason `minimum_margin_floor` even though it's a bare cost floor (and `below_cost` is already emitted). Cosmetic, but the reason string is wrong for that path.

**m3 — `quantity` validated but unused.** The controller validates `quantity` and defaults it to `'1.0000'` into the context, but `DiscountPolicyService` never consumes it. Dead input on the contract (line-level quantity breaks presumably out of Phase-1 scope) — either drop it or document why it's carried.

**m4 — Verdict field redundancy.** `DiscountPolicyVerdict` now carries `floorEnforcement`, `mode`, and (via `severity`/`blocksSale`/`allowed`) three overlapping expressions of the same enforcement state. `mode` and `floorEnforcement` are both `subject->discountFloorMode`. Not a gate issue, but it's redundant surface the FE can desync on.

**m5 — Magic string `'Block'` comparison.** `$blocksSale = ... && $subject->discountFloorMode === 'Block';` compares against a bare literal rather than `DiscountFloorMode::Block->value`. Enums-for-status rule spirit; brittle if the enum value ever changes.

## Gates explicitly checked — PASS

- **No Product/Category/Company import in Pricing** — `DiscountPolicyService` / `DiscountCapResolver` / controller depend only on `Shared\Contracts\*` and `Shared\DTOs\*`; `PricingDiscountPolicyBoundaryTest` guards it (string-search, weak but present). ✔
- **Endpoint placement + guard** — `Route::post('/pricing/discount-policy', ...)->middleware('can:pricing.view_cost_prices')` inside the `api/v1` pricing group. ✔
- **Max-discount priority product→category→company first-defined** — `effectiveMaxDiscountPercent()` + `categoryCapsNearestFirst` (nearest-first), covered by `DiscountCapResolverTest` and DTO test. ✔
- **`sale_price` treated as HT** — `salePriceNet = product->sale_price` with no TTC conversion even under `PriceEntryMode::Ttc` (`test_subject_treats_sale_price_as_ht_even_when_company_entry_mode_is_ttc`); discount-cap floor computed on it as net. ✔
- **Below-cost reuses existing permission** — `PERMISSION_BELOW_COST = 'pricing.sell_below_cost'`, takes precedence via `??=`. ✔
- **Advisory/Warn default, Block opt-in** — `blocksSale` only when mode is `Block`; default Advisory yields `severity='warn'`. ✔
- **No POS/fiscal-chain work** — none present. ✔

Net: no blockers, but **M1 (floor formula semantics) and M2 (company-scope authz) must be verified before merge** — both are gate-adjacent and code-verifiable against the existing margin system and company-context handling respectively.
