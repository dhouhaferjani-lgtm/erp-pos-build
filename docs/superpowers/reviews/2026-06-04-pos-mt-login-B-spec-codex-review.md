# Codex Review: Sub-Spec B POS Multi-Tenant Login Logout Separation

- **Date:** 2026-06-04
- **Reviewer:** Codex
- **Spec reviewed:** `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md`
- **Scope:** Frontend `apps/pos`; review grounded in the current worktree.

## Grounding Notes

I read the required files in full: `Header.tsx`, `PinEntryPage.tsx`, `authStore.ts`, `operatorStore.ts`, `SettingsPage.tsx`, `App.tsx`, `syncScheduler.ts`, `BootstrapErrorScreen.tsx`, and `storage.ts`. There is no `apps/pos/src/router/` directory content in this worktree, so I treated `apps/pos/src/App.tsx` and `apps/pos/src/components/AppShell.tsx` as the routing configuration.

I also searched `apps/pos/src` for `logout()` and `LOGIN_TENANT_ID` usage. The source evidence below cites real file lines; absence claims are called out explicitly where relevant.

## Findings

### MAJOR | Existing cashier-reachable terminal reset conflicts with the "single privileged device teardown" acceptance criterion

**Problem:** The spec moves full auth/tenant unbind into manager-only Settings, but current Settings already exposes a terminal reset path to every signed-in operator. This does not clear `LOGIN_TENANT_ID`, but it does tear down the POS terminal binding by resetting `TERMINAL`, `PENDING_TERMINAL_ID`, and `SHIFT`. If the intent is "cashiers cannot tear down terminal identity," this path remains open after Sub-Spec B unless the spec explicitly gates or excludes it.

**Evidence:** The Header Settings button is rendered without a role check (`apps/pos/src/components/Header.tsx:495`-`apps/pos/src/components/Header.tsx:502`). `AppRouter` only reaches `AppShell` after an operator is present and the screen is not locked (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`), and `AppShell` exposes `/settings` without a role guard (`apps/pos/src/components/AppShell.tsx:139`-`apps/pos/src/components/AppShell.tsx:144`). In Settings, the "change terminal" button calls `useTerminalStore.getState().reset()` (`apps/pos/src/pages/SettingsPage.tsx:587`-`apps/pos/src/pages/SettingsPage.tsx:592`), and `terminalStore.reset()` deletes the stored terminal, pending terminal, and shift (`apps/pos/src/stores/terminalStore.ts:661`-`apps/pos/src/stores/terminalStore.ts:668`).

**Recommended fix:** Move "Change terminal" into the same manager-only Device & Security section or explicitly document it as an allowed non-auth terminal-rebind escape with separate acceptance criteria and tests. My recommendation is to manager-gate it, because otherwise B closes auth unbind while leaving terminal identity reset cashier-reachable.

### MAJOR | The logout caller inventory is incomplete, and one omitted user-facing caller violates the spec's stated surface rule

**Problem:** The spec says the other kept `logout()` callers are `App.tsx`, `syncScheduler.ts`, and `BootstrapErrorScreen.tsx`. The current worktree also has a user-facing `TerminalSetupPage` logout button. Separately, the automatic startup 401 caller is in `authStore.initialize()`, not `App.tsx`. This matters because the acceptance criterion says no user-facing surface except Settings can fully tear down the device.

**Evidence:** `TerminalSetupPage` renders `<LogoutButton />` in setup tabs (`apps/pos/src/pages/TerminalSetupPage.tsx:133`-`apps/pos/src/pages/TerminalSetupPage.tsx:140`), and that button wires `onClick={logout}` from `useAuthStore` (`apps/pos/src/pages/TerminalSetupPage.tsx:371`-`apps/pos/src/pages/TerminalSetupPage.tsx:382`). The startup 401 path calls `get().logout()` inside `authStore.initialize()` (`apps/pos/src/stores/authStore.ts:160`-`apps/pos/src/stores/authStore.ts:167`). The confirmed sync 401 path calls `useAuthStore.getState().logout()` (`apps/pos/src/lib/sync/syncScheduler.ts:168`-`apps/pos/src/lib/sync/syncScheduler.ts:179`). `BootstrapErrorScreen` also selects and calls `logout` (`apps/pos/src/components/BootstrapErrorScreen.tsx:20`-`apps/pos/src/components/BootstrapErrorScreen.tsx:23`, `apps/pos/src/components/BootstrapErrorScreen.tsx:101`-`apps/pos/src/components/BootstrapErrorScreen.tsx:118`).

**Recommended fix:** Amend the spec to handle `TerminalSetupPage` explicitly. Either keep it as a documented pre-terminal recovery escape that calls `logout()` but never `unbindDevice()`, or remove/gate it if "only Settings" is literal. Also correct the "App.tsx" wording to `authStore.initialize()` for the startup 401 path.

### MAJOR | Removing PIN-screen sign-out creates an acknowledged lock-screen dead-end unless a manager can unlock first

**Problem:** After the PIN-screen Sign Out is removed, Settings is not reachable from the locked screen. A terminal locked while no manager is available cannot be unbound in-app. That may be an intentional loss-prevention tradeoff, but the spec currently treats Settings as the only unbind path without documenting the operational recovery path for single-operator shops, no-manager-role tenants, or a manager-away scenario.

**Evidence:** Locking sets `isLocked: true` (`apps/pos/src/stores/operatorStore.ts:348`-`apps/pos/src/stores/operatorStore.ts:350`). `AppRouter` renders `PinEntryPage` whenever `!operator || isLocked` (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`), before `AppShell` and its `/settings` route can mount (`apps/pos/src/components/AppShell.tsx:139`-`apps/pos/src/components/AppShell.tsx:144`). The current PIN screen's sign-out path is a standalone escape today (`apps/pos/src/pages/PinEntryPage.tsx:91`-`apps/pos/src/pages/PinEntryPage.tsx:117`) and calls full cleanup plus `logout()` (`apps/pos/src/pages/PinEntryPage.tsx:27`-`apps/pos/src/pages/PinEntryPage.tsx:36`).

