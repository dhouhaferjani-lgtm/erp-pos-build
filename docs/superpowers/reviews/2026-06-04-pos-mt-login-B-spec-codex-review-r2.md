# Codex Review: Sub-Spec B POS Multi-Tenant Login Logout Separation

- **Date:** 2026-06-04
- **Reviewer:** Codex r2
- **Round:** 2
- **Spec reviewed:** `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md`
- **Round-1 review:** `docs/superpowers/reviews/2026-06-04-pos-mt-login-B-spec-codex-review.md`
- **Scope:** Revised spec plus current `apps/pos` worktree.

## Prior Findings Status

### MAJOR 1 - Settings "Change terminal" terminal reset

**Status: RESOLVED**

Actual code is still ungated today: Settings renders "Change terminal" under `{terminal && (...)}` and calls `useTerminalStore.getState().reset()` after `window.confirm` (`apps/pos/src/pages/SettingsPage.tsx:582`-`apps/pos/src/pages/SettingsPage.tsx:590`).
The revised spec now proposes `useOperatorStore((s) => s.operator)`, Header-equivalent `isManager`, and a manager-only Device & Security section (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:131`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:143`).
The gating is coherent with current rendering because Settings is only inside AppShell after `!operator || isLocked` has been rejected (`apps/pos/src/App.tsx:320`-`apps/pos/src/App.tsx:323`; `apps/pos/src/components/AppShell.tsx:139`-`apps/pos/src/components/AppShell.tsx:144`).

### MAJOR 2 - TerminalSetup logout and startup-401 caller

**Status: RESOLVED**

The revised spec now inventories `TerminalSetupPage` Logout and startup 401 in `authStore.initialize()` (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:54`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:58`).
It keeps these as deliberate `logout()` exceptions, not violations, and acceptance criterion 1 explicitly allows TerminalSetupPage, BootstrapErrorScreen, and automatic 401s (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:152`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:163`; `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:217`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:221`).
The actual kept callers use `logout()`: TerminalSetup selects `useAuthStore((s) => s.logout)` and wires `onClick={logout}` (`apps/pos/src/pages/TerminalSetupPage.tsx:371`-`apps/pos/src/pages/TerminalSetupPage.tsx:379`); startup 401 calls `get().logout()` (`apps/pos/src/stores/authStore.ts:160`-`apps/pos/src/stores/authStore.ts:167`).

### MAJOR 3 - Lock-screen dead-end recovery

**Status: PARTIAL**

The revised spec now explicitly accepts the no-manager lock-screen dead-end and documents clear-app-data/reinstall recovery (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:165`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:173`).
Local storage evidence supports clearing local keys: the Tauri store loads `izipos-settings.json`, includes `LOGIN_TENANT_ID`, `TERMINAL`, `PENDING_TERMINAL_ID`, and `SHIFT`, and exposes `clearStore()` (`apps/pos/src/lib/storage.ts:1`-`apps/pos/src/lib/storage.ts:9`; `apps/pos/src/lib/storage.ts:14`-`apps/pos/src/lib/storage.ts:26`; `apps/pos/src/lib/storage.ts:75`-`apps/pos/src/lib/storage.ts:78`).
But terminal identity can rehydrate from the backend by hardware device ID when no local terminal key exists (`apps/pos/src/stores/terminalStore.ts:490`-`apps/pos/src/stores/terminalStore.ts:504`), so the spec overstates clear-app-data as terminal-binding reset unless it also requires backend/admin unassignment.

### MINOR 1 - Stale "no confirmation" wording

**Status: RESOLVED**

The revised problem statement says Header Logout is manager-gated and confirmed (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:16`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:18`).
Actual code matches: Header opens `showLogoutConfirm` from the manager-only button and only the modal confirm calls `handleLogout()` (`apps/pos/src/components/Header.tsx:504`-`apps/pos/src/components/Header.tsx:513`; `apps/pos/src/components/Header.tsx:565`-`apps/pos/src/components/Header.tsx:590`).

### MINOR 2 - Settings operator-role gate source

**Status: RESOLVED**

The revised spec says Settings must read the current operator from `useOperatorStore`, not `authStore.user`, and use the Header role list (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:131`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:135`).
Current code still lacks that import and only reads `serverUrl` from `useAuthStore`, confirming the spec is calling out new implementation work (`apps/pos/src/pages/SettingsPage.tsx:1`-`apps/pos/src/pages/SettingsPage.tsx:10`; `apps/pos/src/pages/SettingsPage.tsx:51`-`apps/pos/src/pages/SettingsPage.tsx:56`).

## Open Questions From Round 1

**Shared helper adopted: YES.** The spec adds `teardownPosSessionStores()` in the UI/lib layer and has Settings call it before `authStore.unbindDevice()` (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:106`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:119`).

**`authStore.unbindDevice()` kept auth-only: YES.** The spec defines it as `logout()` plus `removeStoredValue(LOGIN_TENANT_ID)` and explicitly forbids feature-store imports into auth to avoid the operator/auth cycle (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:92`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:104`; `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:233`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:239`). Actual `operatorStore` imports `authStore` today (`apps/pos/src/stores/operatorStore.ts:1`-`apps/pos/src/stores/operatorStore.ts:14`).

