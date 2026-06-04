# POS Multi-Tenant Login Auth Store Review

Review target: `git diff ef60e64c5..c58cc6242 -- apps/pos/src/lib/storage.ts apps/pos/src/stores/authStore.ts apps/pos/src/stores/__tests__/authStore.test.ts`

Spec: `docs/superpowers/specs/2026-06-03-pos-multitenant-login-design.md`

Plan: `docs/superpowers/plans/2026-06-04-pos-multitenant-login-A.md`

Verdict: REQUEST-CHANGES

Counts: BLOCKER=0 MAJOR=2 MINOR=1 NIT=0

Validation run:

- `cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts` - PASS, 29 tests.
- `cd apps/pos && pnpm tsc --noEmit` - PASS.

## Findings

### MAJOR 1 - Abort can still produce an org-picker result or commit auth state after the signal is cancelled

Evidence:

- `apps/pos/src/stores/authStore.ts:265` reads `LOGIN_TENANT_ID` after the first POST returns an org-picker shape, but there is no `opts.signal.aborted` check after that non-network await.
- `apps/pos/src/stores/authStore.ts:270-278` proceeds into the auto-select re-POST based on the stored id after that read, again with no preflight abort check.
- `apps/pos/src/stores/authStore.ts:285-286` returns `{ status: 'requires_org_selection', organizations }` even if the user cancelled after the first POST completed and before/during the storage read.
- `apps/pos/src/stores/authStore.ts:216-218` forwards the signal to `/user/companies`, but `apps/pos/src/stores/authStore.ts:224-239` immediately persists token/user/companies and `LOGIN_TENANT_ID` after the GET resolves with no abort check before the commit block.

Problem:

The spec's abort contract is stronger than "pass the signal to fetch": aborting must cancel the whole login and commit no token/user/company/tenant state after abort. This implementation only lets the fetches observe the signal. If cancellation happens after a request has resolved but before the subsequent storage/state step runs, the code can still return an org picker or commit authenticated state. The worst case is `/user/companies` resolves, the user cancels, and the code still writes `auth_token`, `user`, `companies`, optional `company_id`, and `login_tenant_id`.

Recommended fix:

Add an abort gate after every awaited boundary that precedes a user-visible result or state/storage commit. In `completeAuthentication`, check `opts?.signal?.aborted` immediately after `/user/companies` resolves and before `setStoredValue(...)`; restore `priorAuth` and throw an abort error. Also gate after the tenant-hint read before either re-POSTing or returning the picker. If storage writes have already begun, either make the commit phase explicitly non-cancellable after a final pre-commit abort check, or add a rollback strategy that restores/removes the keys without deleting a prior valid session.

### MAJOR 2 - A failure reading the non-authoritative tenant hint aborts multi-tenant login instead of degrading to the picker

Evidence:

- `apps/pos/src/lib/storage.ts:31-33` shows `getStoredValue` can throw while loading/reading the store; unlike encrypted-token decrypt failures, there is no general catch around `getStore()` or `s.get(...)`.
- `apps/pos/src/stores/authStore.ts:265` awaits `getStoredValue<string>(StorageKeys.LOGIN_TENANT_ID)` inside the org-selection branch without a local catch.
- `apps/pos/src/stores/authStore.ts:285-286` only returns the picker after the hint read succeeds and either returns null or a stale id.

Problem:

`LOGIN_TENANT_ID` is explicitly a non-authoritative convenience hint. A read failure for that hint should behave like "no stored tenant" and show the organization picker. Instead, the thrown storage error bubbles through the outer login catch, so a user with a valid multi-tenant email can be blocked before the UI ever receives the organization list.

This differs from the write side, where `apps/pos/src/stores/authStore.ts:238-241` correctly wraps the best-effort `setStoredValue(StorageKeys.LOGIN_TENANT_ID, user.tenantId)`.

Recommended fix:

Wrap only the `LOGIN_TENANT_ID` read in a narrow `try/catch`. On failure, log at debug/warn level and treat the stored id as `null`, allowing the picker return path to run. Do not swallow errors from the authenticated persistence path.

### MINOR 1 - Spec-critical authStore edge cases are not covered by the new tests

Evidence:

- The new multi-tenant store tests start at `apps/pos/src/stores/__tests__/authStore.test.ts:510` and end at `apps/pos/src/stores/__tests__/authStore.test.ts:670`.
- They cover single-tenant success, no/stale stored tenant, persisted-tenant auto-select, manual pick persistence, explicit-tenant picker-shape error, one concurrent-login rejection, and wrong-password-after-auto-select.
- There are no tests in that block for abort during the email-first POST, abort after org-list/before auto-select, abort during the auto-select re-POST, abort during/after `/user/companies`, `/user/companies` failure after auto-select, `LOGIN_TENANT_ID` write failure, or `LOGIN_TENANT_ID` persist ordering after full auth commit.

Problem:

The spec explicitly calls out these behaviors, and the two MAJOR findings above are exactly the kind of gaps those tests would catch. The current tests also assert `LOGIN_TENANT_ID` was called in the manual-pick path (`apps/pos/src/stores/__tests__/authStore.test.ts:617-619`) but do not prove it happens after auth is fully committed.

Recommended fix:

Add focused store tests for the missing abort windows, auto-select `/user/companies` failure snapshot restore, best-effort tenant-hint write failure, and tenant-hint persist timing. For timing, assert observable state and invocation order relative to `/user/companies` and the auth state commit, not only that the mock was eventually called.

## Probe Surface Audit

### 1. Races / State Corruption

