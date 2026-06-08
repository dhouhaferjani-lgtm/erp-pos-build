# Round-2 Adversarial Review: authStore.ts (pos-multitenant-login)

Date: 2026-06-04  
Reviewer: Codex  
Branch: feat/pos-multitenant-login  
Fix commit: dcbd12bf0

## Prior Findings Resolution

### MAJOR 1 — RESOLVED

(a) There is now an abort check after `/user/companies` resolves and before the storage commit block. `completeAuthentication()` awaits `apiGet<Company[]>('/user/companies', ...)` at `apps/pos/src/stores/authStore.ts:236-238`; immediately after the `try/catch`, it checks `opts?.signal?.aborted`, restores `priorAuth`, and throws before any `setStoredValue(...)` call at `apps/pos/src/stores/authStore.ts:244-250`. The storage commit begins only afterward at `apps/pos/src/stores/authStore.ts:252-254`.

(b) There is now an abort check after the `LOGIN_TENANT_ID` read and before either re-POSTing or returning the picker. The read occurs inside the narrow `try/catch` at `apps/pos/src/stores/authStore.ts:286-292`, then `abortIfCancelled(opts?.signal)` runs at `apps/pos/src/stores/authStore.ts:294-296` before the match calculation, auto-select re-POST, or picker return at `apps/pos/src/stores/authStore.ts:298-316`.

(c) The thrown error is an AbortError. The shared helper throws `new DOMException('Aborted', 'AbortError')` at `apps/pos/src/stores/authStore.ts:199-200`, and the `/user/companies` post-resolve gate throws the same AbortError shape at `apps/pos/src/stores/authStore.ts:247-249`. The UI also has a recognized abort path: `LoginPage` suppresses errors when the same controller signal is aborted at `apps/pos/src/pages/LoginPage.tsx:64-70`.

(d) For the two round-1 abort windows, I found no remaining awaited boundary that can proceed to the picker return or durable auth commit without an intervening abort gate. The org-picker path gates after the storage read at `apps/pos/src/stores/authStore.ts:286-296` before the re-POST at `apps/pos/src/stores/authStore.ts:302-308` and picker return at `apps/pos/src/stores/authStore.ts:315-316`; the authenticated path gates after `/user/companies` at `apps/pos/src/stores/authStore.ts:236-250` before durable storage writes at `apps/pos/src/stores/authStore.ts:252-267`.

### MAJOR 2 — RESOLVED

(a) The `LOGIN_TENANT_ID` read is now best-effort. The code initializes `storedTenantId` to `null`, wraps only `getStoredValue<string>(StorageKeys.LOGIN_TENANT_ID)` in `try/catch`, logs a non-fatal warning, and keeps `storedTenantId = null` on failure at `apps/pos/src/stores/authStore.ts:284-292`; the picker return remains available at `apps/pos/src/stores/authStore.ts:315-316`.

(b) The catch is narrow and does not swallow authenticated persistence failures. It surrounds only the tenant-hint read at `apps/pos/src/stores/authStore.ts:287-292`; the authenticated persistence path is in `completeAuthentication()`, where `/user/companies` failures are rethrown after restoring prior auth at `apps/pos/src/stores/authStore.ts:235-242`, and durable auth writes still occur outside that read catch at `apps/pos/src/stores/authStore.ts:252-267`.

### MINOR 1 — RESOLVED

`read-failure -> picker`: Added at `apps/pos/src/stores/__tests__/authStore.test.ts:671-697`. The test forces `getStoredValue('login_tenant_id')` to throw at `apps/pos/src/stores/__tests__/authStore.test.ts:673-675`, receives an org-picker response at `apps/pos/src/stores/__tests__/authStore.test.ts:677-683`, asserts the picker result at `apps/pos/src/stores/__tests__/authStore.test.ts:687-693`, asserts one POST at `apps/pos/src/stores/__tests__/authStore.test.ts:695`, and asserts no authenticated state at `apps/pos/src/stores/__tests__/authStore.test.ts:696`.

`abort-after-companies`: Added at `apps/pos/src/stores/__tests__/authStore.test.ts:699-728`. The test aborts the controller inside the mocked `/user/companies` resolution at `apps/pos/src/stores/__tests__/authStore.test.ts:710-715`, expects an `Aborted` rejection at `apps/pos/src/stores/__tests__/authStore.test.ts:717-721`, and verifies no auth state or token persistence at `apps/pos/src/stores/__tests__/authStore.test.ts:723-727`.

