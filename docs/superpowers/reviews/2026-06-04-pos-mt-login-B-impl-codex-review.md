# Codex Adversarial Review: Sub-Spec B POS Multi-Tenant Login Implementation

- **Date:** 2026-06-04
- **Reviewer:** Codex
- **Scope:** Sub-Spec B implementation for loss-prevention / device teardown gating
- **Diff reviewed:** `git diff 9f71763c2..6a17a05f7 -- apps/pos/src`
- **Spec:** `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md`

## Verification Commands

### Diff Read

Command run:

```bash
git diff 9f71763c2..6a17a05f7 -- apps/pos/src
```

Result: diff was readable and covered `Header.tsx`, `PinEntryPage.tsx`, `SettingsPage.tsx`, `authStore.ts`, `teardownPosSessionStores`, tests, and i18n.

### Targeted Tests

Command run:

```bash
pnpm vitest run src/stores/__tests__/authStore.test.ts src/components/__tests__/Header.test.tsx src/pages/__tests__/PinEntryPage.test.tsx src/pages/__tests__/SettingsPage.test.tsx 2>&1 | tail -8
```

Actual output:

```text
 ✓ src/stores/__tests__/authStore.test.ts (35 tests) 74ms

 Test Files  4 passed (4)
      Tests  45 passed (45)
   Start at  16:20:30
   Duration  1.26s (transform 458ms, setup 308ms, collect 1.03s, tests 183ms, environment 1.82s, prepare 201ms)
```

### TypeScript

Command run:

```bash
pnpm tsc --noEmit 2>&1 | tail -3
```

Actual output:

```text
```

Exit code: `0`. The command produced no output in the final three lines.

## Findings

### MAJOR: Stale unbind modal can outlive the manager gate and still execute

**Problem:** The Device & Security section is manager-gated, but the destructive confirmation modal is rendered outside that gate and its confirm handler does not re-check `isManager`. If `showUnbindConfirm` is already `true`, a later operator-role transition to non-manager hides the section but leaves the modal confirm path active. That violates the fail-closed property the spec asks this implementation to provide for stale renders.

**Evidence:**
- Manager gate is computed from current operator roles with a fail-closed default: `apps/pos/src/pages/SettingsPage.tsx:60-63`.
- Only the section/button is gated by `{isManager && (...)}`: `apps/pos/src/pages/SettingsPage.tsx:595-623`.
- The modal is rendered unconditionally outside that gate: `apps/pos/src/pages/SettingsPage.tsx:667-697`.
- The confirm handler closes the modal, tears down stores, and calls `unbindDevice()` without checking that the current operator is still manager/admin/owner: `apps/pos/src/pages/SettingsPage.tsx:682-688`.

**Recommended fix:** Make the destructive action fail closed at the action boundary, not only at the opener UI. Either render the modal only under `isManager`, or add both an effect that closes it when `!isManager` and a confirm-time guard:

```ts
if (!isManager) {
  setShowUnbindConfirm(false);
  return;
}
```

Add regression coverage where the modal is opened as manager, the operator roles change to cashier/null before confirmation, and confirm does not call teardown or `unbindDevice()`.

### MAJOR: `unbindDevice()` does not await or handle the tenant-binding deletion

**Problem:** The security acceptance criterion is that confirmed unbind clears `LOGIN_TENANT_ID` so the next login is email-first. The implementation schedules `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` as fire-and-forget and returns immediately. If the Tauri store delete rejects, is dropped during process teardown, or races with a fast next login attempt, the stale tenant hint can silently survive the manager-confirmed unbind. This is especially risky because the codebase already documents stale-storage races caused by fire-and-forget logout removals.

**Evidence:**
- `unbindDevice()` calls `get().logout()` then discards the `LOGIN_TENANT_ID` delete promise: `apps/pos/src/stores/authStore.ts:437-439`.
- `removeStoredValue` is asynchronous and can reject from store load/delete: `apps/pos/src/lib/storage.ts:70-72`.
- Settings confirm calls `teardownPosSessionStores()` then `unbindDevice()` synchronously, with no await/error handling: `apps/pos/src/pages/SettingsPage.tsx:684-688`.
- AppRouter comments explicitly describe a stale re-read risk from fire-and-forget logout storage removals: `apps/pos/src/App.tsx:104-111`.
- The regression test only asserts the mock was called, not that the deletion completed or failures are handled: `apps/pos/src/stores/__tests__/authStore.test.ts:784-788`.