**Recommended fix:** Either explicitly accept this as policy and add an operational recovery criterion, or add a manager-authenticated lock-screen unbind path, such as a hidden/secondary manager PIN challenge that calls the same Settings unbind flow. If the business cannot guarantee a manager PIN is always available, this should be fixed before implementation.

### MINOR | The spec's "Header Logout has no confirmation" premise is stale

**Problem:** The problem statement says the home-header Logout performs full device teardown with no confirmation. Current code has a confirmation modal before `handleLogout()`. The removal decision can still stand, but the rationale should be corrected so tests and review do not chase a non-existent no-confirmation bug.

**Evidence:** The Header logout button only opens `showLogoutConfirm` (`apps/pos/src/components/Header.tsx:504`-`apps/pos/src/components/Header.tsx:513`). The modal's confirm button then calls `handleLogout()` (`apps/pos/src/components/Header.tsx:565`-`apps/pos/src/components/Header.tsx:590`).

**Recommended fix:** Update the current-state/problem text: Header Logout is manager-gated and confirmed, but it is still an everyday-surface device teardown and should be removed.

### MINOR | Settings currently has no operator role access; the implementation must add it and rely on the route guard

**Problem:** The spec says Settings will render the Device & Security section only when the current operator is manager. Current `SettingsPage` does not import or read `useOperatorStore`, so this is new implementation work. Route guards mean Settings normally cannot render with `operator === null`, but the spec should state that the gate uses the current operator after PIN sign-in, not the email-auth device user.

