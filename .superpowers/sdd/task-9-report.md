# Task 9 report — recurrence CRUD and permission maps

Status: **DONE — focused and Expense regressions green**

## Outcome

- Added the seven binding recurrence routes with explicit view/create/update/delete gates; pause and resume both consume update permission.
- Added a company-context-aware FormRequest. All four optional defaults use `ScopedExists::tenantAndCompany`; money/percentage/date/enum/lead-day rules match the Task 9 contract.
- Added CRUD, pause, and resume handling with tenant plus active-company predicates on every model lookup.
- Added the shared `ExpenseRecurrenceTemplate::MAX_LEAD_DAYS = 60` contract for Task 10's pre-filter.
- Added the backend permission catalog/grants and the matching hand-authored frontend map, including `expenses.export`.
- Kept generation, notification, forecast, scheduling, and Task 12 UI behavior out of this task.

## TDD evidence

### Backend RED

Command:

```text
cd apps/api
./vendor/bin/phpunit tests/Feature/Expense/ExpenseRecurrenceCrudTest.php
```

Before production changes, PHPUnit exited 2 with 6 tests: five failed on expected 404 responses from absent routes and the grant-matrix test errored because `expense-recurrences.view` did not exist. This was the intended feature-missing failure, not a fixture or syntax failure.

The test was later extended with a merged-date update case. Its focused RED returned 200 instead of 422 when a new start date moved beyond the stored end date. The controller now validates merged existing/submitted dates before persistence.

### Frontend RED

Command:

```text
cd apps/web
pnpm vitest run src/hooks/__tests__/usePermissions.expenseRecurrences.test.ts
```

Vitest exited 1 because `PERMISSIONS['expense-recurrences.view']` was `undefined`. The test pins exact arrays and explicit create/update/delete/export denials for cashier, operator, and viewer.

### GREEN

- Focused backend: `OK (7 tests, 106 assertions)`.
- Focused frontend recurrence + existing expense map: 2 files / 20 tests, exit 0.
- Full frontend permission-map set: 6 files / 28 tests, exit 0.

## Contract details

- `POST /expense-recurrences` computes `next_due_date` with `RecurrenceCursor::firstOnOrAfter(start_date, frequency, today)` and stamps tenant/company/creator from the authenticated, company-authorized request.
- PUT remains partial. A supplied `frequency` or `start_date` recomputes the cursor from the merged origin/cadence; an end-only update remains valid when it follows the stored start date, while any merged end-before-start state returns 422.
- Resume recomputes directly from the immutable origin/cadence to the first occurrence on/after today, sets Active, and does not create any missed-period documents. Pause only sets Paused.
- Index/show/update/delete/pause/resume resolve through the same query containing both authenticated `tenant_id` and `CompanyContext` `company_id`; sibling-company IDs return 404 rather than leaking existence.
- CRUD/export grants match spec §8.5 exactly: admin (all), manager, and accountant have full recurrence CRUD plus export; cashier, operator, and viewer have recurrence view only.

## Regression and quality evidence

- `./vendor/bin/phpunit tests/Feature/Expense`: exit 0, `80 tests`, `434 assertions`.
- Root `pnpm typecheck`: exit 0 for shared, POS, and web workspaces.
- Scoped PHPStan level 8 over the model, request, controller, seeder, and CRUD test: exit 0, no errors.
- Scoped Pint dirty test: exit 0, `{"result":"pass"}`.
- Focused ESLint over the modified hook and new test: exit 0.
- Design-system audit: 753 acknowledged, 0 new, 0 stale.
- TanStack key audit: 0 acknowledged/new/stale violations.
- `php artisan route:list --path=expense-recurrences`: seven routes.
- `git diff --check`: exit 0.

## Scope and deviations

- `.superpowers/sdd/progress.md` remained the controller-owned unstaged ledger and was not staged.
- No Task 10+ command, notification, forecast, frontend page, scheduler, Treasury, fiscal, posting, settlement, migration, or generated-type file changed.
- No implementation deviation from Task 9, design §§6.1/6.3/8.5, or the verified plan-review findings was required.