`companies-fail-after-auto-select`: Added at `apps/pos/src/stores/__tests__/authStore.test.ts:730-756`. The test sets a matching stored tenant at `apps/pos/src/stores/__tests__/authStore.test.ts:732-734`, configures the picker response and auto-select success response at `apps/pos/src/stores/__tests__/authStore.test.ts:735-745`, forces `/user/companies` failure at `apps/pos/src/stores/__tests__/authStore.test.ts:746`, then asserts the error, restored unauthenticated state, and no auth-token persistence at `apps/pos/src/stores/__tests__/authStore.test.ts:748-756`.

`write-failure-swallowed`: Added at `apps/pos/src/stores/__tests__/authStore.test.ts:759-775`. The test makes only `setStoredValue('login_tenant_id')` reject at `apps/pos/src/stores/__tests__/authStore.test.ts:766-769`, then asserts login still resolves authenticated and the store is authenticated at `apps/pos/src/stores/__tests__/authStore.test.ts:772-775`.

These tests exercise the fixed paths non-vacuously: each one injects the relevant storage, abort, or companies-fetch failure before asserting the observable result at the lines cited above.

## buildLoginBody Correctness

The first POST preserves the pre-fix email-first behavior for normal inputs: `buildLoginBody()` includes `tenant_id` only when its argument is truthy at `apps/pos/src/stores/authStore.ts:206-212`, and the first login POST passes only `opts?.tenantId` at `apps/pos/src/stores/authStore.ts:274-277`. The stored `LOGIN_TENANT_ID` is not read until the org-picker branch at `apps/pos/src/stores/authStore.ts:284-288`, after the first POST has already completed.

The re-POST uses the stored tenant value, not `opts.tenantId`: the match is computed from `storedTenantId` and the returned organizations at `apps/pos/src/stores/authStore.ts:298-300`, then the second POST passes `buildLoginBody(storedTenantId ?? undefined)` at `apps/pos/src/stores/authStore.ts:302-308`. The normal stored-tenant test verifies the second body contains `tenant_id: 't-2'` at `apps/pos/src/stores/__tests__/authStore.test.ts:566-588`.

There is one edge-case drift versus the pre-fix inline re-POST body. The old re-POST body always had a `tenant_id` property once `match` was true, but the helper omits `tenant_id` for falsy strings because it uses `...(tenantId ? { tenant_id: tenantId } : {})` at `apps/pos/src/stores/authStore.ts:206-212`. Since `match` allows any non-null string at `apps/pos/src/stores/authStore.ts:298-300`, a stored empty string that also appears in the organizations list would reach the re-POST and omit `tenant_id` at `apps/pos/src/stores/authStore.ts:302-308`. Tenant IDs are expected to be non-empty, so this is low practical risk, but it is not exact "always includes the stored LOGIN_TENANT_ID" semantics.

## New Findings

MINOR: `buildLoginBody()` omits `tenant_id` for an empty-string stored tenant during auto-select. The helper's truthy spread is at `apps/pos/src/stores/authStore.ts:206-212`, while auto-select calls it with `storedTenantId ?? undefined` at `apps/pos/src/stores/authStore.ts:302-308`. Fix guidance: change the helper to include `tenant_id` when the argument is not `undefined`, or pass a separate `includeTenantId` flag so the first POST keeps its current semantics while the re-POST always serializes the stored tenant.

No new race condition, cleanup regression, or type error was found in the reviewed diff. The prior-auth rollback remains in the `/user/companies` catch at `apps/pos/src/stores/authStore.ts:235-242`; the new pre-commit abort rollback is at `apps/pos/src/stores/authStore.ts:247-250`; and `pnpm tsc --noEmit` exited 0 with no output.

## Test Run Results

Command:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts 2>&1 | tail -10
```

Tail output:

```text
    at /Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/apps/pos/src/stores/__tests__/authStore.test.ts:772:22
    at file:///Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/node_modules/.pnpm/@vitest+runner@3.2.4/node_modules/@vitest/runner/dist/chunk-hooks.js:752:20

 ✓ src/stores/__tests__/authStore.test.ts (33 tests) 75ms

 Test Files  1 passed (1)
      Tests  33 passed (33)
   Start at  10:30:58
   Duration  1.10s (transform 258ms, setup 59ms, collect 346ms, tests 75ms, environment 353ms, prepare 52ms)
```

Result: pass, 1 test file passed, 33 tests passed, 0 failed. The leading stack lines are from the intentional mocked storage/write failure warning path, not a test failure; the same tail reports 33/33 passing.

## TypeScript Check

Command:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/apps/pos && pnpm tsc --noEmit 2>&1 | tail -5
```

Tail output:

```text
```

Result: clean. The command exited 0 and produced no output.

## Verdict

APPROVE-WITH-MINOR-EDITS  
Confidence: high  
The two prior major defects are resolved with cited abort gates and narrow storage-error handling; only a low-risk helper edge case prevents a clean approve.
