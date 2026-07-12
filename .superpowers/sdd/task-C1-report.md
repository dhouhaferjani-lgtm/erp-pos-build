# Task C1 Report — Cash-movements direction filter and per-currency totals

## Status

Complete. The cash-movements report now accepts an optional `in|out` direction filter and returns exact full-range totals grouped by currency without changing its existing row or pagination response fields.

## Requirements reviewed

- Plan Global Constraints and Task C1.
- Binding design specification §7.1, §10, and §15 items L2-1 and L2-5.
- Plan-review findings M3 and L4.

## Files

- Modified `apps/api/app/Modules/Accounting/Presentation/Requests/GetCashMovementsRequest.php`.
- Modified `apps/api/app/Modules/Accounting/Application/Services/Reports/CashMovementsReportService.php`.
- Modified `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php`.
- Extended `apps/api/tests/Feature/Accounting/CashMovementsReportTest.php`.
- Updated `.superpowers/sdd/progress.md`.

No frontend, movement-port, fiscal-perimeter, schema, or unrelated module file was changed.

## TDD evidence

1. RED: `./vendor/bin/phpunit tests/Feature/Accounting/CashMovementsReportTest.php` failed the three new focused tests as expected:
   - direction was ignored, so filtered `meta.total` was 2 instead of 1;
   - `meta.totals` was absent;
   - the `per_page=1` filtered-range fixture reported unfiltered `meta.total` 3 instead of 2.
   Result: 14 tests, 3 failures.
2. GREEN: after the request/controller/service changes, the same focused file passed: `OK (14 tests, 85 assertions)`.
3. Final post-format GREEN: `OK (14 tests, 85 assertions)`.

## Contract coverage

- `GetCashMovementsRequest` validates `direction` as nullable `in|out` and exposes a nullable accessor.
- `ReportsController` threads the validated direction into the service.
- The service applies direction immediately after wrapping the payment/journal union and before count, totals, ordering, or pagination. Filtered `meta.total`, `last_page`, `from`, and `to` therefore describe the filtered rows.
- Totals use one SQL aggregate over a clone of the full filtered base: currency and direction grouped with `SUM(CAST(amount AS NUMERIC))`.
- Mixed sources and currencies are pinned with a TND payment, EUR payment, and TND journal-side movement. TND and EUR are formatted at their own scales and are never summed together.
- Missing directions within a currency are zero-filled using `CurrencyScale::bcformatStrict`; each currency net is computed with `bcsub` and formatted once at that currency's scale.
- `per_page=1` returns one row while totals still cover both matching rows across the filtered range.
- No unbounded row fetch or PHP-side summation is used.

## Verification

- `./vendor/bin/phpunit tests/Feature/Accounting/CashMovementsReportTest.php`: `OK (14 tests, 85 assertions)`.
- `php -d memory_limit=1G ./vendor/bin/phpstan --no-progress`: `[OK] No errors`.
- Focused Pint invocation over the four changed PHP files: `{"result":"pass"}`.
- `git diff --check`: exit 0 with no output.

## Deviations

None.

## Concerns

None.
