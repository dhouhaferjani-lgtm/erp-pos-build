# POS Logout Separation + Manager-Gated Device Unbind — Sub-Spec B of 3

- **Date:** 2026-06-04
- **Branch:** `feat/pos-multitenant-login` (worktree off `origin/dev`; Sub-Spec A already merged to `dev`)
- **Status:** Design approved by owner; pending Codex adversarial review before implementation plan.
- **Predecessor:** Sub-Spec A (email-first multi-tenant login) — DONE/merged. This sub-spec closes A's deferred Codex MAJOR 5 (the tenant-binding reset path).

## Problem

The POS desktop conflates two different "sign out" concepts, and exposes full device
teardown on everyday surfaces with no confirmation and inconsistent gating — a
loss-prevention / manipulation hole:

- **Home header** has a manager-only **Logout** button that calls `authStore.logout()`
  (full device teardown) with **no confirmation**.
- **Locked / PIN screen** has a **"Sign Out"** that calls `authStore.logout()` (full
  device teardown) with a confirm modal but **no manager gate** — any cashier at the
  lock screen can unbind the terminal.

Industry norms (retail loss-prevention, PCI-DSS lock-vs-reauth, NF525 operator-vs-fiscal
-day) say: cashiers lock / sign off as *operators*; tearing down a terminal's identity is
a privileged, deliberate, manager action. Sub-Spec A also deferred its tenant-binding
reset (`LOGIN_TENANT_ID`) to here.

## Current state (verified in code)

- `Header.tsx`: `Switch` button (`operatorStore.clearOperator()` — operator sign-off,
  everyone), `Lock` button (`operatorStore.lock()` — screen lock, everyone), and a
  manager-gated (`isManager &&`) `Logout` button → `handleLogout()` which clears
  cart/payment/refund/product stores + `clearOperator()` + `authStore.logout()`.
  `isManager = operator?.roles?.some(r => ['manager','admin','owner'].includes(r))`.
- `PinEntryPage.tsx`: a `showSignOut` confirm modal whose `handleSignOut()` does
  `clearOperator()` + `authStore.logout()` (device teardown), ungated.
- `authStore.logout()`: resets state to `initialState`, removes TOKEN/USER/COMPANY_ID/
  COMPANIES/TERMINAL/PENDING_TERMINAL_ID, `disconnectEcho()`, `terminalStore.reset()`,
  `clearScanCache()`, `bootstrapStore.reset()`. It does **NOT** remove `LOGIN_TENANT_ID`
  (Sub-Spec A deliberately deferred that).
- `authStore.logout()` is ALSO called automatically by `App.tsx` (401 during
  `initialize()`), `syncScheduler.ts` (confirmed 401), and `BootstrapErrorScreen.tsx`
  (degraded-state "Sign Out").
- `SettingsPage.tsx`: sections for display/touch/security/printers/terminal-info/about;
  no manager gating today. `Modal` base component + `RefundConfirmModal` pattern exist.

## Decisions (locked with owner)

1. **Remove device teardown from everyday surfaces.** Remove the home-header Logout
   button and the PIN-screen "Sign Out". Switch + Lock remain on the home header for
   everyone (these are the cashier "session logout"; they keep the device session and
   `LOGIN_TENANT_ID`).
2. **Single privileged unbind in Settings.** A new **"Device & Security"** Settings
   section, rendered only when the current operator `isManager`, with a **"Sign out &
   unbind device"** button behind a confirmation `Modal`.
3. **Unbind clears the tenant binding.** The Settings unbind = full teardown **plus**
   clearing `LOGIN_TENANT_ID`, so the next login is a full email-first login (picker if
   multi-tenant). This is the in-app reset that closes Sub-Spec A's MAJOR 5.

## Scope

- **In scope (B):** `apps/pos` — `authStore`, `Header`, `PinEntryPage`, `SettingsPage`,
  i18n.
- **Out of scope:** backend (no changes); `apps/web`; audit-event emission (**Sub-Spec
  C**); operator/PIN auth mechanics (unchanged); the automatic 401 logout paths and the
  `BootstrapErrorScreen` recovery escape (deliberately kept — see below).

## Design

### `authStore.unbindDevice()` (new action)

```ts
unbindDevice: () => void;
```

Performs the exact `logout()` teardown **and** additionally removes the tenant binding:

```ts
unbindDevice: () => {
  get().logout();                                  // full device teardown (unchanged)
  void removeStoredValue(StorageKeys.LOGIN_TENANT_ID);
},
```

