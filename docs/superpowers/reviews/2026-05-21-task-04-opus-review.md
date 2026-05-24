# Task 04 Opus Second-Pass Adversarial Review — POS Customer Pull Sync And Cursor

## Scope Reviewed

- Implementation commit: `f9023476c Phase 2.4.1: Sync POS customer mirror`
- Codex self-review: `docs/superpowers/reviews/2026-05-21-task-04-codex-review.md`
- Plan anchor: `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md` Task 4
- Touched files:
  - `apps/pos/src/lib/customer/customerSyncService.ts`
  - `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts`

## Verdict

REQUEST-CHANGES.

The Task 4 service gets the local basics right: it reads `customers.updated_since`, sends `updated_since` only when present, uses the server `synced_at` cursor, validates tenant/company scope for all returned rows before any write, calls `upsertCustomer()`, and does not advance the cursor on scope drift or malformed top-level payloads.

However, the Task 3 endpoint contract is capped at 100 rows and the Task 4 client unconditionally stores the top-level server cursor after one request. That creates a silent data-loss path for tenants with more than one page of customer updates.

## Findings

### P1 — Cursor advances after a capped page, permanently skipping remaining customers

References:

- `apps/pos/src/lib/customer/customerSyncService.ts:70-87`
- `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:38-58`
- `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md:397-402`

`pullCustomers()` makes exactly one `/pos/customers/sync` request, upserts that response, then stores `response.synced_at` as `customers.updated_since`.

The server endpoint orders by `updated_at`, applies `limit($limit)`, and defaults/maxes the limit to 100, but the response exposes only `customers` and `synced_at`. There is no `has_more`, `next_cursor`, last-row cursor, total count, or page token. If 150 customers need initial sync, the client receives the first 100, stores the server's current `synced_at`, and the next call asks for `updated_at > synced_at`; the remaining 50 older rows are skipped indefinitely.

This is not just a throughput limitation. It violates the cursor contract and can leave the local customer mirror incomplete while reporting a successful sync.

Required fix:

- Make the Task 3/Task 4 contract page-safe before advancing `customers.updated_since`.
- Acceptable fixes include removing the cap for this mirror endpoint, or returning a stable continuation cursor and having `pullCustomers()` loop until the server says the page is exhausted.
- If pagination remains, do not use server wall-clock `synced_at` as the persisted cursor for a partial page. Use a stable high-water mark that cannot skip rows sharing the boundary timestamp, or a composite cursor such as `(updated_at, id)`.
- Add a regression test for more than one page of customers. The test must prove all rows are upserted and the final stored cursor is advanced only after the full result set is consumed.

## Passing Axes

### Cross-tenant/company safety

PASS for the in-scope service logic. `customerSyncService.ts:75-81` validates every returned row before starting upserts, so a drift row prevents all writes in that response. The test at `customerSyncService.test.ts:102-114` asserts no upsert and no cursor advancement for a company drift.

### Fail-loud vs silent downgrade

PASS for top-level payload shape. `parseResponse()` rejects missing/non-array `customers` and missing/blank `synced_at` at `customerSyncService.ts:44-56`. Tests cover both malformed top-level cases at `customerSyncService.test.ts:116-140`.

### Cursor correctness

PARTIAL. The implementation uses the server cursor rather than the device clock, and it omits `updated_since` on first pull. The page-cap issue above means that cursor is still unsafe to persist after a partial server result.

### Dead-path rebuild

PASS. Task 4 only creates the pull service and tests. The plan does not require scheduler/UI wiring in this task, so the absence of a live caller is not a Task 4 defect.

### D16 bounded-module guard

PASS. The Task 4 files import the POS API helper, local POS database repositories, and the local customer type only. No hard dependency on Treasury, Customer, B2B, or Accounting modules was found.

### Contract drift with Task 2 mirror row

PASS. The service passes the server row directly to `upsertCustomer()`, and the Task 3 resource fields match the Task 2 `CustomerMirrorRow` shape after `apiGet()` unwraps Laravel's top-level `data` envelope.

## Test Gaps

- Add a mixed-batch drift test with one valid row followed by one foreign tenant/company row. The current implementation is correct because it validates all rows before writing, but the existing test would not catch a future refactor that validates and upserts row-by-row.
- Add an explicit tenant-drift case. The current company-drift test exercises the same branch, but Task 4's safety property is tenant and company isolation.

## Verification Run

- `cd apps/pos && pnpm test -- customerSyncService.test.ts` — passed, 1 file / 5 tests.
