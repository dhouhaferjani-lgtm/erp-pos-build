# Treasury Phase 3 — Cash Visibility Progress

- Started: 2026-07-12 (Africa/Tunis)
- Branch: `feat/treasury-phase3-cash-visibility`
- Base: `f1d6c1d30` (`origin/dev` at worktree creation)
- Binding handoff: `docs/handoff/CODEX-treasury-phase3-cash-visibility-2026-07-12.md`

## Tasks

Progress, files touched, test evidence, deviations, and gate verdicts are appended here after each task.

## Deviations

None yet. Task A4 must record the sanctioned Amendment A-1 sequential race-shape substitution.

## Contradictions

None found during pre-flight review.

### Task A1 — Status-scoped treasury-transfer JE uniqueness

- Status: complete
- Files:
  - `apps/api/database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php`
  - `docs/handoff/treasury-phase3-progress.md`
- Verification:
  - `php -l apps/api/database/migrations/tenant/2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php` — no syntax errors.
  - Testing-env SQLite `php artisan migrate ... --pretend --force` — exit 0; emitted the partial unique-index SQL with `status = 'posted'`.
  - Full testing-env SQLite `php artisan migrate --force` on an isolated temporary database — exit 0; A1 migration applied in 0.44 ms.
  - SQLite schema inspection — exact unique index present on `(source_type, source_id)` with `WHERE source_type = 'treasury_transfer' AND status = 'posted'`.
- TDD: binding plan explicitly defers behavioral replay/index coverage to Task A5; A1 is migration-only and was verified by syntax, pretend SQL, real migration, and schema inspection.
- Deviations: the plan's literal pretend command was first cancelled by Laravel's production-environment safety prompt (exit 1). It was rerun non-interactively with the PHPUnit SQLite/testing environment and `--force` (exit 0). No implementation deviation.

### Task A2 — Draft JE factory for inter-repository transfers

- Status: complete
- Files:
  - `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
  - `apps/api/tests/Feature/Treasury/RepositoryTransferServiceTest.php`
  - `.superpowers/sdd/task-A2-report.md`
  - `docs/handoff/treasury-phase3-progress.md`
- RED:
  - `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 2; `ERRORS! Tests: 2, Assertions: 2, Errors: 1, Failures: 1.` Both tests reached the expected missing-feature failure: `Call to undefined method ...GeneralLedgerService::createRepositoryTransferJournalEntry()`.
- GREEN and verification:
  - `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 0; `OK (2 tests, 12 assertions)`.
  - `cd apps/api && ./vendor/bin/phpstan` — exit 1, incomplete: parallel worker exhausted the configured 512M memory ceiling (`Found 2 errors`, both child-process memory crashes; no completed code diagnostics).
  - `cd apps/api && ./vendor/bin/phpstan --memory-limit=1G` — exit 0; `[OK] No errors` across 2499 files.
  - `cd apps/api && ./vendor/bin/pint --dirty` — exit 0; `{"result":"pass"}`.
  - Post-format `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 0; `OK (2 tests, 12 assertions)`.
  - `git diff --check` — exit 0, no output.
- Contract: factory requires an enclosing transaction, creates `treasury_transfer` / OD (`JournalCode::Misc`) with Dr destination and Cr source, returns the entry with lines loaded, and never calls any posting path.
- Deviations: none. The default 512M PHPStan run could not complete; the required full analysis completed cleanly with an explicit 1G ceiling.

### Task A3 — RepositoryTransferService and result DTO

- Status: complete
- Files:
  - `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php`
  - `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
  - `apps/api/tests/Feature/Treasury/RepositoryTransferServiceTest.php`
  - `.superpowers/sdd/task-A3-report.md`
  - `docs/handoff/treasury-phase3-progress.md`
- RED:
  - `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 2; `Tests: 10, Assertions: 17, Errors: 3, Failures: 5.` The two pre-existing A2 tests remained green; all eight new A3 behaviors reached the expected missing-feature failure because `RepositoryTransferService` did not exist.
- GREEN and verification:
  - Initial `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 0; `OK (10 tests, 48 assertions)`.
  - `cd apps/api && ./vendor/bin/pint --dirty` — exit 0; `{"result":"pass"}`.
  - Post-format `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 0; `OK (10 tests, 48 assertions)`.
  - `cd apps/api && ./vendor/bin/phpstan` — exit 0; `[OK] No errors` across 2501 files.
  - `git diff --check` — exit 0, no output.
- Contract coverage: cross-GL transfer posts exactly one JE with Dr destination / Cr source and net-zero paired legs; same-GL transfer posts no JE; source and destination freeze rejection; virtual and inactive rejection in both directions; cross-GL missing-account rejection before writes; currency-mismatch outer rollback leaves no draft or movement; EUR sub-scale input is normalized once at scale 2.
- Deviations: none. Replay/race behavior and the L1-5 fresh-draft compensating-delete path remain assigned to the dedicated replay coverage in subsequent Wave-A tasks; A3's required failure rollback is covered through the currency-mismatch path.