**Evidence:** Current Settings imports `useAuthStore` and terminal/settings/connectivity stores, but no `useOperatorStore` (`apps/pos/src/pages/SettingsPage.tsx:1`-`apps/pos/src/pages/SettingsPage.tsx:22`). It reads only `serverUrl` from `authStore` (`apps/pos/src/pages/SettingsPage.tsx:51`-`apps/pos/src/pages/SettingsPage.tsx:56`). The app-level guard renders `PinEntryPage` when no operator exists or the screen is locked (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`).

**Recommended fix:** Add `useOperatorStore((s) => s.operator)` in `SettingsPage`, compute `isManager` with the same role list as Header, and include a regression test for direct `/settings` navigation with no operator or a locked screen.

## Probe Answers

### 1. Correctness of Current-State Claims

- Header Logout is gated by the current operator's roles, not merely operator existence. `isManager` checks `manager`, `admin`, and `owner` (`apps/pos/src/components/Header.tsx:88`-`apps/pos/src/components/Header.tsx:90`) and gates the button (`apps/pos/src/components/Header.tsx:504`-`apps/pos/src/components/Header.tsx:513`).
- PIN-screen sign-out calls full cleanup plus `logout()` with no manager check (`apps/pos/src/pages/PinEntryPage.tsx:27`-`apps/pos/src/pages/PinEntryPage.tsx:36`). It does have a confirmation modal (`apps/pos/src/pages/PinEntryPage.tsx:99`-`apps/pos/src/pages/PinEntryPage.tsx:121`).
- `logout()` does not clear `LOGIN_TENANT_ID`. It removes token/user/company/companies/terminal/pending-terminal keys only (`apps/pos/src/stores/authStore.ts:395`-`apps/pos/src/stores/authStore.ts:404`). `LOGIN_TENANT_ID` exists as a storage key (`apps/pos/src/lib/storage.ts:24`-`apps/pos/src/lib/storage.ts:26`) and is set/read by login (`apps/pos/src/stores/authStore.ts:264`-`apps/pos/src/stores/authStore.ts:288`), with no source `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` found in `apps/pos/src`.
- `App.tsx`, `syncScheduler.ts`, and `BootstrapErrorScreen.tsx` are not the only relevant callers. `TerminalSetupPage` also calls `logout()` from a user-facing button (`apps/pos/src/pages/TerminalSetupPage.tsx:371`-`apps/pos/src/pages/TerminalSetupPage.tsx:382`). The startup 401 call is in `authStore.initialize()`, not `App.tsx` (`apps/pos/src/stores/authStore.ts:160`-`apps/pos/src/stores/authStore.ts:167`).

### 2. Lock-Screen Stranding Risk

- Settings is not accessible from the lock screen because `AppRouter` returns `PinEntryPage` when `isLocked` is true (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`), and `/settings` exists only inside `AppShell` (`apps/pos/src/components/AppShell.tsx:139`-`apps/pos/src/components/AppShell.tsx:144`).
- The PIN screen is reachable both before operator sign-in and after lock: `!operator || isLocked` uses the same `PinEntryPage` branch (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`).
- The spec leaves a real in-app dead-end for shops where no manager PIN is available. A manager can unlock and use Settings; a cashier-only operator cannot reach manager-only unbind after the PIN sign-out is removed.

### 3. Settings Page Gating

- Current Settings does not have operator role access; it must be added (`apps/pos/src/pages/SettingsPage.tsx:1`-`apps/pos/src/pages/SettingsPage.tsx:22`).
- Settings is normally reachable only after an operator is signed in and the terminal is unlocked (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`; `apps/pos/src/components/AppShell.tsx:139`-`apps/pos/src/components/AppShell.tsx:144`).
- A legitimate manager who has not signed in with PIN cannot use Settings yet. They must enter PIN first; this is consistent with the current route guard.

### 4. `unbindDevice()` Placement and Cross-Store Cleanup

- Current Header `handleLogout()` clears cart, refund flow, refund draft, voucher tenders, payment store, product store, current operator, then calls `logout()` (`apps/pos/src/components/Header.tsx:382`-`apps/pos/src/components/Header.tsx:390`).
- The spec's cleanup list matches Header's logout cleanup. Note that `handleSwitchOperator()` additionally calls `discardPendingSubmission()` (`apps/pos/src/components/Header.tsx:370`-`apps/pos/src/components/Header.tsx:379`), but Header logout does not.
- Do not put feature-store cleanup inside `authStore`. `operatorStore` already imports `authStore` (`apps/pos/src/stores/operatorStore.ts:1`-`apps/pos/src/stores/operatorStore.ts:13`); making `authStore` import `operatorStore` would create a direct cycle. Keep `authStore.unbindDevice()` limited to `logout()` plus `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)`, and call shared POS cleanup outside auth.
- After unbind sets `isAuthenticated=false` via `logout()` (`apps/pos/src/stores/authStore.ts:395`-`apps/pos/src/stores/authStore.ts:397`), `AppRouter` routes to `/login` (`apps/pos/src/App.tsx:260`-`apps/pos/src/App.tsx:267`).

