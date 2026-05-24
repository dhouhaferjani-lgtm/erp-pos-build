# Task 02 Opus-Equivalent Adversarial Review — POS Customer Mirror Repository

## Verdict

APPROVE.

I found no blocker, request-changes, or minor-edit issue in `c08152fd1 Phase 2.2.1: Add POS customer mirror repository`. The commit stays inside the locked Task 02 scope: POS SQLite customer mirror migration, TS types, repository helpers, and real-SQLite repository tests.

## Findings

No issues found.

The implementation matches the locked contract:

- `apps/pos/src/lib/db/migrations.ts:1125` adds the next migration after v38 as v39.
- `apps/pos/src/lib/db/migrations.ts:1128` creates `customers` with `PRIMARY KEY (tenant_id, company_id, id)`.
- `apps/pos/src/lib/db/migrations.ts:1132` through `apps/pos/src/lib/db/migrations.ts:1143` include the required mirror columns.
- `apps/pos/src/lib/db/migrations.ts:1146` through `apps/pos/src/lib/db/migrations.ts:1148` add scoped name, phone, and tax-number indexes.
- `apps/pos/src/lib/customer/customerTypes.ts:1` defines the customer mirror row contract.
- `apps/pos/src/lib/db/repositories/customerRepository.ts:42`, `apps/pos/src/lib/db/repositories/customerRepository.ts:107`, `apps/pos/src/lib/db/repositories/customerRepository.ts:128`, and `apps/pos/src/lib/db/repositories/customerRepository.ts:157` export the required repository API.
- `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:58`, `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:73`, `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:88`, `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:109`, and `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:119` cover the expected behavior with a real SQLite adapter.

## Standing-Pattern Checks

- Cross-tenant and cross-company safety: PASS. `getCustomerById()` scopes by `tenant_id`, `company_id`, and `id` at `apps/pos/src/lib/db/repositories/customerRepository.ts:121` through `apps/pos/src/lib/db/repositories/customerRepository.ts:123`. `searchCustomers()` scopes by tenant/company and `is_active = 1` at `apps/pos/src/lib/db/repositories/customerRepository.ts:143` through `apps/pos/src/lib/db/repositories/customerRepository.ts:145`. Same customer id in another tenant is covered as allowed at `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:73`; same tenant plus different company is covered as fail-loud at `apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts:77`.
- Fail-loud posture: PASS. Required scope/id fields are validated before SQL at `apps/pos/src/lib/db/repositories/customerRepository.ts:37` through `apps/pos/src/lib/db/repositories/customerRepository.ts:44`. Company drift raises `CustomerCompanyDriftError` at `apps/pos/src/lib/db/repositories/customerRepository.ts:57` through `apps/pos/src/lib/db/repositories/customerRepository.ts:64`.
- Dead-path rebuild: PASS for this foundation task. I found no production caller added or claimed by this commit. The repository is exercised only by the new focused SQLite test and is ready for later sync/attach tasks.
- D16 bounded-modules guard: PASS. The changed POS TS files do not introduce Treasury, Accounting, B2B, Laravel container, `app()`, `App::make`, or `resolve()` dependencies.
- Contract drift: PASS. The migration, TS row shape, repository SQL, and tests use the same column names and scope order.
- Test completeness: PASS for Task 02. Coverage includes insert/update, same-id cross-tenant allowance, same-id same-tenant cross-company rejection, active-only search, wrong tenant/company search exclusion, name/phone/tax search, required-field validation, and balance staleness.
- R2-risk pattern: No requested changes, so no fresh implementation/re-review loop is triggered by this review.

## Verification Notes

- Read Codex self-review: `docs/superpowers/reviews/2026-05-21-task-02-codex-review.md`.
- Inspected commit diff: `git show --no-ext-diff --unified=80 c08152fd1 -- apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/customer/customerTypes.ts apps/pos/src/lib/db/repositories/customerRepository.ts apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts`.
- Whitespace check passed: `git diff --check c08152fd1^ c08152fd1 -- apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/customer/customerTypes.ts apps/pos/src/lib/db/repositories/customerRepository.ts apps/pos/src/lib/db/repositories/__tests__/customerRepository.test.ts`.
- Focused POS test passed from `apps/pos`: `pnpm test -- customerRepository.test.ts` — 1 file, 5 tests passed.
- D16 grep guard returned no matches for forbidden module/container patterns in the touched POS files.