## Fresh Adversarial Pass

### MAJOR - Clear-app-data recovery does not necessarily reset server-side terminal binding

**Problem:** The spec's lock-screen recovery says clear Tauri app data / reinstall is the out-of-band recovery (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:170`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:173`). That clears local auth/tenant/terminal keys, but the POS can immediately rebind the same terminal from the backend using the hardware device ID.

**Evidence:** `terminalStore.initialize()` falls through to `/pos/terminals/by-device/${deviceId}` when no local terminal or pending terminal is stored, then persists and publishes the returned active terminal (`apps/pos/src/stores/terminalStore.ts:490`-`apps/pos/src/stores/terminalStore.ts:504`). The local store does contain the keys that clear-app-data removes (`apps/pos/src/lib/storage.ts:14`-`apps/pos/src/lib/storage.ts:26`), but this does not remove the server-side device assignment.

**Fix:** Clarify the recovery policy: clear app data resets local auth and `LOGIN_TENANT_ID`; terminal identity recovery requires backend/admin unassignment of the device-terminal record, or the spec must explicitly accept automatic by-device rehydration after reset.

## Non-Findings Checked

- Moving "Change terminal" to manager-only does not block first-run setup: the router sends authenticated users with no terminal to `/setup` before any operator/PIN gate (`apps/pos/src/App.tsx:293`-`apps/pos/src/App.tsx:299`), and TerminalSetup supports claim/request flows (`apps/pos/src/pages/TerminalSetupPage.tsx:133`-`apps/pos/src/pages/TerminalSetupPage.tsx:139`). Post-terminal re-pairing is the privileged workflow by design (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:71`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:80`).
- `teardownPosSessionStores()` placement is acceptable if implemented as a standalone UI/lib helper: the spec keeps feature-store cleanup outside `authStore` (`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:101`-`docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md:104`), and the current direct cycle risk called out in code is `operatorStore` importing `authStore` (`apps/pos/src/stores/operatorStore.ts:1`-`apps/pos/src/stores/operatorStore.ts:14`).
- I found no additional cashier-reachable device/tenant teardown paths beyond the documented surfaces. Auto-inactivity only calls `lock()` (`apps/pos/src/components/AppShell.tsx:105`-`apps/pos/src/components/AppShell.tsx:110`), lock-after-sale only calls `operatorStore.lock()` (`apps/pos/src/pages/HomePage.tsx:1000`-`apps/pos/src/pages/HomePage.tsx:1003`), and close-shift clears sale/session stores but not auth or terminal identity (`apps/pos/src/components/pos/CloseShiftModal.tsx:37`-`apps/pos/src/components/pos/CloseShiftModal.tsx:46`; `apps/pos/src/stores/terminalStore.ts:650`-`apps/pos/src/stores/terminalStore.ts:658`).

## Verdict

**REQUEST-CHANGES** with **high confidence**.

The round-1 design issues are mostly resolved, but the revised recovery policy needs one more edit: clear-app-data is only a local reset, while the current terminal initializer can restore terminal identity from the backend by hardware device ID.
