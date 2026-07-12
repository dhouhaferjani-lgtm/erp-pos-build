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

## Gate 1 — rc1 verification (2026-07-12)

- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury`: exit 0 — `Tests: 573, Assertions: 2301, PHPUnit Deprecations: 39, Skipped: 22` in 05:22.172. The deprecations/skips are existing suite noise; no failures or errors.
- A5 in-test `treasury:reconcile --tenant=<fixture tenant>` pin: green within the suite (focused evidence: 1 test, 9 assertions; endpoint file: 8 tests, 89 assertions).
- `cd apps/api && ./vendor/bin/phpstan --memory-limit=1G`: exit 0 — `[OK] No errors`, 2503/2503 files.
- `cd apps/api && ./vendor/bin/pint --dirty`: exit 0 — `{"result":"pass"}`.
- `git diff origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`: exit 0 with empty output; the port is byte-untouched.
- Opus adversarial review: `docs/handoff/gate-reviews-phase3/GATE-1-rc1.md` — `VERDICT: APPROVE`; no BLOCKER/HIGH/MEDIUM findings.
- Review note: `origin/dev` advanced 20 commits after the sanctioned base, contaminating a literal two-dot range with phantom deletions. The reviewer used the merge-base authored diff and verified the port under both forms. The required Wave-D interlock/rebase check remains pending before frontend work.

## Gate 2 — rc1 verification (2026-07-12)

- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury`: exit 0 — `Tests: 586, Assertions: 2345, PHPUnit Deprecations: 39, Skipped: 25` in 05:22.172; no failures or errors.
- `cd apps/api && ./vendor/bin/phpunit tests/Feature/Notification`: exit 0 — `OK (6 tests, 43 assertions)`.
- Real PostgreSQL DB-per-tenant recipient pin: `DB_CONNECTION=pgsql DB_DATABASE=autoerp_treasury_test DB_HOST=/tmp DB_USERNAME=houssamr DB_PASSWORD= ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`: exit 0 — `OK (4 tests, 11 assertions)`, including two-tenant cache isolation, active-company deny direction, and inactive membership exclusion.
- Zero-count maturity anti-spam pin: `./vendor/bin/phpunit tests/Feature/Treasury/InstrumentMaturityAlertsTest.php --filter test_maturity_alert_sends_no_notification_when_counts_are_zero`: exit 0 — `OK (1 test, 3 assertions)`.
- `cd apps/api && php -d memory_limit=1G ./vendor/bin/phpstan --no-progress`: exit 0 — `[OK] No errors`.
- `cd apps/api && ./vendor/bin/pint --dirty`: exit 0 — `{"result":"pass"}`.

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

### Task A5 — Mixed-transfer reconcile-green pin

- Status: complete
- Files:
  - `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`
  - `.superpowers/sdd/task-A5-report.md`
  - `.superpowers/sdd/progress.md`
  - `docs/handoff/treasury-phase3-progress.md`
- Pin coverage: one cross-GL endpoint transfer (cash → bank), one same-GL endpoint transfer (cash → safe), and one replay of the cross-GL transfer with the same client `transfer_group_id`. The test clears `CompanyContext`, calls `treasury:reconcile` with `['--tenant' => $this->tenant->id]`, and asserts exit 0, zero frozen repositories, and zero `treasury.reconcile.drift` audit events.
- TDD/pinning evidence:
  - First run immediately after adding the pin: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferEndpointTest.php --filter test_reconcile_stays_green_after_mixed_transfers` — exit 0; `OK (1 test, 9 assertions)`. This is the binding plan's expected green-pin outcome when A1–A4 are correct; no production correction was required.
  - `cd apps/api && ./vendor/bin/pint --dirty` — exit 0; `{"result":"pass"}`.
  - Post-Pint focused pin: exit 0; `OK (1 test, 9 assertions)`.
  - Full endpoint suite: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferEndpointTest.php` — exit 0; `OK (8 tests, 89 assertions)`.
  - `cd apps/api && ./vendor/bin/phpstan --memory-limit=1G` — exit 0; `[OK] No errors` across 2503 files.
- Protected perimeter: no reconcile command, `TreasuryMovementService` port, fiscal file, or named UI interlock was edited.
- Deviations/concerns: none.

### Task B1 — Tenant notifications table

- Status: complete
- Files:
  - `apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php`
  - `.superpowers/sdd/task-B1-report.md`
  - `.superpowers/sdd/progress.md`
  - `docs/handoff/treasury-phase3-progress.md`
