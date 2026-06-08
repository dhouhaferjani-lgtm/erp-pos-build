# POS Logout Separation + Manager-Gated Device Unbind — Sub-Spec B of 3

- **Date:** 2026-06-04 (revised after Codex adversarial review)
- **Branch:** `feat/pos-multitenant-login` (worktree off `origin/dev`; Sub-Spec A already merged to `dev`)
- **Status:** Design approved by owner; revised per Codex review
  (`docs/superpowers/reviews/2026-06-04-pos-mt-login-B-spec-codex-review.md`).
- **Predecessor:** Sub-Spec A (email-first multi-tenant login) — DONE/merged. B closes A's
  deferred Codex MAJOR 5 (the tenant-binding reset path).

## Problem

The POS desktop conflates operator-level session control with full **device teardown**,
and exposes device/terminal-identity teardown on everyday operating surfaces with
inconsistent gating — a loss-prevention / manipulation hole:

- **Home header** has a manager-gated, **confirmed** Logout button → `authStore.logout()`
  (full device teardown). It is confirmed and role-gated, but it is still an
  everyday-operations surface; tearing down a terminal's identity should not be an
  everyday action sitting next to Switch/Lock.
- **Locked / PIN screen** has a **"Sign Out"** (confirmed, but **no manager gate**) →
  `authStore.logout()`. Any cashier at the lock screen can unbind the terminal.
- **Settings → "Change terminal"** calls `terminalStore.reset()` (clears `TERMINAL`,
  `PENDING_TERMINAL_ID`, `SHIFT`) with **no role gate** — any signed-in cashier can reset
  the terminal identity binding.

Industry norms (retail loss-prevention, PCI-DSS lock-vs-reauth, NF525
operator-vs-fiscal-day): cashiers lock / sign off as *operators*; tearing down a
terminal's identity (auth or terminal binding) is a privileged, deliberate, manager
action. Sub-Spec A also deferred its tenant-binding reset (`LOGIN_TENANT_ID`) to here.

## Current state (verified in code, corrected per Codex review)

- `Header.tsx`: `Switch` (`operatorStore.clearOperator()` — operator sign-off, everyone),
  `Lock` (`operatorStore.lock()` — screen lock, everyone), and a manager-gated
  (`isManager &&`, `Header.tsx:504-513`) `Logout` button that opens a **confirm modal**
  (`showLogoutConfirm`) whose confirm calls `handleLogout()` (`Header.tsx:565-590`).
  `handleLogout()` (`Header.tsx:382-390`) clears cart / refund flow / refund draft /
  voucher tenders / payment / product stores + `clearOperator()` + `authStore.logout()`.
  `isManager = operator?.roles?.some(r => ['manager','admin','owner'].includes(r))`
  (`Header.tsx:88-90`), based on the **current operator** (PIN), not the device user.
- `PinEntryPage.tsx`: a `showSignOut` confirm modal whose `handleSignOut()`
  (`PinEntryPage.tsx:27-36`) does `clearOperator()` + `authStore.logout()`, **ungated**.
- `SettingsPage.tsx`: sections for display/touch/security/printers/terminal-info/about;
  the **"Change terminal"** button (`SettingsPage.tsx:587-592`) calls
  `useTerminalStore.getState().reset()` (`terminalStore.ts:661-668` → deletes TERMINAL,
  PENDING_TERMINAL_ID, SHIFT), with **no role gate**. SettingsPage does **not** currently
  import `useOperatorStore` (reads only `serverUrl` from `authStore`) — manager gating is
  new work. `Modal` base component + `RefundConfirmModal` pattern exist.
- `authStore.logout()` (`authStore.ts:395-404`): resets state to `initialState`, removes
  TOKEN/USER/COMPANY_ID/COMPANIES/TERMINAL/PENDING_TERMINAL_ID, `disconnectEcho()`,
  `terminalStore.reset()`, `clearScanCache()`, `bootstrapStore.reset()`. It does **NOT**
  remove `LOGIN_TENANT_ID`. There is **no** `removeStoredValue(LOGIN_TENANT_ID)` anywhere
  in `apps/pos/src` today.
- **Other `logout()` callers (full inventory):** `authStore.initialize()` (startup 401,
  `authStore.ts:160-167`), `syncScheduler.ts` (confirmed 401, `:168-179`),
  `BootstrapErrorScreen.tsx` (degraded-state Sign Out, `:101-118`), and
  **`TerminalSetupPage.tsx`** (`<LogoutButton onClick={logout}>`, `:133-140`, `:371-382`)
  — a user-facing logout on the post-login / pre-terminal setup screen.
- **Routing:** `AppRouter` renders `PinEntryPage` whenever `!operator || isLocked`
  (`App.tsx:320-323`) — BEFORE `AppShell`/`/settings` (`AppShell.tsx:139-144`). When
  `isAuthenticated === false`, `AppRouter` routes to `/login` (`App.tsx:260-267`). So
  Settings is reachable only with an operator signed in and unlocked; an unbind that sets
  `isAuthenticated=false` routes back to login automatically.

