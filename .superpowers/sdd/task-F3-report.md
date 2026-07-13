# Task F3 implementation report

## Result

- Added the reassignment guard in `PaymentRepositoryController::update` after request validation.
- Any request carrying `gl_account_id` opens a transaction, re-fetches the tenant/company-scoped repository with `lockForUpdate`, applies account fallback against that locked row, and re-evaluates whether the GL account actually changes.
- The guard count, possible `DomainException`, and repository update all occur while that row lock remains held. This serializes against `TreasuryMovementService::transfer`, which locks the same repository rows before deciding whether transfer legs may carry a null journal entry.
- Requests that do not carry `gl_account_id` retain the existing non-transactional update path.
- Its tenant-, company-, and repository-scoped query counts only `source_type = transfer` rows whose `journal_entry_id` is null.
- A positive count raises `DomainException`; the global API renderer returns the canonical HTTP 422 `BUSINESS_ERROR` envelope, and the message includes the affected-leg count.
- Reassignment remains allowed with no transfer legs and when all transfer legs carry journal entries.
- `TreasuryMovementService` was not changed.

## TDD evidence

- RED: `PaymentRepositoryTest.php` ran 18 tests with exactly one expected failure: the null-JE fixture received HTTP 200 instead of 422. Both allowed controls already passed.
- GREEN: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositoryTest.php` passed 18 tests and 56 assertions.

## Reviewer concurrency fix

- Root cause: the initial guard counted legs before an unlocked update, so a same-GL transfer could hold/commit its repository locks after the count but before the repository update.
- RED: two focused tests failed. The first observed no repository re-fetch above the test harness's base transaction level; the second deterministically made the controller's initial model stale and received 422 because the guard did not re-check the locked row.
- GREEN: the two serialization tests passed 2 tests / 6 assertions, and the complete focused file passed 20 tests / 62 assertions.
- The suite uses in-memory SQLite, so the regression pins the transaction/re-fetch contract there and additionally asserts `FOR UPDATE` in captured SQL whenever the same test runs on PostgreSQL.

## Additional verification

- Focused PHPStan on the changed controller and test: no errors.
- Pint on the changed controller and test: clean.
- `git diff --check` passed.

## Files changed

- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`
- `apps/api/tests/Feature/Treasury/PaymentRepositoryTest.php`
- `docs/handoff/treasury-phase3-followups-progress.md`
- `.superpowers/sdd/progress.md`
- `.superpowers/sdd/task-F3-report.md`

## Concerns

- None within F3 scope.
