# Wave 3 Adversarial Review — Pricing Discount Policy Phase 1

## BLOCKER

**B1 — Core Wave 3 files are absent from the diff; the primary gates cannot be verified.**
The diff contains only DTOs, routes, the provider (Product module), and generated types. The three files that actually implement the behavior being gated are untracked and **not in this diff**:
- `Pricing/Domain/Services/DiscountPolicyService.php`
- `Pricing/Domain/Services/DiscountCapResolver.php`
- `Pricing/Presentation/Controllers/DiscountPolicyController.php`

Consequences — these gates are **unverifiable** as submitted:
- "Pricing must not import Product/Category/Company models" — `DiscountPolicyService` and `DiscountCapResolver` live in `Pricing\Domain\Services`. A Domain-layer service in Pricing importing `Product`/`Category`/`Company` would be a hard boundary violation. **Cannot confirm.** (The `PricingDiscountPolicyBoundaryTest.php` that presumably asserts this is also not in the diff.)
- "Floor reuses existing minimum-margin semantics and below-cost permissions" — lives entirely in the resolver. **Cannot confirm.**
- "Max discount priority product→category→company" *inside the service* — the DTO helper is correct (see below), but whether the service uses `effectiveMaxDiscountPercent()` vs. reimplementing it is unverified.

Re-run the review with those three files (plus `DiscountPolicyEndpointTest`, the boundary test, and the Unit/Pricing tests) included. Everything below is scoped to what *is* in the diff.

## MAJOR

**M1 — `salePriceNet` is mapped unconditionally from `sale_price`, ignoring `priceEntryMode`.**
`DiscountPolicySubjectProvider` (`salePriceNet: $product->sale_price !== null ? (string) $product->sale_price : null`) labels the value "Net"/HT while also carrying `discountFloorMode` / `priceEntryMode` on the same subject. If a company is `PriceEntryMode::Ttc`, `sale_price` is stored TTC (per the `unit_price`/`sale_price` context-overload note in the precision contract), so the floor/margin math will compare a net floor against a gross price and silently mis-enforce. The Rev 3 gate says "sale_price is HT," so this is gate-*compliant as written*, but there is no guard, conversion, or assertion pinning the company to Ht. Add an explicit guard (or TTC→HT normalization) and a test with a `Ttc` company, or the field name is a lie for half the tenants.

**M2 — `DiscountPolicyVerdict::maxDiscountPercent` is non-nullable `string`, but the effective cap can legitimately be `null`.**
`DiscountPolicySubject::effectiveMaxDiscountPercent()` returns `?string` and returns `null` when product, all category, and company caps are all null (covered by `test_subject_uses_company_cap...` only for the non-null case). The verdict forces a non-null string. Verify the service does not coerce "no cap defined" into `'0.00'` (blocks every discount) or `'100.00'` (allows anything) — either is a footgun and the choice is invisible in this diff. This belongs in the (missing) service; flagging so it gets an explicit test.

## MINOR

**M3 — `terminalId` (and `userId`) added to `DiscountPolicyContext` is POS-shaped scope creep for a web-only Phase 1.**
`DiscountPolicyContext` gains `?string $userId` and `?string $terminalId`. Rev 3 Phase 1 is web-only with "no POS/fiscal-chain work." These are nullable/defaulted and inert, but `terminalId` presupposes a POS terminal identity. Confirm nothing in the (missing) service/controller reads `terminalId` to branch into terminal/fiscal logic, and consider dropping it until the POS phase to keep the contract honest.

**M4 — `FloorBasis::None` added but its handling is unverifiable.**
`enum FloorBasis` gains `case None = 'None';`. Reasonable for advisory/no-floor, but the resolver that must return `floorPriceNet: null` when basis is `None` isn't in the diff. Ensure a `None` basis never emits a non-null `floorPriceNet` and never sets `blocksSale: true`.

**M5 — `DiscountPolicyContext` shape change is breaking; confirm no live consumer.**
The context dropped the embedded `subject: DiscountPolicySubject` in favor of flat scalars (reflected in `generated.d.ts`). If any existing caller constructed the old shape, it breaks. Since this appears to be net-new Phase 1 wiring, likely fine — just confirm no other module already depended on the `subject`-nested form.

## Verified clean (in-diff)

- Endpoint is inside the `api/v1` group and guarded by `can:pricing.view_cost_prices` (`routes.php`). ✓
- Cap priority product→category→company, first-defined, product wins even when less restrictive — `effectiveMaxDiscountPercent()` + `categoryCapsNearestFirst()`, confirmed by `DiscountPolicyDtoTest` and the provider feature test. ✓
- No new permission is defined/referenced in the route layer. ✓ (Full "no new override permission" gate still depends on migrations not shown.)
- `salePriceNet` is folded into `hashPolicyInputs()`, so `policyVersion` reacts to sale-price changes; `policyAsOf` correctly excluded from the hash. ✓
- `quantity` default `'1.0000'` (qty scale) and money as strings — precision-contract compliant. ✓
- Subject provider lives in the **Product** module, so its `Product`/`Category` imports are legitimate (not a Pricing-boundary violation). ✓

**Net: 1 BLOCKER (incomplete diff blocks the two most important gates), 2 MAJOR, 3 MINOR. Do not pass until the service/controller/resolver and their tests are included.**
