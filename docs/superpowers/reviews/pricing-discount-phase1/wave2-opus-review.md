# Wave 2 Adversarial Review

## Opus Verdict

CHANGES REQUIRED before reconciliation.

Opus review output:

> Reviewed the provider, DTOs, and contract.
>
> **MAJOR — cross-module model import:** `DiscountPolicySubjectProvider` (Product module) imports and queries `App\Modules\Taxation\Domain\Entities\TaxConfiguration` directly (lines 9, 182). CLAUDE.md Rule 6 forbids importing another module's models — go through a Taxation contract/service.
>
> **MAJOR — cap cascade picks first-defined, not tightest:** `effectiveMaxDiscountPercent` returns the product cap if set, ignoring a tighter category/company cap (DTO 94-107). A 50% product cap overrides a 10% category cap — confirm that's intended vs. min-across-levels.
>
> Otherwise batching, tax-config-before-rate, and margin resolution look correct.

## Reconciliation

- MAJOR cross-module Taxation model import: Accepted and fixed. Product now depends on `App\Shared\Contracts\TaxConfigurationLookupInterface` and `App\Shared\DTOs\TaxConfigurationSummary`; Taxation implements the contract in `TaxConfigurationLookupService` and binds it in `TaxationServiceProvider`. `DiscountPolicySubjectProvider` no longer imports or queries `TaxConfiguration`.
- MAJOR cap cascade first-defined vs tightest: Rejected as a spec conflict. Rev 3 and the task brief explicitly bake in cap priority as product -> category -> company. Added `DiscountPolicyDtoTest::test_product_discount_cap_has_priority_even_when_less_restrictive()` so the priority decision is executable.

## Verification

Passed:

```bash
cd apps/api
php artisan test tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php
```

Result: 7 tests, 18 assertions.

Passed:

```bash
cd apps/api
./vendor/bin/phpstan analyse app/Shared/DTOs/DiscountPolicyContext.php app/Shared/DTOs/DiscountPolicyLineContext.php app/Shared/DTOs/DiscountPolicySubject.php app/Shared/DTOs/DiscountPolicyVerdict.php app/Shared/DTOs/TaxConfigurationSummary.php app/Shared/Contracts/DiscountPolicyInterface.php app/Shared/Contracts/DiscountPolicySubjectProviderInterface.php app/Shared/Contracts/TaxConfigurationLookupInterface.php app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php app/Modules/Product/ProductServiceProvider.php app/Modules/Taxation/Application/Services/TaxConfigurationLookupService.php app/Modules/Taxation/Providers/TaxationServiceProvider.php --memory-limit=2G
```

Result: no errors.

Passed:

```bash
cd apps/api
./vendor/bin/pint --test app/Shared/DTOs/DiscountPolicyContext.php app/Shared/DTOs/DiscountPolicyLineContext.php app/Shared/DTOs/DiscountPolicySubject.php app/Shared/DTOs/DiscountPolicyVerdict.php app/Shared/DTOs/TaxConfigurationSummary.php app/Shared/Contracts/DiscountPolicyInterface.php app/Shared/Contracts/DiscountPolicySubjectProviderInterface.php app/Shared/Contracts/TaxConfigurationLookupInterface.php app/Modules/Product/Application/Services/DiscountPolicySubjectProvider.php app/Modules/Product/ProductServiceProvider.php app/Modules/Taxation/Application/Services/TaxConfigurationLookupService.php app/Modules/Taxation/Providers/TaxationServiceProvider.php tests/Feature/Product/DiscountPolicySubjectProviderTest.php tests/Unit/Shared/DiscountPolicyDtoTest.php
```

Result: pass.

Passed:

```bash
git diff --check
```
