# Document-number company scope — adversarial gate r1 (fiscal/POS + tenancy)

Date: 2026-08-29  
Lane: `fix/doc-number-company-scope`  
Stated base: `ea4b6b10bdd321c2cdb7be97c251a79168c957e6` (`dev`)  
Reviewed HEAD: `e4a09281ffb50a30d64e009bfd461f1c1169014b`

## Verdict

**CHANGES.** The migration itself has the right collision-first ordering and the standard invoice path preserves sequence atomicity, but the lane does not yet implement the owner's per-company ruling across all `documents` types. The mandatory PostgreSQL migration test file is red, and the manifest notes do not record the dual-driver G-3a precedent.

## Review provenance

The prompt described uncommitted changes, but `git status --porcelain=v2 --untracked-files=all`, `git diff`, and `git diff --cached` were all empty. There were no untracked files. The worktree was clean on two commits after the stated base:

- `71e06d5d7 fix(documents): scope document_number uniqueness per company ...`
- `e4a09281f ledger(session-J): D-J0-2 opening-balance entry numbers collide across companies ...`

I therefore reviewed the complete `ea4b6b10b..e4a09281f` delta: six files, 646 insertions and 3 deletions. No application change outside the new index-name class and migration is present; the second commit only adds the `D-J0-2` ledger row.

## Blocking findings

### B1 — Owner ruling is not implemented for Expense and Income numbering

The schema becomes company-scoped, but two live `documents` allocators remain deliberately tenant-scoped:

- Expense posting passes only `tenant_id` into `generateExpenseNumber()` (`app/Modules/Expense/Application/Services/ExpenseService.php:404-410`). The allocator locks `expense_number:{tenantId}` and scans only `tenant_id` (`:1117-1136`). Its contract explicitly says companies interleave numbers (`:1098-1108`).
- Income posting has the same shape (`app/Modules/Income/Application/Services/IncomeService.php:153-157`), with a tenant advisory key and tenant-only scan (`:226-245`). Its contract explicitly says the scope is tenant-wide and companies interleave (`:210-219`).

The existing behavior pins confirm this is live, not stale prose: the targeted SQLite run passed 2 tests / 10 assertions while requiring company B to receive `EXP-<year>-000002` and `INC-<year>-000002`. See `tests/Feature/Expense/ExpensePostTest.php:188-213` and `tests/Feature/Income/IncomeNumberingTenantScopeTest.php:46-60`.

This also fails the requested old-name census. `rg documents_tenant_id_type_document_number_unique apps/api` finds PHP references outside `DocumentIndexNames` in:

- `app/Modules/Expense/Application/Services/ExpenseService.php:1100`
- `app/Modules/Income/Application/Services/IncomeService.php:212`
- `tests/Feature/Expense/ExpensePostTest.php:182,204`
- `tests/Feature/Income/IncomeNumberingTenantScopeTest.php:33`

The SQL backup also contains the historical name at `apps/api/backup_before_phase0.sql:2177,2181`; that archival occurrence is not live code, but the PHP occurrences are live contracts and assertions. Re-scope the Expense/Income allocator key, scan, call signature, and two-company tests to company scope, then repeat the grep.

### B2 — Required PostgreSQL migration test leg is red

SQLite passes the entire migration file: **3 passed / 17 assertions**. PostgreSQL on the mandated private database returns **2 passed, 1 failed / 15 assertions**.

Root cause is deterministic test-transaction poisoning, not the migration census. `test_company_scoped_numbers_allow_sibling_companies_and_reject_same_company_duplicates()` deliberately triggers and catches a same-company `23505` at `tests/Feature/Migrations/DocumentNumberCompanyScopeMigrationTest.php:129-134`, then calls `down()` at `:136-141`. Under PostgreSQL, the caught statement leaves `RefreshDatabase`'s enclosing transaction aborted; `down()`'s first schema query receives `25P02`, so the expected `1 collision group(s)` assertion fails. Running that method alone reproduces the same 1-test failure.

Consequences:

- The PostgreSQL file required by this gate does not pass.
- The PostgreSQL `down()` refusal is not executable proof; SQLite does prove it, and the implementation is ordered correctly, but the PG test never reaches its census.
- The fix must isolate the deliberate unique violation in a rollback boundary or split it from the rollback-refusal case so subsequent PG queries run in a healthy transaction.

### B3 — Manifest raise notes do not follow the G-3a dual-driver precedent

