# Task 02 Codex Self-Adversarial Review — POS Customer Mirror Repository

## Scope Reviewed

- Implementation commit: `c08152fd1 Phase 2.2.1: Add POS customer mirror repository`
- Files reviewed:
  - `apps/pos/src/lib/db/migrations.ts`
  - `apps/pos/src/lib/customer/customerTypes.ts`
  - `apps/pos/src/lib/db/repositories/customerRepository.ts`
  - `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts`

## Verdict

APPROVE.

Task 2 is a scoped POS-only foundation slice: local SQLite customer mirror schema, type contract, repository upsert/search/read helpers, balance-staleness helper, and real-SQLite tests. I found no blocker or request-changes issue.

## Attack Vectors Checked

### Cross-Tenant / Cross-Company FK Safety

PASS. The table primary key is `(tenant_id, company_id, id)`, and every repository read/search predicate includes both `tenant_id` and `company_id`. `upsertCustomer()` checks for same-tenant customer-id company drift before writing and throws `CustomerCompanyDriftError`; the test proves same customer id is allowed across different tenants but rejected across companies inside one tenant.

Residual note: this is a local mirror table with no local foreign-key references yet. Future Task 4/5 customer attach and ACCOUNT_PAYMENT authoring must call `getCustomerById(db, tenantId, companyId, id)`, not query `customers` directly.

### Fail-Loud vs Silent Downgrade

PASS. Missing `tenant_id`, `company_id`, or `id` throws before SQL execution. Same-tenant company drift throws a typed error and prevents local cache corruption. Invalid search `limit` and invalid staleness threshold throw instead of silently coercing.

### Dead-Path Rebuild

PASS for this task’s scope. Task 2 intentionally creates the repository before sync ingestion and UX attach flows. The new path has a live caller in the real-SQLite repository tests, and later plan tasks wire it into customer sync/search. No production call site was claimed in this commit.

### D16 Bounded-Modules Guard

PASS. POS TypeScript only. No Treasury, Accounting, B2B, Laravel container, `app()`, `App::make`, or `resolve()` references. Grep guard on the Task 2 files returned no matches.

### Test Matrix Completeness

PASS for Task 2. The test covers:

- Insert/update by `(tenant_id, company_id, id)`.
- Same id across tenant allowed.
- Same id company drift inside tenant rejected.
- Search by name, phone, tax number.
- Inactive/wrong-company/wrong-tenant rows excluded.
- Required scope/id validation.
- Balance-staleness true/false boundary.

### Contract Drift

PASS. The migration columns match the locked plan’s Task 2 table shape: identity scope, contact fields, category, receivable/credit balances, balance timestamp, active flag, sync version, update timestamp, sync timestamp, and three scoped search indexes. Type names intentionally mirror DB column names to avoid translation drift at the repository boundary.

### Skip-Citation / Per-Method Skips

PASS. No skips added.

## Verification Evidence

- Red test observed first: `pnpm test -- customerRepository.test.ts` failed because `../customerRepository` did not exist.
- Focused POS test: `pnpm test -- customerRepository.test.ts` — 5 passed.
- POS lint: `pnpm lint` — passed with 42 existing warnings outside Task 2.
- Full POS test: `pnpm test` — 154 files, 1397 tests passed.
- POS typecheck: `pnpm typecheck` — passed.
- Backend Fiscal/POS suite:
  - First run without `APP_KEY` failed in POS signing-key tests with `MissingAppKeyException`.
  - Rerun with explicit test `APP_KEY` passed: 1087 tests, 3625 assertions, 16 deprecations, 107 skipped, 2 incomplete.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` — passed.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` — passed.
- `git diff --check` — passed.

PHPStan and Pint were not applicable: Task 2 touched no PHP files.
