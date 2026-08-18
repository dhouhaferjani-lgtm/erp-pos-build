# Task M1A report

## Status

Implemented the P0-1 delivery-note billing projection, typed billing state,
shared uninvoiced predicate, opt-in offset pagination, opt-in aggregates,
PostgreSQL partial index, generated TypeScript contract, and server-filtered
client request.

## Implementation summary

- `DocumentData` now projects `invoiced_at`, invoice id/number, and lane; the
  invoice number lookup is tenant-and-company scoped and legacy stamped rows
  project `legacy_unknown`.
- Added the three-property `DeliveryNoteBillingState` and backed
  `DeliveryNoteBillingLane` enum.
- Added `Document` scopes for complementary invoiced/uninvoiced predicates and
  reused the uninvoiced scope in the compliance service and DN controller.
- `GET /delivery-notes` retains cursor pagination by default; `page` selects
  offset pagination and `with_aggregates=1` adds a full-filtered-set sibling
  aggregate, formatted at the injected company currency scale.
- Added the self-guarding PostgreSQL concurrent partial-index migration.
- Regenerated TypeScript declarations; the client now aliases generated
  `DocumentData` and asks the API for `uninvoiced=1` rather than filtering a
  nonexistent `payload` field client-side.

## Files changed

- `apps/api/app/Modules/Document/Application/DTOs/DeliveryNoteBillingState.php`
- `apps/api/app/Modules/Document/Application/DTOs/DocumentData.php`
- `apps/api/app/Modules/Document/Domain/Enums/DeliveryNoteBillingLane.php`
- `apps/api/app/Modules/Document/Domain/Document.php`
- `apps/api/app/Modules/Document/Presentation/Controllers/DeliveryNoteController.php`
- `apps/api/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php`
- `apps/api/database/migrations/tenant/2026_08_18_000001_add_delivery_note_uninvoiced_index.php`
- `apps/api/tests/Feature/Document/DeliveryNoteBillingProjectionTest.php`
- `apps/web/src/features/documents/api/deliveryNotes.ts`
- `apps/web/src/features/documents/api/deliveryNotes.test.ts`
- `apps/web/src/features/documents/hooks/useDeliveryNotes.ts`
- `apps/web/src/features/documents/hooks/__tests__/deliveryNotesTenantScope.test.tsx`
- `packages/shared/types/generated.d.ts`

## RED evidence

```text
$ ./vendor/bin/phpunit tests/Feature/Document/DeliveryNoteBillingProjectionTest.php --testdox
EEEEFF
Errors included: DeliveryNoteBillingLane not found,
DeliveryNoteBillingState not found; failures showed missing offset metadata and
missing aggregates. Tests: 6, Assertions: 7, Errors: 4, Failures: 2.

$ pnpm exec vitest run src/features/documents/api/deliveryNotes.test.ts
FAIL expected apiGet('/delivery-notes', { status, partner_id, uninvoiced: 1 });
received no uninvoiced parameter. Test Files 1 failed; Tests 1 failed.
```

## GREEN evidence

```text
$ php artisan typescript:transform
Transformed 491 PHP types to TypeScript.

$ ./vendor/bin/pint [changed PHP paths]
PASS (fixed DeliveryNoteController.php and DeliveryNoteBillingProjectionTest.php).

$ ./vendor/bin/phpstan analyse [six changed production PHP paths] --memory-limit=1G
[OK] No errors

$ ./vendor/bin/phpunit tests/Feature/Document/DeliveryNoteBillingProjectionTest.php \
    tests/Feature/Document/DocumentDataReturnDecisionProjectionTest.php \
    tests/Feature/Compliance/UninvoicedDNReportTest.php \
    tests/Feature/Compliance/UninvoicedDeliveryNoteScalingTest.php --testdox
OK, but there were issues! Tests: 27, Assertions: 119, PHPUnit Deprecations: 1.

$ pnpm --dir apps/web exec vitest run src/features/documents/api/deliveryNotes.test.ts \
    src/features/documents/hooks/__tests__/deliveryNotesTenantScope.test.tsx
Test Files 2 passed; Tests 4 passed.

$ pnpm --dir apps/web exec tsc --noEmit --pretty false
exit 0

$ npx react-doctor@latest --verbose --diff
exit 0 (tool reported its `--diff` deprecation and scanned the branch changes).

$ git diff --check
exit 0
```

## Fresh-eyed self-review

- Confirmed the invoice-number resolution is isolated to `DocumentData::fromModel`
  and scopes on both tenant and company.
- Confirmed the payload DTO has exactly the three permitted state fields and no
  invoice-number member.
- Confirmed no change to `HandlesDocuments`, writers, routes/gates, accountants
  seeder, or generated permissions map.
- Confirmed aggregates clone the fully filtered DN query before pagination and
  use scalar SQL plus `CurrencyScale::bcformatStrict`, with no float conversion.
- Confirmed the migration only emits concurrent PostgreSQL DDL and safely no-ops
  under non-PostgreSQL test drivers.

## Concerns

- The focused PHPUnit run reports one existing deprecation; it does not fail.
- The existing delivery-notes tenant-scope Vitest test emits React `act(...)`
  warnings while passing; this slice did not alter its async test flow.
- `typescript:transform` also refreshed a few pre-existing generated enum
  declarations alongside the required `DocumentData` fields; generated output
  is committed as produced, not hand-edited.