The arithmetic is correct: `gated_ceiling` 1212→1214 (`tests/feature-lane-manifest.json:9`), Document 91→92 (`:777-780`), and Migrations 10→11 (`:863-866`). The checker passes.

However, both new `raise_note` values say only `Run BY PATH on SQLite` (`:780` and `:866`). The referenced G-3a precedent records SQLite **and PostgreSQL**, which matters here because the migration contains separate `array_agg`/DDL logic and the requested PG leg is currently red. After fixing B2, update the notes to record both verified drivers and the private PG lane convention without naming a disposable database as permanent CI configuration.

## Requested gate checks

### 1. Census-then-refuse and healthy no-op paths

**Implementation passes static review.**

- `up()` detects index state first and treats an already-company-scoped tenant as a healthy re-entry (`database/migrations/tenant/2026_08_30_100500_enforce_company_scoped_document_numbers.php:37-47`).
- On the legacy path, the census is called at `:50`; the tenant unique is dropped only afterward at `:51`. A collision is logged and throws at `:146-155`, so control cannot reach the drop.
- The raw census reads `documents` directly with no `deleted_at` predicate (`:120-130`). `documents` is soft-deletable (`database/migrations/tenant/2025_11_30_080000_create_documents_table.php:36`), so lifetime rows are included.
- PostgreSQL uses `array_agg(DISTINCT company_id)` and SQLite uses `group_concat(DISTINCT company_id)` (`2026_08_30_100500...php:116-118`). Executed output showed PG `{uuid,uuid}` and SQLite `uuid,uuid`, with the correct one-group count and both company IDs.
- Missing table and each required missing column return cleanly (`:89-109`). A fresh healthy schema takes the zero-collision path. Already-migrated re-entry is explicitly non-censusing, preventing legal cross-company duplicates from being reclassified as damage (`:40-47`).

The collision test uses a soft-deleted holder and asserts the legacy unique is still present after refusal (`tests/Feature/Migrations/DocumentNumberCompanyScopeMigrationTest.php:71-105`). It passed on both drivers.

### 2. Idempotency and rollback refusal

- Up/re-entry is exercised twice at `tests/Feature/Migrations/DocumentNumberCompanyScopeMigrationTest.php:63-68` and passed on both SQLite and PG.
- `down()` runs the collision census at `2026_08_30_100500...php:77` before dropping the company unique at `:78`; it recreates the tenant unique only at `:80-83`.
- SQLite proves rollback refusal and preservation of the company unique (`DocumentNumberCompanyScopeMigrationTest.php:136-144`).
- PostgreSQL does not currently prove this because of B2.

### 3. Index names and old constraint references

The lookup index constant is `documents_tenant_id_type_document_number_index` (`2026_08_30_100500...php:22`) and creation uses that exact name over `(tenant_id,type,document_number)` (`:160-170`). The exact-name assertion at `DocumentNumberCompanyScopeMigrationTest.php:68,208-216` passed in the first test on both drivers. The company unique is named `documents_company_id_type_document_number_unique` by `DocumentIndexNames.php:11` and is asserted on both drivers.

The "no other code references the old name" condition fails as documented in B1.

### 4. Real create → confirm → post path

The new feature test is genuinely end-to-end for ordinary invoices: it calls `POST /api/v1/invoices`, then `/confirm`, then `/post` (`tests/Feature/Document/DocumentNumberingCompanyScopeTest.php:139-168`). It asserts both companies receive `INV-<year>-0001` and each company sequence remains at 1 (`:90-110`). Results:

- SQLite: **1 passed / 14 assertions**
- PostgreSQL private DB: **1 passed / 14 assertions**

Same-company duplicate refusal is covered separately by a direct factory insert in the migration test (`DocumentNumberCompanyScopeMigrationTest.php:124-134,147-159`), not by the end-to-end test. That is a reasonable database-constraint probe because the real allocator should generate `0002`, but the test/report should state this split accurately rather than imply the refusal itself uses the API path.

### 5. Unique retry callers and burned-number risk

The key scratchpad claim is materially correct for the two requested spot checks.