- Contract: Laravel-standard tenant `notifications` table with UUID primary key, string type, UUID morph columns and their standard composite index, JSONB data, nullable timezone-aware `read_at`, timezone-aware timestamps, and the secondary `(notifiable_type, notifiable_id, read_at)` polling index. No `company_id` column was added, per spec §6.1.
- TDD boundary: the binding plan assigns `$user->notify(...)` behavior smoke to B2's feature-test file. B1 stayed migration-only to avoid creating B2-owned scaffolding; RED was the absent locked migration path, followed by syntax, pretend SQL, real SQLite apply, and schema inspection.
- Verification:
  - `php -l apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php` — exit 0; no syntax errors.
  - Testing-env isolated SQLite `php artisan migrate ... --pretend --force` — exit 0; emitted the table plus the standard morph index and required secondary polling index in exact column order.
  - Testing-env isolated SQLite real `php artisan migrate ... --force` — final gate exit 0; migration applied in 2.58 ms.
  - Direct `PRAGMA table_info(notifications)` — eight expected columns; UUID-backed `id` primary key; `read_at` nullable.
  - Direct `PRAGMA index_info(...)` — standard morph index ordered `(notifiable_type, notifiable_id)` and required polling index ordered `(notifiable_type, notifiable_id, read_at)`.
  - Isolated SQLite `php artisan migrate:rollback ... --force` — exit 0 in 1.58 ms; subsequent `sqlite_master` inspection confirmed the table was absent.
  - `./vendor/bin/pint --dirty` — exit 0; `{"result":"pass"}`. `git diff --check` — exit 0, no output.
- Deviations/concerns: none. Database-channel behavior smoke remains assigned to B2 exactly as planned.

### Task B2 — Slim Notification module inbox read API

- Status: complete
- Files:
  - `apps/api/app/Modules/Notification/Providers/NotificationServiceProvider.php`
  - `apps/api/app/Modules/Notification/Presentation/routes.php`
  - `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php`
  - `apps/api/bootstrap/providers.php`
  - `apps/api/tests/Feature/Notification/NotificationEndpointsTest.php`
  - `.superpowers/sdd/task-B2-report.md`
  - `.superpowers/sdd/progress.md`
  - `docs/handoff/treasury-phase3-progress.md`