## Decisions (locked with owner; terminal-gating added per review)

1. **Remove device teardown from everyday operating surfaces.** Remove the home-header
   Logout button (+ its confirm modal + `handleLogout`) and the PIN-screen "Sign Out"
   (+ its confirm modal + `handleSignOut`). Switch + Lock remain on the home header for
   everyone (cashier "session logout"; keep device session and `LOGIN_TENANT_ID`).
2. **Single privileged unbind in Settings.** New manager-only **"Device & Security"**
   Settings section with a **"Sign out & unbind device"** button behind a confirmation
   `Modal`.
3. **Unbind clears the tenant binding.** Settings unbind = full POS-store teardown +
   `authStore.unbindDevice()` (= `logout()` + clear `LOGIN_TENANT_ID`). Closes A's MAJOR
   5. `logout()` itself stays unchanged (401 / setup / bootstrap paths keep the binding).
4. **Manager-gate "Change terminal" (added per Codex MAJOR 1).** Move/gate the existing
   "Change terminal" action so it is visible/usable only to a manager operator (same
   `isManager` check), placed in the Device & Security section. Otherwise B closes auth
   unbind while leaving terminal-identity reset cashier-reachable.

## Scope

- **In scope (B):** `apps/pos` — `authStore` (new `unbindDevice`), a shared
  `teardownPosSessionStores()` helper, `Header`, `PinEntryPage`, `SettingsPage`, i18n.
- **Out of scope:** backend; `apps/web`; audit-event emission (**Sub-Spec C**);
  operator/PIN auth mechanics; the automatic 401 logout paths and the setup/bootstrap
  recovery escapes (deliberately kept — see below).

## Design

### `authStore.unbindDevice()` (new action — auth-owned only)

```ts
unbindDevice: () => {
  get().logout();                                  // full device teardown (unchanged)
  void removeStoredValue(StorageKeys.LOGIN_TENANT_ID);
},
```

It stays **auth-owned**: only `logout()` + clearing the tenant key. It does **NOT** import
cart/payment/refund/product/operator stores — `operatorStore` already imports `authStore`
(`operatorStore.ts:1-13`), so a reverse import would create a dependency cycle. The
cross-store cleanup is done by the shared helper below, called from the UI layer.

### `teardownPosSessionStores()` (new shared helper, UI/lib layer)

Extract the exact cleanup currently inline in `Header.handleLogout` into one helper so the
list is testable and cannot drift between teardown surfaces:

```ts
// clears: cart, refund flow, refund draft, voucher tenders, payment(reset),
// product(reset), operator(clearOperator) — matching Header.handleLogout:382-390.
export function teardownPosSessionStores(): void { /* ... */ }
```

The Settings unbind handler calls `teardownPosSessionStores()` then
`authStore.unbindDevice()`. (Note: this matches Header **logout** cleanup; it does NOT
include `discardPendingSubmission()`, which only `handleSwitchOperator` does.)

### `Header.tsx`

Remove the `isManager`-gated Logout button, its `showLogoutConfirm` modal, and
`handleLogout()`. Keep Switch + Lock + Settings unchanged.

### `PinEntryPage.tsx`

Remove `showSignOut` state, the "Sign Out" trigger, the sign-out confirm modal, and
`handleSignOut()`. The lock/PIN screen can only move forward (enter PIN to unlock).

### `SettingsPage.tsx`

- Add `const operator = useOperatorStore((s) => s.operator)` and compute `isManager` with
  the same role list as Header. (Gate is on the **current operator**, not `authStore.user`
  — the cashier physically at the till, per Codex.)
- New **"Device & Security"** section rendered only when `isManager`, containing:
  - **"Change terminal"** (moved here / gated) → existing `terminalStore.reset()` flow.
  - **"Sign out & unbind device"** → confirmation `Modal` → on confirm:
    `teardownPosSessionStores()` then `authStore.unbindDevice()`. The router then routes to
    `/login` (because `isAuthenticated` is false).
- The route guard already prevents Settings from rendering with `operator === null` or
  `isLocked` (it shows `PinEntryPage`), so the manager gate never hides the section from a
  legitimately-signed-in manager. A regression test covers direct `/settings` nav.

### i18n

New `pos` keys (en + fr): `settings.deviceSecurity`, `settings.deviceUnbind`,
`settings.deviceUnbindConfirmTitle`, `settings.deviceUnbindConfirmMessage`,
`settings.deviceUnbindConfirm`. Reuse existing `settings.cancel` / `settings.changeTerminal`
if present.

## Deliberate exceptions (kept, documented)

These call `logout()` (never `unbindDevice()`), so the tenant binding **survives** — they
are not everyday-operations teardown surfaces:

- **Automatic 401 logouts:** `authStore.initialize()` (startup) and `syncScheduler.ts`
  (confirmed 401). Not user-initiated.
