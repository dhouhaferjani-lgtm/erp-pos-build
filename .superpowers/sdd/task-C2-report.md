# Task C2 Report — Cash-position windowed in/out flows

## Status

Complete. The cash-position endpoint now accepts optional `flows_window=1..90` and adds exact, company-currency in/out movement totals without changing the response when the parameter is absent.

## Requirements reviewed

- Plan Global Constraints and Task C2.
- Binding design specification §8.1, §10, and §15 items L2-4 and L2-7.
- Plan-review finding L3.

## Files

- Modified `apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php`.
- Extended `apps/api/tests/Feature/Treasury/CashPositionEndpointTest.php`.
- Updated `.superpowers/sdd/progress.md`.

No frontend, movement-port, fiscal-perimeter, schema, or unrelated module file was changed.

## TDD evidence

1. RED: `./vendor/bin/phpunit tests/Feature/Treasury/CashPositionEndpointTest.php` ran the pre-existing endpoint cases plus the new compatibility, aggregation, currency, and validation coverage. Result: 5 tests, 3 expected failures. The new flow paths were absent and invalid `flows_window=0` still returned 200. The absent-without-param compatibility assertion passed against the pre-change endpoint.
2. GREEN: after the minimal controller implementation, the focused file passed at 5 tests and 36 assertions.
3. Refactor: the absent-without-param assertion was separated into its own named compatibility test. Post-format result: `OK (6 tests, 37 assertions)`.

## Contract coverage

- Without `flows_window`, the response has no `data.flows` key.
- With a valid window, `data.flows` contains integer `window_days` plus canonical decimal-string `in` and `out` totals.
- One SQL aggregate groups `SUM(m.amount)` by direction after joining movements to active `cash_register`, `bank_account`, and `safe` repositories for the current tenant and company.
- The aggregate restricts repositories to the company currency. Foreign-currency repository movements contribute nothing.
- The rolling boundary uses `repository_movements.occurred_at`. The test deliberately gives an included movement an old `created_at` and an excluded movement an in-range `created_at`.
- Inactive and virtual repositories and movements outside the window are excluded.
- Missing directions are formatted as zero with the existing company scale. All totals pass once through `CurrencyScale::bcformatStrict` using the controller's existing `CurrencyScaleResolverInterface` dependency and company currency.
- Invalid values `0`, `91`, and `x` throw `DomainException` and are pinned to the canonical `{error:{code,message}}` 422 envelope.

## Verification

- `./vendor/bin/phpunit tests/Feature/Treasury/CashPositionEndpointTest.php`: `OK (6 tests, 37 assertions)`.
- `php -d memory_limit=1G ./vendor/bin/phpstan --no-progress`: `[OK] No errors`.
- Focused Pint invocation over the changed controller and endpoint test: `{"result":"pass"}`.
- `git diff --check`: exit 0 with no output.

## Deviations

None.

## Concerns

None.
