# Task A3 Report — RepositoryTransferService

## RED

Command:

`cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php`

Result: exit 2; `Tests: 10, Assertions: 17, Errors: 3, Failures: 5.` The two A2 tests passed. Every new A3 scenario failed at the missing `RepositoryTransferService`: direct success cases raised `BindingResolutionException`, while rejection cases observed that same missing-class exception instead of their expected domain exception.

## GREEN

Implemented the readonly `RepositoryTransferResult` DTO and the plan-verified `RepositoryTransferService` signature. The service scopes both repositories by tenant/company, enforces active/physical/frozen eligibility, normalizes the amount at the source currency scale, creates a draft JE only for cross-GL transfers inside an outer transaction, calls the existing transfer port with exact named arguments, resolves the result JE from the persisted out leg, and deletes an unreferenced fresh draft.

Verification:

- Focused PHPUnit after implementation: exit 0; `OK (10 tests, 48 assertions)`.
- Pint dirty: exit 0; `{"result":"pass"}`.
- Focused PHPUnit after formatting: exit 0; `OK (10 tests, 48 assertions)`.
- Full PHPStan: exit 0; `[OK] No errors` across 2501 files.
- `git diff --check`: exit 0.

## Files

- `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php`
- `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- `apps/api/tests/Feature/Treasury/RepositoryTransferServiceTest.php`
- `docs/handoff/treasury-phase3-progress.md`
- `.superpowers/sdd/task-A3-report.md`

## Self-review

- Preserved the exact plan-verified constructor dependencies, transfer signature, `TransferIntent` named arguments, and DTO property names.
- Did not edit `TreasuryMovementService`, fiscal code, migrations, or interlocked files.
- `journalEntryId` is read from the persisted out-leg row, not the fresh draft.
- Freeze checks cover both repositories before any draft is created.
- Cross-GL missing-account validation occurs before the transaction and any write.
- Currency mismatch occurs after draft creation in the nested port call; the test proves the outer transaction removes the draft and leaves no movement.
- No insufficient-balance or approval guard was added, matching §5.4.

## Concerns

- PostgreSQL-specific replay/race behavior and direct exercise of the L1-5 compensating-delete branch are intentionally reserved for the dedicated replay coverage in subsequent Wave-A tasks. The A3 suite runs on the configured SQLite fast loop and covers the required rollback failure vehicle.