**Recommended fix:** Change `unbindDevice` to return `Promise<void>` and await the tenant-key deletion. The Settings confirm path should await it before considering the destructive action complete, and should surface/retry a failure instead of silently routing to login with a stale tenant binding. If `logout()` must stay synchronous for existing 401 paths, keep `logout()` unchanged but make `unbindDevice()` explicitly await `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` after the synchronous auth-state reset.

### MINOR: Tests do not prove several Sub-Spec B security properties they claim to cover

**Problem:** The targeted tests pass, but parts of the security contract remain unproved or are asserted only weakly. This makes future regressions likely to pass CI while re-opening the teardown path.

**Evidence:**
- `SettingsPage` test name says "teardown then unbindDevice", but it only checks both functions were called once and does not assert call order: `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:163-170`.
- `SettingsPage` tests cover manager and cashier roles, but not `operator === null`, `roles === undefined`, admin/owner allowed roles, or stale-modal role downgrade before confirm: `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:95-105` and `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:149-181`.
- `SettingsPage` tests do not assert "Change terminal" is hidden/unreachable for a cashier even though it is the other destructive action moved into Device & Security: `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:156-160`.
- `Header` tests cover manager and cashier only, not admin/owner/no-operator despite the spec requiring no logout for any role: `apps/pos/src/components/__tests__/Header.test.tsx:174-188`.
- The route-level test suite read in this review covers other AppRouter recovery behavior but does not add the spec-required direct `/settings` guard cases for `operator === null` or `isLocked`: `apps/pos/src/__tests__/AppRouter.test.tsx:121-435`.

**Recommended fix:** Add focused tests for fail-closed role shapes, stale modal confirmation, teardown-before-unbind call order using invocation order, cashier-hidden Change terminal, Header no-operator/admin/owner cases, and direct `/settings` rendering `PinEntryPage` when `operator === null` or locked.

## Security Questions

### Q1 - Gate Integrity

The main Settings section gate uses the current operator, not the auth user, and fails closed for null/undefined shapes: `operator?.roles?.some(...) ?? false` at `apps/pos/src/pages/SettingsPage.tsx:60-63`. `operator === null` and `roles === undefined` therefore do not render the Device & Security section.

Route-level gating also prevents ordinary direct `/settings` navigation without an active unlocked operator: AppRouter renders `PinEntryPage` when `!operator || isLocked` at `apps/pos/src/App.tsx:320-323`, before `AppShell` and its `/settings` route are mounted at `apps/pos/src/App.tsx:343-347`. `AppShell` is where `/settings` is declared at `apps/pos/src/components/AppShell.tsx:139-145`.

However, the unbind confirmation modal itself is not fail-closed against stale manager state. See MAJOR finding 1.

### Q2 - Orphaned Teardown Paths

The required grep was run exactly. Non-test call sites of destructive auth/terminal teardown are:

- `authStore.initialize()` confirmed startup 401 path calls `logout()`: `apps/pos/src/stores/authStore.ts:161-169`.
- `BootstrapErrorScreen` recovery Sign out calls `logout()`: `apps/pos/src/components/BootstrapErrorScreen.tsx:101-118`.
- `SyncScheduler` confirmed 401 calls `logout()`: `apps/pos/src/lib/sync/syncScheduler.ts:168-180`.
- `TerminalSetupPage` post-login/pre-terminal setup logout calls `logout()`: `apps/pos/src/pages/TerminalSetupPage.tsx:371-382`.
- `SettingsPage` confirmed manager unbind calls `unbindDevice()`: `apps/pos/src/pages/SettingsPage.tsx:682-688`.
- `SettingsPage` manager-gated Change terminal calls `terminalStore.reset()`: `apps/pos/src/pages/SettingsPage.tsx:603-607`.
- `authStore.logout()` itself resets terminal state and removes auth/terminal storage keys, but not `LOGIN_TENANT_ID`: `apps/pos/src/stores/authStore.ts:396-428`.
- `terminalStore.reset()` clears terminal/pending/shift state and storage: `apps/pos/src/stores/terminalStore.ts:661-668`.

I did not find another cashier-reachable everyday operations path that calls `authStore.logout()`, `unbindDevice()`, `terminalStore.reset()`, or removes the auth/terminal keys. The old Header logout path is gone; Header now has Switch, Lock, Reports, fullscreen, and Settings controls only at `apps/pos/src/components/Header.tsx:434-484`. The old PIN Sign Out path is gone; `PinEntryPage` renders only the PIN UI at `apps/pos/src/pages/PinEntryPage.tsx:35-74`.