`logout()` stays **unchanged** (still does NOT clear `LOGIN_TENANT_ID`), so the automatic
401 paths and bootstrap-error escape keep the binding (a token expiry must not re-prompt
for tenant on the same terminal). Only `unbindDevice()` clears it.

### `Header.tsx`

- **Remove** the `isManager`-gated Logout button JSX and `handleLogout()`.
- Keep Switch + Lock + Settings buttons unchanged.
- The full store cleanup that `handleLogout` performed (clear cart, refund flow, refund
  draft, voucher tenders, payment reset, product reset, operator) **moves to the Settings
  unbind handler** so the privileged teardown remains thorough (Settings is now the only
  user-initiated full teardown).

### `PinEntryPage.tsx`

- **Remove** the `showSignOut` state, the "Sign Out" trigger button, and the sign-out
  confirm modal + `handleSignOut()`. The lock/PIN screen can only move forward (enter PIN
  to unlock); it offers no device teardown.

### `SettingsPage.tsx`

- Add a **"Device & Security"** section rendered only when the current operator
  `isManager` (`operator?.roles?.some(r => ['manager','admin','owner'].includes(r))`,
  same check as Header).
- Button → confirmation `Modal` (reuse the existing `Modal` component) with clear copy
  ("This signs the terminal out and a full login will be required next time."). On
  confirm: run the same store cleanup as the old `handleLogout`, call
  `authStore.unbindDevice()`, close the modal. The app's router then routes to the login
  screen (because `isAuthenticated` is now false), as it does today after logout.

### i18n

New `pos` keys (en + fr): `settings.deviceSecurity`, `settings.deviceUnbind`,
`settings.deviceUnbindConfirmTitle`, `settings.deviceUnbindConfirmMessage`,
`settings.deviceUnbindConfirm`, `settings.cancel` (reuse if present).

## Deliberate exceptions (kept, documented)

- **Automatic 401 logouts** (`App.tsx`, `syncScheduler.ts`): not user-initiated; keep
  calling `logout()` (binding survives — same terminal/business after a token refresh).
- **`BootstrapErrorScreen` "Sign Out"**: degraded-state recovery escape; the app is
  unusable and Settings is unreachable. Keep as an ungated escape (out of scope). It
  calls `logout()` (not `unbindDevice()`), so it does not clear the binding.

## Events Sub-Spec C must capture (enumerated here so nothing is missed)

When C builds the audit pipeline it must emit auditable events for: operator sign-in
(PIN verify), operator sign-off (`clearOperator`), screen lock (`operatorStore.lock`),
**device unbind** (`unbindDevice`), and login/tenant-selection (Sub-Spec A). B ships
without emission; the unbind is the highest-value event for the audit trail.

## Testing (TDD, Vitest, existing `apps/pos` conventions)

`authStore`:
- `unbindDevice()` performs full teardown (token/user/companies cleared, stores reset)
  AND calls `removeStoredValue(LOGIN_TENANT_ID)`.
- `logout()` still does **NOT** remove `LOGIN_TENANT_ID` (regression guard — the A
  contract that automatic logouts keep the binding).

`Header`:
- No device-logout button is rendered for ANY role (manager or cashier).
- Switch and Lock buttons are still rendered.

`PinEntryPage`:
- No "Sign Out" / device-teardown control is rendered in either mode (`isLocked` true or
  false).

`SettingsPage`:
- The "Device & Security" / unbind control is rendered only when the operator is a
  manager; hidden for a cashier operator.
- Clicking unbind opens the confirm modal; confirming calls `unbindDevice` (and the
  store cleanup); cancelling calls nothing and closes the modal.

## Acceptance criteria

1. No user-facing surface except Settings (manager-only, confirmed) can fully tear down
   the device. Home header has no Logout button; the PIN/lock screen has no Sign Out.
2. A manager can unbind from Settings behind a confirmation; the next launch requires a
   full email-first login (and shows the tenant picker if the email is multi-tenant).
3. `unbindDevice()` clears `LOGIN_TENANT_ID`; `logout()` (401/bootstrap paths) does not.
4. Switch and Lock continue to work for all operators and keep the device session +
   binding.
5. No backend/`apps/web` changes. All new strings use `t()`. Cashier operators never see
   the unbind control.

## Open questions for review

- Is moving the `handleLogout` store-cleanup into the Settings handler the right home for
  it, or should it be a shared `teardownPosStores()` helper (DRY) callable by both the
  Settings unbind and any future caller?
- Should `unbindDevice()` live in `authStore` (current plan) given it must also trigger
  the cross-store cleanup, or should the cross-store cleanup stay at the component layer
  (as `handleLogout` did) to avoid `authStore`→feature-store coupling?
