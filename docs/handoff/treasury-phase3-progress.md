# Treasury Phase 3 — Cash Visibility Progress

- Started: 2026-07-12 (Africa/Tunis)
- Branch: `feat/treasury-phase3-cash-visibility`
- Base: `f1d6c1d30` (`origin/dev` at worktree creation)
- Binding handoff: `docs/handoff/CODEX-treasury-phase3-cash-visibility-2026-07-12.md`

## Tasks

Progress, files touched, test evidence, deviations, and gate verdicts are appended here after each task.

## Deviations

- **2026-07-12 — Task D2 cache-filter prefixes:** Rev2 specified `tenantScopedKey(...)` for six transfer-success invalidations and raw prefixes only for repository movements. The pre-existing TanStack audit from `d0620c90d` rejects every scoped cache filter because `tenantScopedKey` appends tenant/company as suffixes while TanStack filter matching is positional-prefix based. D2 therefore uses raw leading literal prefixes for all eight invalidations, as required by current CI. This reaches the tenant-suffixed queries (including filtered movement variants) and changes no money-path behavior.

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

## Gate 3 — rc1 verification (2026-07-12)

- `cd apps/web && pnpm typecheck && pnpm lint`: exit 0. ESLint reported 0 errors and 6422 existing warnings; TanStack audit 0 violations; design audit 753 acknowledged / 0 new / 0 stale; custom rule tests passed.
- `cd apps/web && pnpm vitest run src/features/treasury src/features/notifications src/features/finance src/hooks/usePermissions.test.ts`: exit 0 — `64 test files, 393 tests passed`. Existing suite-wide React `act(...)` and localstorage-file warnings remain non-failing.
- Gate-specific notification pins are green inside that run: bell badge hidden at count 0 and legacy PHP-FQCN/data.message fallback rendering.
- `node tools/audit-design-system.mjs`: exit 0 — 0 new, 0 stale.
- `node tools/audit-tanstack-keys.mjs`: exit 0 — 0 violations.
- React Doctor changed-scope scan against `phase3-gate-2`: exit 0, `baseline.newCount=0`, `diagnostics=[]`, complete scan of 29 Wave-D files. Displayed score 93 reflects five baseline host-file findings; Wave D introduced zero diagnostics.
- D2 deviation: six cache invalidations use raw leading prefixes rather than plan-pasted `tenantScopedKey` filters because the pre-existing enforced TanStack audit rejects scoped cache filters. Prefix semantics remain correct for tenant-suffixed keys; deviation is non-money-path.
- D4 deviation: the report uses the current typed `DataTable` plus external `OffsetPagination` rather than the plan's hand-written `<table>`, because the enforced design audit rejects raw tables and the current DataTable supports the needed presentational/external-pagination contract. Behavior and all seven columns remain pinned.

### Task D1 — FE permission registration

- Status: complete.
- Files: `apps/web/src/hooks/usePermissions.ts`, existing `apps/web/src/hooks/__tests__/usePermissions.authPayload.test.tsx`, task report, and this progress ledger.
- RED: the token-bearing hook regression executed in Vitest, while strict typecheck failed with TS2345 because `treasury.transfer` was not yet a valid `Permission`; the hardened fixture uses `roles: []`, so runtime success proves token permission handling rather than role fallback.
- GREEN: `treasury.transfer` is registered with the backend-privileged fallback roles `admin`, `manager`, and `accountant`; no `SERVER_AUTHORITATIVE_PERMISSIONS` entry was added because that set has no treasury/adjust-class peer.
- Verification: focused Vitest 3/3, TypeScript typecheck, relevant ESLint, and full web lint exited 0. Fresh `npx react-doctor@latest --verbose --scope changed --base 9218efcea` exited 0 with 100/100 and `No issues found!`; the earlier deprecated `--diff` invocation is not relied upon as React Doctor evidence.
- Deviations/concerns: none.

### Task D2 — Inter-repository transfer modal