- TDD evidence:
  - RED before module/provider registration: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Notification/NotificationEndpointsTest.php` — exit 1; `FAILURES! Tests: 6, Assertions: 7, Failures: 4.` Each implemented endpoint expectation received the required 404. The B1 database-channel smoke and malformed-UUID route check already passed, as expected at this boundary.
  - Initial GREEN after provider/routes/controller registration: same command — exit 0; `OK (6 tests, 43 assertions)`.
  - Post-Pint focused GREEN: same command — exit 0; `OK (6 tests, 43 assertions)`.
- Contract coverage: B1 `$user->notify(...)` database-channel row smoke; caller-only descending pagination with exact `{data,meta}`; `filter=unread|all`; caller-only unread count; foreign notification read returns 404; owned read is idempotent and preserves the original `read_at`; malformed non-UUID ID returns 404; read-all affects only the caller. Every controller query starts at the authenticated user's `notifications()` or `unreadNotifications()` relation.
- Route audit: `php artisan route:list --path=api/v1/notifications -v` listed exactly the four specified routes. Each carries, in order, `api`, `auth:sanctum`, `SetPermissionsTeam`, and `EnforceTokenTenantClaim`; there is no `can:` gate. The single-item read route is constrained with `whereUuid('id')` in the route declaration.
- Static verification:
  - First `./vendor/bin/phpstan --memory-limit=1G` found one `method.nonObject` diagnostic for framework-nullable `created_at`; serialization was made null-safe without suppression or inferred-type override.
  - Rerun `./vendor/bin/phpstan --memory-limit=1G` — exit 0; `[OK] No errors` across 2506 files.
  - `./vendor/bin/pint --dirty` — exit 0; formatted the endpoint test (`new_with_parentheses`, `fully_qualified_strict_types`, `no_superfluous_phpdoc_tags`, `ordered_imports`).
  - `git diff --check` — exit 0, no output.
- Protected perimeter: no Treasury movement port, fiscal file, frontend/interlock file, permission seeder, or unrelated module was edited.
- Deviations/concerns: none.

### Task B3 — Treasury alert notification and recipient resolver

- Status: complete, including reviewer fix `afed7090f`.
- Files: `TreasuryAlertNotification.php`, `TreasuryAlertRecipients.php`, `TreasuryAlertRecipientsTest.php`, and task/progress reports.
- RED/GREEN: missing-class RED; real PostgreSQL DB-per-tenant GREEN at `4 tests, 11 assertions, 0 skips`.
- Coverage: database-only stable type; `alert_type` forced into stored payload; team set to tenant and restored; per-tenant registrar flush; active-company membership deny direction; two-tenant cache isolation; inactive membership exclusion.
- Reviewer fix: a conflicting caller `alert_type` initially won; regression test made that RED, then `toDatabase()` was changed so the constructor's stable value overrides caller data. Real PostgreSQL suite, PHPStan 1G, and Pint passed.
- Deviations: none.

### Task B4 — Treasury alert command delivery

- Status: complete (`6df99958c`).
- Files: `ReconcileTreasuryCommand.php`, `InstrumentMaturityAlertsCommand.php`, their focused tests, and task/progress reports.
- RED/GREEN: missing drift/portfolio/maturity rows and notification-channel failure audit were RED; GREEN is `ReconcileTreasuryTest` 20 tests/88 assertions and `InstrumentMaturityAlertsTest` 6 tests/29 assertions.
- Coverage: notification delivery is a third independently caught channel after audit; failures never suppress freeze/audit; portfolio failure has repository-less logging; company-manager deny direction; one maturity row per recipient/company; zero-count maturity sends nothing.
- Verification: PHPStan 1G clean, Pint clean, diff check clean.
- Deviations: none.

### Task C1 — Cash-movements direction and per-currency totals

- Status: complete (`ccd89f970`).
- Files: request, reports controller, `CashMovementsReportService`, `CashMovementsReportTest`, and reports/progress.
- RED/GREEN: direction ignored, totals missing, and unfiltered pagination meta were RED; GREEN is `14 tests, 85 assertions`.
- Coverage: direction applies before count; one full-range SQL aggregate groups currency+direction; TND/EUR stay separate across payment and journal sources; totals remain full-range with `per_page=1`; exact `bcformatStrict`/`bcsub` formatting.
- Verification: PHPStan 1G clean, Pint clean, diff check clean.
- Deviations: none.

### Task C2 — Cash-position windowed flows

- Status: complete (`59245962b`).
- Files: `CashPositionController.php`, `CashPositionEndpointTest.php`, and reports/progress.
- RED/GREEN: requested flows missing and invalid window accepted were RED; GREEN is `6 tests, 37 assertions`.
- Coverage: absent without parameter; 1..90 validation; exact in/out sums by `occurred_at`; active cash types only; foreign-currency, inactive, virtual, and out-of-window movements excluded; position remains balance-derived.
- Verification: PHPStan 1G clean, Pint clean, diff check clean.
- Deviations: none. Reviewer recorded a non-blocking test-hardening opportunity because fixture rows are inserted directly and cross-company/cross-tenant exclusion is not independently pinned; production scoping is correct.

### Gate 2 adversarial review

- Review: `docs/handoff/gate-reviews-phase3/GATE-2-rc1.md`.
- Verdict: `APPROVE`; no gating findings and no Fable escalation.
- The sole LOW process finding (missing B3-C2 summaries in this binding progress file) was corrected before the final Gate 2 tag.

### Wave D interlock (2026-07-12)

- Ran `git fetch origin dev` at Wave D start.
- `origin/dev` contains the expected `feat/treasury-ui-gaps` changes to `apps/web/src/features/treasury/RepositoryDetailPage.tsx` and `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx`; Phase 3 will not edit either file.
- Diff/log checks from the sanctioned base `f1d6c1d30` show no changes to `apps/web/src/features/treasury/RepositoryListPage.tsx` or `apps/web/src/components/organisms/TopBar/TopBar.tsx` on `origin/dev`.
- Per the handoff's conditional interlock, no rebase is required before Wave D. No conflict exists to report.

### Task D1 — FE permission registration

- Status: complete.
- Files: `apps/web/src/hooks/usePermissions.ts`, existing `apps/web/src/hooks/__tests__/usePermissions.authPayload.test.tsx`, task report, and this progress ledger.
- RED: the token-bearing hook regression executed in Vitest, while strict typecheck failed with TS2345 because `treasury.transfer` was not yet a valid `Permission`.
- GREEN: `treasury.transfer` is registered with the backend-privileged fallback roles `admin`, `manager`, and `accountant`; no `SERVER_AUTHORITATIVE_PERMISSIONS` entry was added because that set has no treasury/adjust-class peer.
- Verification: focused Vitest 3/3, TypeScript typecheck, relevant ESLint, full web lint, and React Doctor diff scan all exited 0; React Doctor reported no changed-scope diagnostics or regression.
- Deviations/concerns: none.
