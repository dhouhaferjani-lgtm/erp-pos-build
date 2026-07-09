# Wave 3 Opus Review Reconciliation

Date: 2026-07-08

## Review Artifacts

- `wave3-opus-review.jsonl` — first full-tool Opus attempt; stopped after prolonged partial stream output.
- `wave3-opus-review-findings.md` — bounded diff-only review; found BLOCKER because untracked implementation files were omitted from the diff feed.
- `wave3-opus-review-final.md` — complete diff review; found no BLOCKER and two MAJOR findings.
- `wave3-opus-review-rerun.md` — complete diff review after MAJOR reconciliation; found **NO BLOCKER / MAJOR FINDINGS**.

## BLOCKER Reconciliation

- **B1: implementation files absent from diff feed** — Fixed procedurally by marking new Wave 3 files intent-to-add before rerunning the complete diff review.

## MAJOR Reconciliation

- **M1: minimum-margin formula semantics unverified** — Reconciled with code and test. Existing `MarginService` computes margin as markup on cost, and `DiscountPolicyService` uses the same `cost * (1 + margin/100)` threshold. Added `DiscountPolicyEndpointTest::test_discount_policy_floor_matches_existing_margin_service_threshold()` to verify a 100.00 cost with 10.00 minimum margin floors at 110.00 and matches `MarginService::getMarginLevel()`.
- **M2: sibling-company cost exposure** — Reconciled in code and test. `DiscountPolicyController` now reads the middleware-validated `CompanyContext::requireCompanyId()` instead of trusting the raw `X-Company-Id` header. Added endpoint coverage for sibling-company denial.

## MINOR Reconciliation

- **Foreign product id returned 500** — Fixed. Product subject lookup now throws `DiscountPolicySubjectNotFoundException`, and the endpoint maps it to `404 PRODUCT_NOT_FOUND`.
- **`quantity` accepted but unused** — Accepted for Phase 1. The contract carries line context for document validation/batch reuse, but Wave 3 policy computation is per-unit as specified.
- **Boundary test exact-string scope** — Accepted for Phase 1 as a tripwire; it is backed by the actual Pricing files created in this wave.
- **Verdict redundancy** — Accepted for Phase 1 API ergonomics; frontend can consume stable explicit fields without deriving policy state.

## Verification

```bash
php artisan test tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php tests/Unit/Pricing/DiscountCapResolverTest.php tests/Unit/Pricing/DiscountPolicyServiceTest.php tests/Feature/Pricing/DiscountPolicyEndpointTest.php tests/Architecture/PricingDiscountPolicyBoundaryTest.php
./vendor/bin/pint --test app/Shared/DTOs/DiscountPolicyContext.php app/Shared/DTOs/DiscountPolicySubject.php app/Shared/DTOs/DiscountPolicyVerdict.php app/Shared/Exceptions/DiscountPolicySubjectNotFoundException.php app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php app/Modules/Pricing/Domain/Enums/FloorBasis.php app/Modules/Pricing/Domain/Services/DiscountCapResolver.php app/Modules/Pricing/Domain/Services/DiscountPolicyService.php app/Modules/Pricing/Presentation/Controllers/DiscountPolicyController.php app/Modules/Pricing/Providers/PricingServiceProvider.php app/Modules/Pricing/Presentation/routes.php tests/Unit/Pricing/DiscountCapResolverTest.php tests/Unit/Pricing/DiscountPolicyServiceTest.php tests/Feature/Pricing/DiscountPolicyEndpointTest.php tests/Architecture/PricingDiscountPolicyBoundaryTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php tests/Feature/Product/DiscountPolicySubjectProviderTest.php
./vendor/bin/phpstan analyse app/Shared/DTOs/DiscountPolicyContext.php app/Shared/DTOs/DiscountPolicySubject.php app/Shared/DTOs/DiscountPolicyVerdict.php app/Shared/Exceptions/DiscountPolicySubjectNotFoundException.php app/Shared/Contracts/DiscountPolicyInterface.php app/Shared/Contracts/DiscountPolicySubjectProviderInterface.php app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php app/Modules/Pricing/Domain/Enums/FloorBasis.php app/Modules/Pricing/Domain/Enums/PriceBasis.php app/Modules/Pricing/Domain/Services/DiscountCapResolver.php app/Modules/Pricing/Domain/Services/DiscountPolicyService.php app/Modules/Pricing/Presentation/Controllers/DiscountPolicyController.php app/Modules/Pricing/Providers/PricingServiceProvider.php --memory-limit=2G
git diff --check
```

Results: all passed.
