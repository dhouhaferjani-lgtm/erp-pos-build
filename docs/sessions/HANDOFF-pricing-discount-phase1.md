# Product Pricing Panel + Discount Policy Cascade Phase 1 Handoff

Branch: `feat/pricing-discount-panel`

## Shipped

- Discount policy schema and seed defaults, including Rev 3 Advisory default and Block opt-in.
- Product/category/company max discount cascade with product -> category -> company priority.
- Pricing DTO contracts, subject provider, tax lookup boundary, and Pricing-domain discount policy service.
- Guarded `POST /api/v1/pricing/discount-policy` endpoint using `pricing.view_cost_prices`.
- B2B document advisory/block validation for invoices and sales orders using existing `pricing.sell_below_minimum_margin` and `pricing.sell_below_cost`.
- Product detail pricing intelligence panel with HT/TTC display, WAC/last purchase/margin/floor visibility gated by `pricing.view_cost_prices`.
- Product form HT/TTC reconciliation: `sale_price` remains canonical HT; TTC is derived and converted back to HT on input.
- Route manifest regeneration required by the existing manifest drift gate.
- One unrelated PHPStan cleanup: replaced nullsafe partner access on non-nullable `GoodsReceiptData::$purchaseOrder->partner`.

## Verification

- `php artisan test tests/Feature/Pricing/DiscountPolicySchemaTest.php tests/Feature/Pricing/PricingPermissionSeederTest.php tests/Feature/Pricing/DiscountPolicyEndpointTest.php tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Feature/Product/ProductDiscountCapValidationTest.php tests/Feature/Product/CategoryDiscountCapValidationTest.php tests/Feature/Company/CompanyDiscountPolicySettingsTest.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php tests/Feature/Document/DiscountToleranceValidationTest.php tests/Unit/Pricing/DiscountCapResolverTest.php tests/Unit/Pricing/DiscountPolicyServiceTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php`  
  Result: 50 passed / 149 assertions.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=2G`  
  Result: passed.
- `./vendor/bin/pint --test app/Modules/Inventory/Application/DTOs/GoodsReceiptData.php`  
  Result: passed. Full repository Pint was not used as a success gate because it is red on unrelated pre-existing files.
- `php artisan typescript:transform` plus root `git diff --quiet -- packages/shared/types/generated.d.ts`  
  Result: generated types in sync.
- `pnpm --filter @autoerp/web test -- src/features/inventory/components/pricing/pricingMath.test.ts src/features/inventory/components/pricing/PricingIntelligencePanel.test.tsx src/features/inventory/components/pricing/PricingModeCalculator.test.tsx src/features/inventory/ProductDetailPage.test.tsx src/features/inventory/__tests__/ProductFormParapharmacyGate.test.tsx`  
  Result: 15 passed. Vitest emitted existing async `act(...)` warnings in legacy ProductDetail/ProductForm harnesses.
- `pnpm --filter @autoerp/web typecheck`  
  Result: passed.
- `pnpm --filter @autoerp/web audit:keys`  
  Result: passed.
- `bash scripts/factory/check-manifest-drift.sh`  
  Result: passed after regenerating manifests.
- `apps/pos/scripts/check-fiscal-fixture-parity.sh`  
  Result: passed 29 tests.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh`  
  Result: passed.
- `npx react-doctor@latest --verbose --scope changed --base <previous-wave-sha>`  
  Result: no issues found for Wave 5.

## Review Artifacts

- `docs/superpowers/reviews/pricing-discount-phase1/wave2-opus-review.md`
- `docs/superpowers/reviews/pricing-discount-phase1/wave3-opus-review-final.md`
- `docs/superpowers/reviews/pricing-discount-phase1/wave3-opus-review-rerun.md`
- `docs/superpowers/reviews/pricing-discount-phase1/wave4-opus-review-final-rerun.md`
- `docs/superpowers/reviews/pricing-discount-phase1/wave5-opus-review.md`
- `docs/superpowers/reviews/pricing-discount-phase1/wave5-reconciliation.md`
- `docs/superpowers/reviews/pricing-discount-phase1/2026-07-08-final-opus-review.md`

Wave 5 and final Opus review attempts were unavailable: non-trivial `claude -p --model claude-opus-4-8` prompts hung or returned only `Execution error`. This is documented in the artifacts above.

## Browser Trace

Not completed in this worktree. The requested browser trace requires an authenticated tenant/company with product, document, and pricing seed data plus a running API/browser session. I did not fabricate a trace. Coverage for the same user-visible assertions is in the targeted frontend tests and backend document/endpoint tests listed above.

## Deploy Owes

- Run landlord and tenant migrations, including `php artisan migrate` and tenant database migration paths.
- Run `php artisan db:seed --class=CountryPricingRegulationSeeder`.
- Reseed/register pricing permissions.
- Run permission cache reset per tenant database.
- Verify manager/admin roles receive `pricing.view_cost_prices`, `pricing.sell_below_minimum_margin`, and admin-only `pricing.sell_below_cost` according to deployment policy.

## Deferred Scope

- Phase 2 POS device enforcement.
- Redacted signed sync DTO.
- Sealed policy snapshot.
- Fiscal-chain policy snapshot hashing.
- Ingestion audit replay.
- Tunisia pharma enforcement.
