# Document-number company scope — adversarial gate r2 (fiscal/POS + tenancy)

Date: 2026-08-29
Lane: `fix/doc-number-company-scope`
Stated base: `ea4b6b10bdd321c2cdb7be97c251a79168c957e6` (`dev`)
Reviewed HEAD: `27386da255e7995e2e6c79a1c08d33baf124e421`
Prior gate: `docs/superpowers/reviews/2026-08-29-doc-number-company-scope-gate-r1-fiscal.md`

## Verdict

**APPROVED.** Fix round 1 closes r1 B1–B3. Expense and Income allocation now uses the company for both the max+1 scan and PostgreSQL transaction advisory lock, while preserving the existing legal-number bytes. The PostgreSQL migration proof now survives the deliberate `23505` and executes the rollback-refusal census. The manifest notes name both SQLite and PostgreSQL, and migration messages route through `MigrationOutput`.

The worktree was clean at review start. The five-commit tip was:

- `27386da25` — Expense/Income company allocation, PostgreSQL test isolation, dual-driver notes, `MigrationOutput`
- `0d7c4bf68` — merge of migration-output hardening
- `93fc6fc3a` — migration census output excluded from HTTP responses
- `5abecd6fa` — r1 gate review
- `e4a09281f` — numbering-lane census ledger row

## R1 blocker re-check

### B1 — Expense and Income company scope: closed

- Expense passes both `tenant_id` and `company_id` from `post()` and `reverse()` into `generateExpenseNumber()` (`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:410,939`). The allocator locks `expense_number:{companyId}` and scans `tenant_id + company_id + Expense` (`:1102-1118`).
- Income passes both identifiers from `post()` (`apps/api/app/Modules/Income/Application/Services/IncomeService.php:157`). Its allocator locks `income_number:{companyId}` and scans `tenant_id + company_id + Income` (`:212-228`).
- Both rewritten contracts state that lock and scan share company scope and that sibling companies do not interleave.
- The number-producing statements are byte-identical to `git show ea4b6b10b:`: Expense remains `sprintf('EXP-%s-%06d', $year, $nextNumber)` and Income remains `sprintf('INC-%s-%06d', $year, $nextNumber)`.
- `ExpensePostTest` proves company B receives `EXP-<year>-000001` and then `...-000002`; `IncomeNumberingCompanyScopeTest` proves the corresponding `INC-` sequence using real post endpoints. The SQLite suite passed these sequence tests. PostgreSQL separately executed both company-key advisory-lock pins.
- `rg documents_tenant_id_type_document_number_unique apps/api --type php` returns only the canonical value in `DocumentIndexNames.php`. The migration references it through `DocumentIndexNames::TENANT_TYPE_NUMBER_UNIQUE`; no allocator, test, or other PHP contract retains the stale literal.

### B2 — PostgreSQL migration test: closed

`DocumentNumberCompanyScopeMigrationTest` now wraps the intentional same-company duplicate insert in a nested `DB::transaction`. On PostgreSQL this creates the rollback boundary needed to recover from `23505`, leaving the outer `RefreshDatabase` transaction usable. The same method then calls `down()`, receives the expected one-group census refusal, and asserts that the company unique remains while the tenant unique is absent (`apps/api/tests/Feature/Migrations/DocumentNumberCompanyScopeMigrationTest.php:130-150`).

Fresh PostgreSQL execution on the mandated private database passed the complete migration file, including:

- collision census before the legacy unique is dropped;
- idempotent re-entry and exact lookup-index presence;
- legal sibling-company duplicate numbers;
- isolated same-company `23505`;
- executable `down()` refusal after that violation.

### B3 — manifest notes: closed

The Document and Migrations `raise_note` entries explicitly say the new tests run by path on **SQLite AND PostgreSQL 16** (`apps/api/tests/feature-lane-manifest.json:780,866`). The manifest checker exits 0 at 1,474 Feature classes in 74 groups and a parked/gated ceiling of 1,215.

## Migration output guard

The document-number migration imports `App\Shared\Database\MigrationOutput`; `emitInfo()` delegates to `MigrationOutput::info()` and `emitError()` delegates to `MigrationOutput::error()` (`apps/api/database/migrations/tenant/2026_08_30_100500_enforce_company_scoped_document_numbers.php:227-235`). It contains no bare `echo`. `NoBareEchoInMigrationsTest` passed in the required SQLite unit-migration run.

## R1 PASS regression re-check

- **Census then refuse:** `up()` calls `assertNoCrossCompanyNumberCollisions()` before dropping the tenant unique; `down()` calls it before dropping the company unique. The lifetime/soft-deleted collision test remains green.
- **Lookup index:** `documents_tenant_id_type_document_number_index` is still created over `(tenant_id,type,document_number)` and exact-name assertions pass on both drivers.
- **Real two-company path:** `DocumentNumberingCompanyScopeTest` still performs `POST /invoices -> /confirm -> /post` for each company and asserts both receive `INV-<year>-0001` with separate `last_number=1`. It passed on SQLite and PostgreSQL.
- **Deferred/fiscal invariant:** `DeferredDocumentNumberingTest` passed all SQLite-applicable cases, including rollback/no-burn paths and numbering before delivery-note fiscal hash sealing; its one concurrency case skipped only because it is PostgreSQL-specific.
- **No burned-number regression:** the r1-reviewed `DocumentStatusService`, `DocumentNumberingService`, and `StandaloneReceiptService` transaction paths are unchanged by fix round 1. The real invoice test and deferred-numbering suite remain green.

## Verification evidence

All PHP commands ran from `apps/api`. Every PostgreSQL command used only `DB_DATABASE=autoerp_test_j2 DB_CENTRAL_DATABASE=autoerp_test_j2` with `phpunit-pgsql.xml`.

| Check | Fresh outcome |
|---|---|
| SQLite: `DocumentNumberingCompanyScopeTest`, `DocumentNumberCompanyScopeMigrationTest`, all `tests/Feature/Expense`, all `tests/Feature/Income`, `DeferredDocumentNumberingTest`, all `tests/Unit/Migrations` | PASS — 172 passed, 3 expected PostgreSQL-only skips, 1,629 assertions |
| PostgreSQL j2: migration + Document company-scope + Income company-scope files | PASS — 6 passed, 43 assertions |
| PostgreSQL j2: Expense company advisory-lock method | PASS — 1 passed, 3 assertions |
| Feature-lane manifest checker | PASS — 1,474 Feature classes / 74 groups; gated ceiling 1,215 |
| Old tenant-unique literal census | PASS — canonical constant only; migration consumes the constant |

GATEVERDICT: APPROVED