- Status: complete.
- Files: transfer mutation hook and test; transfer modal and test; `RepositoryListPage` and its test; repository payload formatter and feature assertion; frontend repository currency type; en/fr/ar treasury translations; task/progress reports.
- RED/GREEN: the hook first failed on its missing module, the backend currency assertion first received `null`, the modal first failed on its missing component, and the page permission test first failed because the transfer action was absent. GREEN coverage is 1 hook test, 3 modal tests, 6 page tests, and the complete 14-test/39-assertion backend repository suite.
- Contract: canonical string money through shared `MoneyInput`; active non-virtual repositories; destination excludes the source and different currencies; one `crypto.randomUUID()` per modal open and stable retries; canonical error extraction; success toast/callback/close; independent `treasury.transfer` and `repositories.manage` action gates; API/FE currency contract; all eight affected cache families invalidated by leading prefixes.
- Precision: no numeric money parsing was introduced; the touched list's existing `parseFloat` totals/sign checks were migrated to `big.js`.
- Verification: frontend treasury 33 files/234 tests; typecheck; full web lint; TanStack audit 0; design audit 0 new; backend 14 tests/39 assertions; Pint pass; React Doctor changed-scope against base `5a587790725a2b8a23d72ffb4a70d1e21d41aa09` 100/100 with no issues.
- Deviation: the all-raw cache-filter amendment is recorded in the dated Deviations entry above.
- Reviewer follow-up: a non-identity i18n regression test first failed on raw zero-amount and same-repository keys; the modal now translates only its known Zod validation keys (unknown/undefined messages remain untouched), and `repositoryLabel` is module-scoped. Follow-up gates: modal 4/4, D2 focused 11/11, typecheck/lint/audits clean, React Doctor 100/100.

### Task D3 — Notification center frontend

- Status: complete.
- Base: `0aad1edfdd8d0821356dbf7a769cf0fb8e50cf49` (recorded before D3 changes and used explicitly for React Doctor changed-scope analysis).
- Files: notification API wrappers/hooks and tests; `NotificationBell`/`NotificationPanel` and tests; TopBar organism wiring/test; en/fr/ar `notifications` namespace and `src/lib/i18n.ts` registration; task report and this progress ledger.
- RED:
  - API/hooks focused run failed in two suites because `notificationsApi.ts` and `useNotifications.ts` did not exist.
  - Component/i18n/TopBar focused run failed because Bell/Panel did not exist, all three notification bundles were undefined, and TopBar still rendered the static fake-dot button.
- GREEN: focused `pnpm exec vitest run src/features/notifications src/components/organisms/TopBar/TopBar.test.tsx` — 6 files, 20 tests passed. Coverage pins raw `api.get` list metadata vs `apiGet` count, user+tenant gating, tenant-scoped user keys, 60-second polling, panel-open list fetch, raw user-prefix mutation invalidation, zero/nonzero badge behavior, newest-first/read-state rendering, unread mark-read then deep-link, read-row behavior, mark-all, empty state, legacy PHP-FQCN `data.message` fallback, namespace registration, and static-dot replacement.
- Contract: inbox remains universally available to authenticated tenant users; list fetches only while the panel is open; latest 15 are sorted newest-first; unread treatment uses design tokens and semantic `data-read-state`; unknown notification types render the generic title plus raw type and best-effort message; all controls use the shared Button atom and logical RTL positioning. The non-modal dropdown uses a native open `<dialog>` for browser accessibility semantics.
- Verification: TypeScript typecheck passed; full web lint exited 0 with 0 errors (repository warning baseline only); TanStack key audit 0; design-system audit 0 new; `git diff --check` passed. The authoritative repository-root React Doctor v0.7.6 changed-scope run pinned with `--base 0aad1edf...` reported `No issues found!` and 100/100.
- React Doctor follow-up TDD: the first repository-root scan exposed `role="dialog"` on a generic `<section>` (98/100), which the earlier app-directory invocation had missed. A native-element assertion failed RED (`SECTION` vs `DIALOG`); the panel then moved to non-modal `<dialog open>` and returned the focused suite and authoritative root scan to green/100.
- Deviations/concerns: none. The current React Doctor CLI expresses the skill's former `--diff` behavior as `--scope changed --base <sha>`; the explicit recorded SHA prevents moving-branch contamination.

### Task D4 — Cash movements report frontend