- **`TerminalSetupPage` Logout:** the post-login / pre-terminal setup screen. No operator
  is signed in and no sales occur there; logging out backs out to login (e.g. wrong device
  account). A setup/recovery escape, not an operations surface.
- **`BootstrapErrorScreen` Sign Out:** degraded-state recovery; the app is unusable and
  Settings is unreachable.

## Lock-screen recovery policy (Codex MAJOR 3 — accepted tradeoff)

Removing the PIN-screen Sign Out means a terminal **locked** with **no available manager**
cannot be unbound *in-app* (Settings is unreachable while `isLocked`). This is the
**intended** anti-manipulation posture: a locked terminal cannot be casually torn down.
Operationally: a manager unlocks with their PIN and uses Settings.

With no manager PIN available at all, the documented **out-of-band recovery** is to
**clear the Tauri app data / reinstall**. Precisely (per Codex r2): clearing app data
removes the **local auth/tenant session** (token, user, companies, terminal cache, and
`LOGIN_TENANT_ID`), which is exactly what is needed to escape the locked-screen *auth*
dead-end — the next launch forces a fresh email-first login. It does **not** unpair the
**server-side `device_id → terminal` mapping**: on re-login, `terminalStore.initialize()`
re-fetches `/pos/terminals/by-device/${deviceId}` and the device re-pairs to its assigned
register (normal, usually desired). Reassigning the device to a *different* terminal is the
manager-gated **"Change terminal"** action (or a server-side admin reassignment), NOT part
of clear-app-data. So clear-app-data is a valid recovery for the locked-*auth* dead-end;
the terminal pairing is a separate binding handled by the gated Change-terminal flow. This
is an explicit, accepted operational criterion, not an oversight.

## Events Sub-Spec C must capture (enumerated so nothing is missed)

C's audit pipeline must emit auditable events for: operator sign-in (PIN verify), operator
sign-off (`clearOperator`), screen lock (`operatorStore.lock`), **device unbind**
(`unbindDevice`), **terminal change** (`terminalStore.reset` via the gated action), and
login / tenant-selection (Sub-Spec A). B ships without emission; unbind + terminal-change
are the highest-value events.

## Testing (TDD, Vitest, existing `apps/pos` conventions)

`authStore`:
- `unbindDevice()` performs full teardown (token/user/companies cleared) AND calls
  `removeStoredValue(LOGIN_TENANT_ID)`.
- `logout()` still does **NOT** remove `LOGIN_TENANT_ID` (regression guard — startup 401,
  sync 401, BootstrapErrorScreen, and TerminalSetupPage all keep the binding).

`teardownPosSessionStores()`:
- calls each expected store-clear exactly once (cart, refund flow, refund draft, voucher
  tenders, payment reset, product reset, operator clear).

`Header`:
- No device-logout button rendered for ANY role (manager/admin/owner/cashier/no-operator);
  Switch + Lock still rendered.

`PinEntryPage`:
- No "Sign Out" / teardown control rendered in either mode (`isLocked` true or false).

`SettingsPage`:
- Device & Security section (incl. unbind + Change terminal) rendered only for a manager
  operator; hidden for a cashier operator.
- Confirm unbind runs `teardownPosSessionStores()` then `unbindDevice()`; cancel does
  nothing.
- "Change terminal" not reachable for a cashier operator.

Routing:
- direct `/settings` while `operator === null` or `isLocked` renders `PinEntryPage`.
- after confirmed unbind (`isAuthenticated=false`) the app routes to `/login`.

i18n: new en + fr keys exist; no raw keys render.

## Acceptance criteria

1. No **everyday-operations** surface can tear down the device or terminal identity:
   home header has no Logout button; the PIN/lock screen has no Sign Out; "Change terminal"
   is manager-gated. The only kept `logout()` escapes are non-operations setup/recovery
   surfaces (TerminalSetupPage, BootstrapErrorScreen) + automatic 401s, which preserve the
   tenant binding.
2. A manager can unbind from Settings behind a confirmation; the next launch requires a
   full email-first login (tenant picker if multi-tenant).
3. `unbindDevice()` clears `LOGIN_TENANT_ID`; `logout()` (401 / setup / bootstrap paths)
   does not.
4. Switch and Lock continue to work for all operators and keep the device session + binding.
5. "Change terminal" is reachable only by a manager operator.
6. A locked terminal with no manager has a documented out-of-band recovery (clear app data);
   no in-app cashier-reachable teardown exists.
7. No backend / `apps/web` changes. All new strings use `t()`. Cashier operators never see
   the Device & Security section.

## Resolved review questions

- *Cross-store cleanup placement:* shared `teardownPosSessionStores()` helper (not
  component duplication, not inside `authStore`).
- *`unbindDevice()` placement:* in `authStore`, auth-owned only (`logout()` + clear
  `LOGIN_TENANT_ID`); feature-store cleanup stays outside auth to avoid the
  `operatorStore`→`authStore` cycle.
