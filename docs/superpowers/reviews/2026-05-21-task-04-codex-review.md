# Task 04 Codex Self-Adversarial Review — POS Customer Pull Sync And Cursor

## Scope Reviewed

- Implementation commit: `f9023476c Phase 2.4.1: Sync POS customer mirror`
- Plan anchor: `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md` Task 4
- Files reviewed:
  - `apps/pos/src/lib/customer/customerSyncService.ts`
  - `apps/pos/src/lib/customer/__tests__/customerSyncService.test.ts`

## Verdict

APPROVE.

The Task 4 implementation pulls the server customer mirror through the Phase 2 sync endpoint, uses the existing `sync_metadata` repository for the `customers.updated_since` cursor, validates all tenant/company scope before writing any row, and fails loudly for malformed server payloads.

## Attack Vectors Reviewed

### Cross-tenant/company FK safety

PASS. `pullCustomers()` validates every returned row against the active `tenantId` and `companyId` before calling `upsertCustomer()`. The negative test asserts a company drift throws `CustomerSyncScopeError` and leaves both `upsertCustomer()` and `setSyncMetadata()` untouched.

### Fail-loud vs silent-downgrade

PASS. Initial review found a silent-downgrade risk where missing `customers` could become an empty pull and missing `synced_at` could fall back to the local device clock. That was fixed before commit. `parseResponse()` now requires a `customers` array and non-empty `synced_at` cursor, with tests proving malformed responses do not upsert or advance the cursor.

### Dead-path rebuild

PASS for Task 4 scope. The service is directly covered by focused unit tests and reuses live repositories/API helpers. Production scheduler/UI wiring is intentionally deferred to later plan tasks, so this review does not claim customer mirror auto-pull is user-triggered yet.

### Cursor correctness

PASS. First pull omits `updated_since`; subsequent pulls send the stored cursor. The stored value is the server `synced_at`, not a device-generated timestamp, avoiding local clock skew.

### D16 bounded-module guard

PASS. The new POS service imports only POS API and local database repositories. Grep for Treasury/Customers/B2B/Accounting forbidden imports over the Task 4 files returned no matches.

### Constructor/container rule

PASS. No PHP code was touched, and the TypeScript files introduce no service-locator analogue.

### Discriminated-union / branch matrix

PASS. Task 4 does not wrap a discriminated-union DTO. The tested branch matrix covers cursor present, first pull, tenant/company drift, missing customers array, and missing synced_at cursor.

## Verification Evidence

- Focused POS test: `pnpm test -- customerSyncService.test.ts` — 1 file, 5 tests passed.
- POS typecheck: `pnpm typecheck` — exit 0.
- POS lint: `pnpm lint` — exit 0 with the existing 42 warnings outside Task 4.
- Full POS suite: `pnpm test` — 155 files, 1402 tests passed.
- Backend Fiscal/POS suite: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1092 tests, 3653 assertions, 16 PHPUnit deprecations, 107 skipped, 2 incomplete.
- Chokepoint/pass2b/diff gate: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh && git diff --check` — pass.
- D16 grep on Task 4 files — no matches.

## Residual Risk

The service is not yet invoked by the POS sync scheduler or UI. That is an expected sequencing gap for Task 4 rather than a defect in this commit; the later sync/UX tasks must provide the live caller.