### Q3 - `unbindDevice()` Correctness

`logout()` still does not clear `LOGIN_TENANT_ID`, which preserves the tenant binding for 401/setup/bootstrap paths as specified. Its remove calls cover token/user/company/terminal/pending terminal but not `LOGIN_TENANT_ID`: `apps/pos/src/stores/authStore.ts:396-405`.

`unbindDevice()` does call `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` after `logout()`: `apps/pos/src/stores/authStore.ts:437-439`. The regression test asserts the call and state reset: `apps/pos/src/stores/__tests__/authStore.test.ts:784-788`, and separately asserts `logout()` does not clear it: `apps/pos/src/stores/__tests__/authStore.test.ts:790-793`.

The correctness gap is durability/error handling: the delete promise is discarded. See MAJOR finding 2.

### Q4 - `teardownPosSessionStores()` Completeness

The helper clears the same feature-store set formerly inline in Header logout: cart, refund flow, refund draft, voucher tenders, payment reset, product reset, and operator clear at `apps/pos/src/lib/session/teardownPosSession.ts:13-20`. That matches the removed Header cleanup shown in the diff and is tested for exactly-once calls at `apps/pos/src/lib/session/__tests__/teardownPosSession.test.ts:16-23`.

Settings invokes the helper before `unbindDevice()`: `apps/pos/src/pages/SettingsPage.tsx:684-688`.

### Q5 - SettingsPage Modal Security

Cancel does not teardown or unbind; it only clears modal state at `apps/pos/src/pages/SettingsPage.tsx:675-681`, and the test asserts neither teardown nor unbind is called after cancel at `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:172-180`.

The modal cannot be opened through the normal rendered UI unless `isManager` is true because the opener button is inside the gated section at `apps/pos/src/pages/SettingsPage.tsx:595-623`. After confirm, `unbindDevice()` calls `logout()`, which synchronously sets `isAuthenticated` false at `apps/pos/src/stores/authStore.ts:396-399`; AppRouter routes unauthenticated users to `/login` at `apps/pos/src/App.tsx:260-266`.

The modal security gap is stale state: the modal is outside the manager gate and the confirm handler does not re-check the gate. See MAJOR finding 1.

### Q6 - Dead Code / Build Integrity

The build check passed with exit code 0 and no tail output. I did not find orphaned imports or dead state in the changed TypeScript files that breaks compilation. `Header` no longer imports `LogOut` or `useProductStore`; it still uses cart/payment/refund imports for Switch operator at `apps/pos/src/components/Header.tsx:363-372`. `PinEntryPage` no longer imports auth/cart/payment/product/refund stores and has no `showSignOut` state: `apps/pos/src/pages/PinEntryPage.tsx:1-18`.

There are stale i18n strings for the removed old Sign Out surfaces (`settings.signOutTerminal`, `settings.signOutConfirmMessage`, `settings.signOutFromPin`) at `apps/pos/src/locales/en/pos.json:442-445` and `apps/pos/src/locales/fr/pos.json:442-445`, but they do not affect build integrity.

### Q7 - Test Integrity

The tests prove the broad happy-path removals and the basic manager/cashier Settings gate:

- Header logout absent for manager and cashier: `apps/pos/src/components/__tests__/Header.test.tsx:179-188`.
- PIN Sign Out absent in locked and PIN-entry modes: `apps/pos/src/pages/__tests__/PinEntryPage.test.tsx:56-64`.
- Device & Security visible for manager and hidden for cashier: `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:149-160`.
- Confirm/cancel call or do not call the expected functions: `apps/pos/src/pages/__tests__/SettingsPage.test.tsx:163-180`.
- `unbindDevice()` invokes the tenant-key removal mock and `logout()` does not: `apps/pos/src/stores/__tests__/authStore.test.ts:779-793`.

They do not prove several adversarial cases: stale-modal fail-closed behavior, actual async deletion completion/failure handling, teardown-before-unbind ordering, admin/owner/no-operator role matrix, undefined roles, cashier-hidden Change terminal, or direct `/settings` route guarding. See MINOR finding 3.

## Verdict

**REQUEST-CHANGES** with **high** confidence.

The everyday Header and PIN teardown paths were removed, Change terminal was moved behind the current-operator manager gate, and the kept logout escapes match the spec inventory. The remaining issues are concentrated in the destructive action boundary: the confirm path should fail closed if the manager gate becomes stale, and `LOGIN_TENANT_ID` deletion should be awaited/handled so the manager-confirmed unbind actually clears the tenant binding durably.
