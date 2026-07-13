# Task 8 report — origin-anchored recurrence cursor math

Status: **DONE — focused and Expense regressions green**

## Outcome

- Added the pure static `RecurrenceCursor` domain service with the binding `next`, `firstOnOrAfter`, and `periodKey` APIs.
- Every candidate occurrence is derived from `startDate` with `addMonthsNoOverflow`; no occurrence advances from a previously clamped date.
- The service reads no clock, framework date facade, mutable date state, database state, tenant/company context, or other global dependency.
- Kept recurrence CRUD, generation, notifications, forecast projection, and frontend work out of Task 8.

## TDD evidence

### RED

Command:

```text
cd apps/api
./vendor/bin/phpunit tests/Unit/Expense/RecurrenceCursorTest.php
```

Before the production service existed, the command exited 2 with all 7 tests reaching the expected missing `RecurrenceCursor` API error. The test file already pinned:

- monthly `2026-01-31` → `2026-02-28` → `2026-03-31`, proving no clamp drift;
- quarterly `2026-02-15` → `2026-05-15`;
- yearly leap-day `2024-02-29` → `2025-02-28`;
- monthly `firstOnOrAfter(2026-01-05, 2026-04-20)` → `2026-05-05`;
- before-origin, on-origin, and exact-later-occurrence inclusive boundaries;
- monthly, quarterly, and yearly period-key formats.

### GREEN

The same focused command after the minimal implementation exited 0:

```text
OK (7 tests, 11 assertions)
```

The `next` implementation derives an initial cadence index from the start/current year-month distance, then resolves the first candidate strictly after `current`. `firstOnOrAfter` uses the same origin-indexed calculation with an inclusive comparison. Both regenerate candidates from the original start date, so a February clamp cannot become the next month's anchor.

## Regression and quality evidence

- `./vendor/bin/phpunit tests/Unit/Expense`: exit 0, `7 tests`, `11 assertions`.
- `./vendor/bin/phpunit tests/Feature/Expense`: exit 0, `73 tests`, `328 assertions`.
- Scoped PHPStan level 8 over the service and test: exit 0, `[OK] No errors`.
- Scoped Pint `--test` over the service and test: exit 0, `{"result":"pass"}`.
- Final syntax, diff, and scope checks are recorded in the controller handoff and commit evidence.

## Scope and deviations

- Production scope is exactly `RecurrenceCursor.php`; test scope is exactly `RecurrenceCursorTest.php`.
- The existing Task 7 `RecurrenceFrequency` enum is consumed unchanged.
- `.superpowers/sdd/progress.md` remained the controller-owned unstaged ledger and was not staged.
- No implementation deviation from the binding Task 8 plan or design §6.1 was required.
