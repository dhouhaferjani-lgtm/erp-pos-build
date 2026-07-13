# Task F3 implementation report

## Result

- Added the reassignment guard directly in `PaymentRepositoryController::update`, after request validation/account fallback and immediately before `PaymentRepository::update`.
- The guard runs only when `gl_account_id` is present and differs strictly from the repository's stored value.
- Its tenant-, company-, and repository-scoped query counts only `source_type = transfer` rows whose `journal_entry_id` is null.
- A positive count raises `DomainException`; the global API renderer returns the canonical HTTP 422 `BUSINESS_ERROR` envelope, and the message includes the affected-leg count.
- Reassignment remains allowed with no transfer legs and when all transfer legs carry journal entries.
- `TreasuryMovementService` was not changed.

## TDD evidence

- RED: `PaymentRepositoryTest.php` ran 18 tests with exactly one expected failure: the null-JE fixture received HTTP 200 instead of 422. Both allowed controls already passed.
- GREEN: `./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositoryTest.php` passed 18 tests and 56 assertions.

## Additional verification

- Focused PHPStan on the changed controller and test: no errors.
- Pint on the changed controller and test: clean after ordered-import/braces formatting.
- `git diff --check` passed.

## Files changed

- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`
- `apps/api/tests/Feature/Treasury/PaymentRepositoryTest.php`
- `docs/handoff/treasury-phase3-followups-progress.md`
- `.superpowers/sdd/progress.md`
- `.superpowers/sdd/task-F3-report.md`

## Concerns

- None within F3 scope.