### 5. Security / Manipulation Gaps

- After removing Header Logout and PIN Sign Out, I found no cashier-reachable source path that clears `LOGIN_TENANT_ID`; current source has no `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` under `apps/pos/src`.
- I did find cashier-reachable terminal reset in Settings, which is a device-identity manipulation gap even though it does not clear auth tenant binding (`apps/pos/src/pages/SettingsPage.tsx:587`-`apps/pos/src/pages/SettingsPage.tsx:592`; `apps/pos/src/stores/terminalStore.ts:661`-`apps/pos/src/stores/terminalStore.ts:668`).
- The unbind authorization should be based on the current operator, not the email-auth device user. Header already uses current operator roles (`apps/pos/src/components/Header.tsx:51`-`apps/pos/src/components/Header.tsx:53`, `apps/pos/src/components/Header.tsx:88`-`apps/pos/src/components/Header.tsx:90`). Using `authStore.user` would authorize whoever performed the email login, not the cashier currently at the till.

### 6. Missing Test Cases Needed for Safe Ship

- Regression: `logout()` preserves `LOGIN_TENANT_ID`; `unbindDevice()` clears it.
- Regression: startup 401, sync confirmed 401, BootstrapErrorScreen Sign Out, and TerminalSetupPage Logout all call `logout()` and preserve `LOGIN_TENANT_ID`.
- Routing: direct `/settings` while `operator === null` or `isLocked === true` renders `PinEntryPage`, not Settings.
- Settings: manager operator sees Device & Security; cashier operator does not.
- Settings: confirm unbind runs every old Header cleanup step exactly once, then calls `unbindDevice()`.
- Settings: cancel unbind calls no cleanup and no auth action.
- Header: no Logout button for manager, admin, owner, cashier, or no operator; Switch and Lock remain.
- PIN screen: no Sign Out control for both `isLocked=true` and first PIN-entry mode.
- Terminal reset: either assert "Change terminal" is manager-only or explicitly assert it remains non-manager and does not clear `LOGIN_TENANT_ID`.
- Navigation: after confirmed unbind, `isAuthenticated=false` routes to `/login`.
- i18n: new English and French strings exist and no raw keys render.

## Open Question Answers

### Should old Header cleanup move into Settings or a shared helper?

Use a shared helper, not component-local duplication. A small `teardownPosSessionStores()` helper keeps the cleanup list testable and prevents future drift between user-initiated teardown surfaces. It should contain the exact current Header cleanup: clear cart, refund flow, refund draft, voucher tenders, payment reset, product reset, and operator clear (`apps/pos/src/components/Header.tsx:382`-`apps/pos/src/components/Header.tsx:390`). Settings should call the helper before `unbindDevice()`.

### Should `unbindDevice()` live in `authStore`?

Yes, but only for auth-owned teardown plus tenant-binding removal. `authStore.unbindDevice()` should be `get().logout()` plus `removeStoredValue(StorageKeys.LOGIN_TENANT_ID)` because `LOGIN_TENANT_ID` is an auth/login storage concern (`apps/pos/src/lib/storage.ts:24`-`apps/pos/src/lib/storage.ts:26`; `apps/pos/src/stores/authStore.ts:264`-`apps/pos/src/stores/authStore.ts:288`). Do not import cart/payment/operator/product stores into `authStore`; `operatorStore` already imports `authStore` (`apps/pos/src/stores/operatorStore.ts:1`-`apps/pos/src/stores/operatorStore.ts:13`), so reverse imports would create avoidable circular coupling.

## Verdict

**REQUEST-CHANGES** with **high confidence**.

The main design direction is sound, but the spec needs edits before implementation: it misses a current terminal reset surface, misses `TerminalSetupPage` as a logout caller, and does not address the lock-screen recovery tradeoff created by removing PIN-screen Sign Out.