- `StandaloneReceiptService` retries broad unique failures at `app/Modules/Procurement/Application/StandaloneReceiptService.php:65-101`. The purchase-order number allocation and `documents` insert occur inside the enclosing `DB::transaction` (`:67-94`, with allocation/insert at `:205-229`). A `documents` unique failure exits that transaction before the catch, so `document_sequences.last_number` rolls back. The second attempt cannot commit a burned number.
- `DocumentStatusService` obtains the company-scoped number at `app/Modules/Document/Domain/Services/DocumentStatusService.php:358-368`. The allocation and conditional numbered status update are inside one transaction at `:248-281`; any non-lost-claim exception is rethrown after rolling back a staged savepoint at `:283-290`. Staged fiscal/confirm paths are required to start inside a caller transaction and retain the allocator savepoint until transition (`:385-419`). A failed `documents` update therefore cannot commit the bump.
- `DocumentNumberingService` itself retries only the `document_sequences` row-creation race (`app/Modules/Document/Domain/Services/DocumentNumberingService.php:29-40`); each attempt is its own transaction and increments at `:42-67`. It is not a catch/retry of a `documents` unique violation.

No burned legal number was found in these paths. B1 is a separate scope defect: Expense and Income use max+1 rather than `document_sequences`.

### 6. Fiscal invariant and deferred numbering

The lane diff touches no sealed hash, chain, fiscal payload, `DocumentStatusService`, or `DocumentNumberingService` implementation. The unique-key migration does not rewrite document numbers or sealed rows.

Fresh by-path verification:

- SQLite `DeferredDocumentNumberingTest`: **17 passed, 1 skipped / 65 assertions** (the PG concurrency case is skipped by design).
- PostgreSQL private DB: **18 passed / 80 assertions**.

This includes number rollback, staged allocation, concurrent stale-confirm behavior on PG, and “delivery note is numbered before its fiscal hash is sealed.” The deferred-numbering/fiscal-chain contract remains green.

### 7. Manifest

`php tools/feature-lane-manifest-check.php` exits 0: **1473 Feature classes in 74 groups**, all dispositions present, with the parked total at **1214**. The numeric raises are correct. Semantic raise-note compliance is blocked by B3.

### 8. Nine-tenant staging promotion risk

Promotion is a fleet `tenants:migrate` across nine staging tenant databases. On each tenant's first application, the operator must find:

`documents.number_scope_census collisions=0`

followed by:

`documents.number_scope unique=(company_id,type,document_number)`

An already-applied tenant may instead emit `documents.number_scope_census state=already_company_scoped`; that is the healthy re-entry signal. Account for all 9 tenants individually—do not accept a fleet command's aggregate exit status without the per-tenant lines.

If any tenant emits `documents.number_scope_census collisions=N` with `N >= 1` and refuses:

1. Stop the promotion and identify the tenant plus every logged `type`, `document_number`, and `company_ids` group.
2. Verify the old tenant unique remains present and the company unique was not installed; the migration is designed to refuse before DDL.
3. Do not delete, renumber, or unseal fiscal documents ad hoc. Route the collision set through the fiscal/data-remediation owner, establish whether the rows are legitimate company records or damaged provenance, and preserve the audit trail.
4. Apply an approved tenant-specific remediation, rerun the census, and resume only when that tenant logs `collisions=0`. Re-check the final index names on all 9 tenants after the fleet run.

## Verification commands and outcomes

All PHP commands ran from `apps/api`; every PG command used only `DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j` with `phpunit-pgsql.xml`.

| Check | Outcome |
|---|---|
| SQLite migration file | PASS — 3 tests, 17 assertions |
| PG migration file | **FAIL — 2 passed, 1 failed, 15 assertions** (`25P02` after caught `23505`) |
| PG failing method rerun alone | **FAIL — 1 test, 3 assertions**, same root cause |
| SQLite company-scope E2E | PASS — 1 test, 14 assertions |
| PG company-scope E2E | PASS — 1 test, 14 assertions |
| SQLite deferred numbering | PASS/WARN — 17 passed, 1 expected PG-only skip, 65 assertions |
| PG deferred numbering | PASS — 18 tests, 80 assertions |
| Manifest checker | PASS — 1473 classes / 74 groups; gated ceiling 1214 |
| Existing Expense + Income two-company scope pins | PASS — 2 tests, 10 assertions, proving current tenant-wide behavior |

GATEVERDICT: CHANGES
BLOCKER-1: Re-scope Expense and Income numbering, locks, tests, and stale old-constraint references from tenant to company scope.
BLOCKER-2: Make `DocumentNumberCompanyScopeMigrationTest.php` pass on PostgreSQL and preserve executable PG proof of `down()` refusal after the same-company duplicate assertion.
BLOCKER-3: Update the Document/Migrations manifest raise notes to record the G-3a SQLite+PostgreSQL by-path precedent after the PG leg is green.
