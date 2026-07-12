# Treasury Phase 3 — Cash Visibility Progress

- Started: 2026-07-12 (Africa/Tunis)
- Branch: `feat/treasury-phase3-cash-visibility`
- Base: `f1d6c1d30` (`origin/dev` at worktree creation)
- Binding handoff: `docs/handoff/CODEX-treasury-phase3-cash-visibility-2026-07-12.md`

## Tasks

Progress, files touched, test evidence, deviations, and gate verdicts are appended here after each task.

## Deviations

- **2026-07-12 — Amendment A-1 (Task A4, sanctioned):** the transfer endpoint race-shape coverage uses a sequential pre-existing-transfer replay rather than a true two-connection concurrent request. The test first completes the transfer through `RepositoryTransferService` for group G, then POSTs the endpoint with the same group. This deliberately reaches the same unique-violation-inside-savepoint/idempotent-hit path, proves the original persisted JE id is returned, and proves the compensating draft cleanup leaves one posted JE and no drafts. A two-connection harness does not exist in the port suite, and adding one here would introduce database-driver-dependent test infrastructure without changing the production path exercised. This is the exact substitution approved by spec §15 Amendment A-1; no claim of literal concurrency is made.

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

### Task A4 — Transfer HTTP endpoint and `treasury.transfer` permission

- Status: complete
- Files:
  - `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php`
  - `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php`
  - `apps/api/app/Modules/Treasury/Presentation/routes.php`
  - `apps/api/database/seeders/PermissionSeeder.php`
  - `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
  - `apps/api/lang/en/messages.php`
  - `apps/api/lang/fr/messages.php`
  - `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`
  - `.superpowers/sdd/task-A4-report.md`
  - `docs/handoff/treasury-phase3-progress.md`
- Global constraints applied: canonical string money validation; constructor-injected controller; existing Treasury middleware stack; canonical global `DomainException` handler with no catch/rethrow shim; backend locales en/fr only; transfer port, fiscal perimeter, and named UI interlocks untouched.
- RED:
  - `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferEndpointTest.php` — exit 1; `FAILURES! Tests: 7, Assertions: 8, Failures: 7.` Every desired request failed before controller execution because the POST route was absent. Laravel returned 405 rather than a literal 404 because the existing `GET /payment-repositories/{repository}` wildcard recognizes `transfers` as the same URI with the wrong method; this is the honest missing-POST-route RED signal for this route table.
- GREEN and verification:
  - Initial focused GREEN after aligning validation assertions with the existing canonical `error.errors` envelope — exit 0; `OK (7 tests, 80 assertions)`.
  - `cd apps/api && ./vendor/bin/pint --dirty` — exit 0; formatted the endpoint test (`fully_qualified_strict_types`, `ordered_imports`).
  - Post-format `./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferEndpointTest.php` — exit 0; `OK (7 tests, 80 assertions)`.
  - Regression `./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferServiceTest.php` — exit 0; `OK (10 tests, 48 assertions)`.
  - `./vendor/bin/phpstan` — exit 1, incomplete: parallel worker exhausted the configured 512M memory ceiling; no completed code diagnostics.
  - `./vendor/bin/phpstan --memory-limit=1G` — exit 0; `[OK] No errors` across 2503 files.
  - `php artisan route:list --name=payment-repositories.transfers.store` — exactly one POST route at `api/v1/payment-repositories/transfers`.
  - Final combined completion gate (endpoint suite + A3 service regression + PHPStan 1G + Pint + named route + permission occurrence audit + protected-file/lang/catch audits + `git diff --check`) — exit 0; endpoint `OK (7 tests, 80 assertions)`, service `OK (10 tests, 48 assertions)`, PHPStan `[OK] No errors`, Pint `{"result":"pass"}`, one named POST route, and all silent invariant checks passed.
- Contract coverage: permission 403; 201 response shape and both persisted balances; same-repository, sub-scale, negative, and missing-field validation; cross-company 404; client-group sequential replay with the original JE id, one posted JE, no drafts, and unchanged replay balances; Amendment A-1 pre-existing-transfer race shape; canonical `BUSINESS_ERROR` envelopes for frozen, virtual, and inactive repositories.
- Permission parity: `PermissionSeeder` base list contains `treasury.transfer`; `RolesAndPermissionsSeeder` contains it in the permission catalog and beside all three `treasury.adjust` occurrences (catalog, manager bundle, accountant bundle).
- Deviations: Amendment A-1 substitution recorded above. The requested conceptual "route 404 RED" manifested as framework-correct 405 because of the existing wildcard GET route; no test or routing behavior was contrived to misreport it.
