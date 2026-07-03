# Partner Address Import Persistence Report

## Summary

Fixed the demo-critical partner import issue where mapped CSV address fields reached the backend but were dropped by `PartnerService::upsertWithTypeMerge()`.

## Root Cause

`apps/api/app/Modules/Import/Services/ImportService.php` routes partner rows through `PartnerService::upsertWithTypeMerge()`. That service only persisted `name`, `type`, `email`, `phone`, and `vat_number`, so imported `address`, `city`, and `country` values were silently omitted from `partners`.

## Changes

- `apps/api/app/Modules/Partner/Application/Services/PartnerService.php`
  - Persists `address` as `street_address`.
  - Persists `street_address_2`, `city`, `state`, `postal_code`, `country`.
  - Persists `country_code`, defaulting it from `country` when a separate tax country is not provided.
  - Trims string fields and uppercases country codes.
- `apps/api/tests/Feature/Import/ImportInfrastructureTest.php`
  - Added import-path regression coverage for `address`, `city`, `country`, and `country_code`.
  - Cleaned existing test nullability assertions so changed-file PHPStan exits cleanly.
- `apps/web/src/features/partners/PartnerDetailPage.tsx`
  - Makes the existing contact-info address block visible for any available address line, including country-only/postal-only cases.
- `apps/web/src/features/import/pages/ImportWizardPage.tsx`
  - Reuses the wizard's existing API polling to update/complete the global import progress widget when WebSocket completion does not arrive.
  - Prevents the previous initializer from resetting terminal jobs back to `Importing... 0%`.

## TDD Evidence

Red, before fix:

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp.demo-import-map/apps/api/phpunit.xml

F                                                                   1 / 1 (100%)

Time: 00:04.696, Memory: 123.00 MB

There was 1 failure:

1) Tests\Feature\Import\ImportInfrastructureTest::test_partner_import_persists_address_fields
Failed asserting that null is identical to '12 Avenue Habib Bourguiba'.

/Users/houssamr/Projects/syneriva/apps/erp.demo-import-map/apps/api/tests/Feature/Import/ImportInfrastructureTest.php:424

FAILURES!
Tests: 1, Assertions: 1, Failures: 1.
```

Green, after fix:

```text
.                                                                   1 / 1 (100%)

Time: 00:02.738, Memory: 123.00 MB

OK (1 test, 4 assertions)
```

## Verification

- `DB_HOST=127.0.0.1 DB_PORT=5433 ./vendor/bin/phpunit tests/Feature/Import/ImportInfrastructureTest.php --filter test_partner_import_persists_address_fields`
  - Passes: `OK (1 test, 4 assertions)`
- `DB_HOST=127.0.0.1 DB_PORT=5433 ./vendor/bin/phpunit tests/Feature/Import`
  - Passes: `OK (70 tests, 272 assertions)`
- `pnpm --filter @autoerp/web test -- src/features/import src/features/partners/partners.test.tsx`
  - Passes: `4 passed`, `67 passed`
  - Existing warnings printed: React `act(...)`, `--localstorage-file`, and unmatched test routes.
- `pnpm --filter @autoerp/web typecheck`
  - Passes, exit 0.
- `./vendor/bin/phpstan analyse app/Modules/Partner/Application/Services/PartnerService.php tests/Feature/Import/ImportInfrastructureTest.php --memory-limit=1G`
  - Passes: `[OK] No errors`
- `pnpm --filter @autoerp/web test:arch`
  - Exits 0.
  - Output: `[sweep-progress] Gate C - useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 20`
- `git diff --check`
  - Passes, no output.

## Notes

No git commit was created.