- Status: complete.
- Base: 9ccbb45c7b252409335f6f852673e1dcfa74d1a8.
- Files: typed useCashMovementsReport hook/test; report page/test; lazy route/test; Accounting/reports Sidebar entry/gate test; en/fr/ar finance keys; task/progress reports.
- RED: hook/page modules were unresolved, the Sidebar lacked the entry, and the unmatched report URL fell through to Dashboard. Existing Sidebar coverage remained green.
- GREEN: the finance + D4 route/sidebar focused run passed 27 files and 172 tests. Coverage pins raw {data,meta} preservation through api.get, tenant-scoped key/gating, current-month defaults, repository/direction filters, one formatted totals row per currency, typed DataTable columns, safe payment link/copy fallback, filter-preserving pagination, and both route/nav permission gates.
- Contract: route uses reports.view; navigation uses the reports mapping under Accounting beside Treasury overview. This differs intentionally from the later Treasury-axis widget per L2-6.
- Verification: typecheck, full web lint, explicit TanStack/design audits, JSON parsing, and diff check exited 0. Pinned React Doctor changed-scope against the D4 base has baseline.newCount 0 and empty diagnostics; line-scope reports no issues. The displayed 93 score consists only of four pre-existing whole-file warnings in required host files outside D4 changed lines, so no unrelated host refactor/suppression was made.
- Deviations: no functional deviation; React Doctor score display behavior is documented in .superpowers/sdd/task-D4-report.md.
- Spec cross-link follow-up: TreasuryOverviewPage now exposes the required PageHeader action to /finance/cash-movements using the existing translated cash-movements label and shared Link/Button convention. The accessible link test was RED then GREEN; final D4 focused coverage is 5 files/49 tests. Typecheck, lint, audits, and diff check passed. React Doctor pinned to task base 632b4eacf reported no issues, zero new diagnostics, and 100/100.
- Dated D4 deviation (2026-07-12): the plan's hand-rolled-table rationale is superseded because the current canonical DataTable supports typed presentational rows with externally owned OffsetPagination. The raw table and copy button triggered enforced C5/C3 audit blockers, so D4 now uses DataTableColumn<CashMovementRow>, DataTable data/keyExtractor, and the canonical Button atom while preserving all columns, cells, links, copying, empty/loading behavior, and server pagination. Page regression remained 4/4; final D4 focused coverage passed 49/49; typecheck/full lint/TanStack/diff check passed; design audit is 753 acknowledged with 0 new and 0 stale. Doctor against the original D4 base has 0 new diagnostics, and the isolated current-task-base scan is 100/100 with no issues.

### Task D5 — Cash-position widget on owner and generic dashboards

- Status: complete.
- Base: `74187dd36` (`fix(finance-web): link treasury overview to cash movements`), recorded before D5 changes and used explicitly for React Doctor changed-scope analysis.
- Files: backward-compatible `useCashPosition` options/flow response typing and tenant-key test; self-gating `CashPositionWidget` and behavior tests; owner/generic dashboard mount pins; en/fr/ar treasury `cashWidget` translations; task report and this progress ledger.
- RED: the four-file focused run failed on all missing D5 surfaces: the seven-day cache entry/request parameter was absent, the widget module was unresolved, and neither dashboard rendered the mount-pin test id. The legacy argument-less hook key test remained green.
- GREEN: focused D5 run passed 4 files/20 tests. The broader treasury/dashboard/owner slice passed 46 files/276 tests after the pre-existing provider-less Dashboard tenant-scope harness isolated the new child with a test mock.
- Contract: `useCashPosition()` retains the exact legacy key and one-argument API call; `{ flowsWindow: 7 }` adds `7` before tenant/company in the query key and sends `{ flows_window: 7 }`. The widget returns `null` without either `canAccessModule('treasury')` or `hasModule('Treasury')`, does not mount its data hook in either deny path, and renders server-provided grand total, per-type totals/counts, seven-day in/out, and both finance links when allowed. Both hosts only mount the self-gating component.
- Precision/i18n/design: every monetary decimal string flows directly to `formatCurrency`; no numeric money parsing was introduced. All visible copy uses `treasury:cashWidget.*` keys in en/fr/ar. The new component uses sanctioned design tokens and logical/neutral layout utilities.
- Verification: TypeScript typecheck passed; focused and broad Vitest runs passed; changed D5 production/widget ESLint passed; TanStack audit reported 0 new; `git diff --check` passed. React Doctor v0.7.6, pinned with `--scope changed --base 74187dd36`, reported `No issues found!`.
- Standing-gate boundary: the full web lint command completed ESLint with 0 errors and the TanStack audit with 0 new, then stopped at exactly two design-audit findings in the pre-existing D4 `CashMovementsReportPage.tsx` (raw button/table). Neither finding is in a D5 file, and the explicit D5-base React Doctor scan has zero diagnostics, so no prior-task host/report refactor was made. An extra global Arabic coverage run also exposed unrelated pre-existing gaps in `common`, `workshop-technicians`, and `vehicles`; the D5 widget suite passed in the same run.
- Deviations/concerns: no implementation deviation. Existing full-repository ratchet/locale failures are recorded above and left untouched per the no-scope-creep constraint.
- Reviewer test-hardening follow-up: the widget now pins the formatted monetary subtotal for registers (`250.000 TND`), bank accounts (`900.000 TND`), and safes (`100.000 TND`) independently of their counts. Both cash-position hook instances now use the async render/wait-for/unmount lifecycle, eliminating the newly introduced `act(...)` warning without changing production. Narrow coverage passed 2 files/7 tests; remaining `act(...)` warnings came only from the pre-existing repository-movements tests sharing the hook test file.