Single-flight guard: clean for normal login calls. `apps/pos/src/stores/authStore.ts:188-193` checks `get().isLoading` before calling `set({ isLoading: true, serverUrl })`. Zustand `set` is synchronous, so a second immediate `login()` call sees `isLoading: true` and rejects before entering the try/finally. A caller rejected by the guard never reaches the `finally`, so it cannot clear the first login's loading flag.

Sibling `isLoading` clearing: clean under store-owned login flow. Because a second login cannot pass the guard while the first login owns `isLoading`, `apps/pos/src/stores/authStore.ts:295` does not clear a sibling login in the intended path.

Temp token and auto-select re-POST: clean for the requested concern. The auto-select re-POST at `apps/pos/src/stores/authStore.ts:270-278` happens before `completeAuthentication(second)` is called at `apps/pos/src/stores/authStore.ts:281`. The temp token write is inside `completeAuthentication` at `apps/pos/src/stores/authStore.ts:212`, so the second POST does not run with the new temp token in the store. If a prior authenticated session already exists, `apiPost` may still include that prior token via the global API header logic, but this diff does not introduce a new temp-token header leak.

Partial state while loading: the existing transactional pattern still temporarily sets `token` before `/user/companies` at `apps/pos/src/stores/authStore.ts:212`; the reviewed question asked specifically whether `isLoading` can become false while partial state exists. On `/user/companies` failure the catch restores `priorAuth` at `apps/pos/src/stores/authStore.ts:219-221` before the outer finally clears loading at `apps/pos/src/stores/authStore.ts:295`. The remaining post-fetch abort gap is covered by MAJOR 1.

### 2. Abort Handling

Signal forwarding: clean. The signal is passed to the first POST at `apps/pos/src/stores/authStore.ts:255-257`, the auto-select re-POST at `apps/pos/src/stores/authStore.ts:271-278`, and `/user/companies` at `apps/pos/src/stores/authStore.ts:216-218`.

Abort after awaited boundaries: not clean. See MAJOR 1.

Abort between the two POSTs: no temp token exists yet, so there is no temp token to clean up. However, without an abort check after the hint read, a cancelled login may still proceed to the re-POST or return a picker. See MAJOR 1.

### 3. `LOGIN_TENANT_ID` Best-Effort Write

Write failure containment: clean. `apps/pos/src/stores/authStore.ts:238-241` catches `setStoredValue(StorageKeys.LOGIN_TENANT_ID, user.tenantId)` failures.

Read failure degradation: not clean. See MAJOR 2.

Persist timing relative to full auth commit: implementation is clean for the normal success path. The tenant hint is written after token/user/companies persistence (`apps/pos/src/stores/authStore.ts:224-226`), after in-memory auth commit (`apps/pos/src/stores/authStore.ts:228`), and after single-company selection if applicable (`apps/pos/src/stores/authStore.ts:230-233`). The test coverage for this timing is incomplete; see MINOR 1.

Value correctness: clean. The persisted value is `user.tenantId` at `apps/pos/src/stores/authStore.ts:239`, which is the server-authenticated tenant, not the untrusted stored hint or UI selection.

### 4. Auto-Select Correctness

Exactly one extra POST: clean. Auto-select is inline at `apps/pos/src/stores/authStore.ts:270-278`; it does not call `login()` recursively. If the second response is another org-picker shape, `apps/pos/src/stores/authStore.ts:280` throws `UnexpectedLoginResponseError`.

Same credentials/device fields: clean. The re-POST body reuses `email` and `password` and sends the same `device_id`, `device_name`, and `platform` fields at `apps/pos/src/stores/authStore.ts:271-278`.

Wrong password after auto-select: clean for state commitment. No temp token has been written before the second POST; if that POST rejects, the catch at `apps/pos/src/stores/authStore.ts:291-293` rethrows, and no auth persistence path has run. The test at `apps/pos/src/stores/__tests__/authStore.test.ts:653-669` covers this.

Body type: clean. The explicit tenant body includes `email`, `password`, `tenant_id`, `device_id`, `device_name`, and `platform` at `apps/pos/src/stores/authStore.ts:271-278`, matching the spec's required fields for POS login.

### 5. Contract: First POST Omits `tenant_id`

Clean. The first request body is created without `tenant_id` at `apps/pos/src/stores/authStore.ts:246-252`. It only gets `tenant_id` when the caller explicitly supplies `opts.tenantId` at `apps/pos/src/stores/authStore.ts:253`; the stored tenant is not read until after a first org-picker response at `apps/pos/src/stores/authStore.ts:265`.

No ambient body injection found. `LOGIN_TENANT_ID` is a storage key only and is not included in the first POST body.

### 6. Type Safety

Discriminated union narrowing: clean. `isOrgSelection` at `apps/pos/src/stores/authStore.ts:79-81` narrows the org-picker branch, and the success branch is passed to `completeAuthentication`.

Implementation implicit `any`: none found in the reviewed implementation.

Test non-null assertions: present but not a current defect. `apps/pos/src/stores/__tests__/authStore.test.ts:529` and `apps/pos/src/stores/__tests__/authStore.test.ts:587` use non-null assertions on `mock.calls`, but each is preceded by a call-count assertion. `apps/pos/src/stores/__tests__/authStore.test.ts:378-380` uses non-null assertions for invocation order; the test would still fail if the mocked calls were absent. This is not worth a finding.

### 7. Test Gaps

See MINOR 1. The highest-value missing tests are:

- abort after the first org-list response but before auto-select;
- abort after `/user/companies` resolves but before auth persistence;
- `/user/companies` failure after the auto-select re-POST restores the prior auth snapshot;
- `LOGIN_TENANT_ID` write failure does not throw and leaves auth committed;
- `LOGIN_TENANT_ID` read failure degrades to picker;
- tenant-hint persistence happens after full auth commit.
