# Task 10 report — recurring expense draft generation

Status: **DONE — focused and Expense regressions green**

## Outcome

- Added `expenses:generate-recurring` as a tenant-scoped, per-company-isolated command.
- Materializes each due Active template through `ExpenseService::create()` inside one outer transaction, links the resulting metadata, and advances the origin-anchored cursor atomically.
- Keeps recurring output Draft/unpaid with explicit company, due document date, null payment date, every template default, and the prefixed period idempotency key.
- Sends the database notification only after commit and only when `wasRecentlyCreated` is true, using the existing company-membership and `expenses.post` recipient filter.
- Added the daily 05:30 in-process schedule with overlap prevention and no background execution.
- Registered the command through the Expense module provider; this is the minimum provider wiring needed for Laravel to expose the new signature.

## TDD evidence

### RED

Command:

```text
cd apps/api
./vendor/bin/phpunit tests/Feature/Expense/GenerateRecurringExpensesCommandTest.php
```

Before any production code existed, PHPUnit exited 2 with 7 tests: six expected `CommandNotFoundException` errors for the absent `expenses:generate-recurring` command and one expected failure because `schedule:list` omitted it. The tests already pinned creation defaults, VAT/partner/category/method/repository/vendor/notes passthrough, Jan-31 clamp, inclusive end behavior, replay dedup/no-re-notification, recipient isolation, missing-author fallback, tenant/company stamping, company timezone, the shared maximum-lead prefilter, error isolation, scheduler shape, and the CompanyContext ban.

The first implementation run exposed an additional discriminating failure: due dates parsed in the application timezone compared later than company-local midnight, so a template exactly on its lead-day threshold was skipped. Parsing the date-only due value in the owning company's timezone fixed the boundary without changing cursor math.

### GREEN

- Final focused command suite: 7 tests / 70 assertions, exit 0.
- The replay test directly proves the load-bearing service contract: the fresh service result has `wasRecentlyCreated === true`, the idempotent re-query has `false`, and the command advances once without a second document or notification.

## Contract details

- Scan partition: tenant plus company plus Active status, ordered by ID; SQL prefilter uses `ExpenseRecurrenceTemplate::MAX_LEAD_DAYS`, followed by the exact company-local `due - lead_days <= today` predicate.
- Transaction: explicit `company_id`, `document_date = due`, `is_paid = false`, no `payment_date`, string money/VAT values, all optional defaults, recurrence metadata link, and cursor/status update share one outer transaction.
- Cursor: `RecurrenceCursor::next(start_date, frequency, current due)` advances exactly once; status becomes Ended only when the next occurrence is strictly greater than the inclusive end date.
- Idempotency: `recurring:{template_uuid}:{period_key}`; existing same-period metadata returns the existing document, links the template if necessary, advances from the current due once, and skips notification.
- Actor: the stored author is tenant-qualified; if absent, the command temporarily sets the Spatie tenant team, chooses the lexically first Active `admin` in that tenant, and restores the previous team in `finally`. The test safely defers the restrictive FK, supplies foreign/inactive/later admin distractors, proves tenant stamping, then restores both the FK value and constraint mode.
- Notification: database type `expense.recurring.generated`; data is exactly `template_name`, `amount`, `currency`, `due_date`, `deep_link`, plus `alert_type` from `TreasuryAlertNotification`. Only active memberships in the template company holding `expenses.post` receive it.
- Isolation: the command copies the mature `forEachTenant` → ordered-company → per-company `try/catch` structure. One invalid company returns command failure but does not stop a later company.
- Context: `CompanyContext` is constructor-only for the parent; the command neither reads nor binds it.

## Regression and quality evidence

- Full Expense feature path: 90 tests / 537 assertions, exit 0.
- Maturity-alert scheduler regression plus `HorizonQueueCoverageTest`: 8 tests / 37 assertions, exit 0.
- `php artisan schedule:list`: `30 5 * * * php artisan expenses:generate-recurring` present.
- Scoped PHPStan at explicit level 8: no errors.
- Scoped Pint: `{"result":"pass"}`.
- PHP syntax checks: command and feature test clean.
- `git diff --check`: exit 0.
- `TreasuryMovementService` task-base diff: empty.
- Horizon configuration/queues: untouched; the notification remains database-only.

The requested combined console-architecture cut ran 9 tests and had one pre-existing failure in `ConsoleCommandTenantContextTest`: `ScanPercentScaleDrift` and `RunEnrichmentCommand` are unclassified. Both offending command files, the architecture test, and its deferral fixture are byte-unchanged from Task 10 base `13388cf948ef33d14ab6bda118a3389c6f4ac703`; Task 10's new command itself extends `TenantScopedCommand`. The independently rerun maturity/schedule and Horizon tests are green as recorded above. No unrelated architecture file was changed.

## Scope and deviations

- Necessary file-list expansion: `ExpenseServiceProvider` registers the command. Without provider registration, Laravel does not expose a command located under the module's `Presentation/Console` directory; the RED suite proved the missing signature. No provider binding or runtime behavior beyond command registration was added.
- No functional deviation from design §6.2 or the reconciled Opus findings.
- No Task 11 forecast, Task 12 UI/i18n, Treasury movement, fiscal, posting, settlement, migration, generated type, queue, or Horizon behavior changed.
- `.superpowers/sdd/progress.md` remained the controller-owned unstaged ledger and was not staged.
