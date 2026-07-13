# Task 7 report — recurring expense template schema

Status: **DONE — focused and Expense regressions green**

## Outcome

- Added the `expense_recurrence_templates` tenant table with the complete spec §6.1 field set.
- Added nullable `expense_metadata.recurrence_template_id` linkage with `SET NULL` deletion behavior.
- Added string-backed `RecurrenceFrequency` and `RecurrenceStatus` enums.
- Added the `ExpenseRecurrenceTemplate` UUID model with complete fillable fields, enum/date/decimal/integer casts, default active status, default three-day lead, and scoped entity relations.
- Kept recurrence cursor math, CRUD, permissions, generation, and scheduling out of Task 7.

## TDD evidence

### RED

Command:

```text
cd apps/api
./vendor/bin/phpunit tests/Feature/Expense/ExpenseRecurrenceTemplateModelTest.php
```

Result before production files: exit 2, `5 tests`, `1 failure`, `4 errors`. The schema assertion failed because `expense_recurrence_templates` did not exist, and the four behavior tests errored because `ExpenseRecurrenceTemplate` did not exist. This was the expected missing-feature failure.

### GREEN

The same focused command after implementation exited 0:

```text
OK (5 tests, 55 assertions)
```

The feature test proves:

- the complete template column contract plus the metadata linkage column;
- fillable preservation for every template input field;
- `decimal:3` amount/VAT amount and `decimal:2` VAT percent casts;
- string-backed frequency/status enum casts;
- start/end/next-due date casts and integer lead-day cast;
- database defaults of `lead_days = 3` and `status = active`;
- all four nullable default FKs become null after their target row is deleted;
- metadata linkage is nullable and becomes null after its template is deleted.

## Regression and quality evidence

- `./vendor/bin/phpunit tests/Feature/Expense`: exit 0, `73 tests`, `327 assertions`.
- Scoped PHPStan level 8 over the two enums, model, and feature test: exit 0, `[OK] No errors`.
- Scoped Pint `--test` over all six Task 7 PHP files: exit 0, `{"result":"pass"}`.
- `php -l` over all six Task 7 PHP files: every file reported no syntax errors.
- `git diff --check`: exit 0.
- Migration/schema sanity is exercised by the focused `RefreshDatabase` test: both migrations apply under the PHPUnit SQLite environment, the schema is queryable, and the five real persistence/FK tests pass.

## Scope and deviations

- The initial production commit contains the two tenant migrations, two string-backed enums, and recurrence-template model required by Task 7; the independent-review fix below adds only the metadata model write contract needed to consume the new linkage.
- The only additional file is the required focused feature test; this report and the shared handoff progress log record evidence.
- No Task 8+ cursor, CRUD, permissions, command, notification, frontend, or scheduler behavior was implemented.
- No Treasury, fiscal, posting, settlement, generated-type, or permission-map file changed.
- No implementation deviation from the binding Task 7 plan/spec was required.
- `.superpowers/sdd/progress.md` remained the sole unstaged controller ledger and was not staged.

## Independent-review HIGH fix — metadata mass-assignment contract

- Finding: the initial metadata-link test wrote through `DB::table`, masking that `ExpenseMetadata::$fillable` did not allow `recurrence_template_id`. Task 10's binding consumer path, `$expense->expenseMetadata?->update(['recurrence_template_id' => $template->id])`, would therefore silently discard the linkage.
- RED: the test now uses the real `ExpenseMetadata::update()` path and refreshes the model. Focused PHPUnit exited 1 with 1 failure / 5 tests: the refreshed attribute was null instead of the template UUID.
- Fix: added the `recurrence_template_id` PHPDoc property and fillable entry to `ExpenseMetadata`. No unused relationship was introduced.
- GREEN: focused PHPUnit exited 0 with 5 tests / 56 assertions, including persistence through model mass assignment followed by `SET NULL` after template deletion.
- Fresh regression: the full Expense feature path exited 0 with 73 tests / 328 assertions.
- Fresh quality gates: scoped PHPStan level 8 reported no errors; scoped Pint passed; `git diff --check` passed.
