# POS Logout Separation + Manager-Gated Device Unbind (Sub-Spec B) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Remove device teardown from everyday POS surfaces (home header, PIN/lock screen), and provide a single manager-gated, confirmed "Sign out & unbind device" in Settings that clears the tenant binding (`LOGIN_TENANT_ID`); also manager-gate "Change terminal".

**Architecture:** Frontend-only (`apps/pos`). New `authStore.unbindDevice()` (= `logout()` + clear `LOGIN_TENANT_ID`, auth-owned, no feature-store imports). New shared `teardownPosSessionStores()` helper holding the cross-store cleanup currently inline in `Header.handleLogout`. `Header` and `PinEntryPage` lose their device-teardown controls. `SettingsPage` gains a manager-only "Device & Security" section (unbind + Change terminal), gated on the current **operator** role.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Vitest + Testing Library. Spec: `docs/superpowers/specs/2026-06-04-pos-mt-login-B-logout-separation-design.md` (+ Codex reviews `-spec-codex-review.md`, `-r2.md`).

**Conventions:** tests mock `@/lib/api`, `@/lib/storage`, `@/lib/echo`, `@tauri-apps/plugin-os`, `@/lib/device`, and the feature stores as needed (see `apps/pos/src/stores/__tests__/authStore.test.ts`, `apps/pos/src/pages/__tests__/LoginPage.test.tsx`). Strings via `t()` (`pos` ns). Run `cd apps/pos && pnpm vitest run <path>`; never `pnpm test --run`.

---

## File Structure

| File | Change |
|---|---|
| `apps/pos/src/lib/session/teardownPosSession.ts` | **Create** — `teardownPosSessionStores()` helper |
| `apps/pos/src/stores/authStore.ts` | Add `unbindDevice()` action (interface + impl) |
| `apps/pos/src/components/Header.tsx` | Remove Logout button, confirm modal, `handleLogout`, `showLogoutConfirm`, now-unused imports |
| `apps/pos/src/pages/PinEntryPage.tsx` | Remove Sign Out button, confirm modal, `handleSignOut`, `showSignOut`, now-unused imports |
| `apps/pos/src/pages/SettingsPage.tsx` | Manager-only Device & Security section (unbind + Change terminal); remove Change terminal from Terminal Info |
| `apps/pos/src/locales/en/pos.json`, `fr/pos.json` | New `settings.*` keys |
| `*.test.ts(x)` | Tests per task |

**Task order:** 1 (helper + unbindDevice) → 2 (remove Header logout) → 3 (remove PIN sign-out) → 4 (Settings gated section) → 5 (i18n) → 6 (gate).

---

## Task 1: `teardownPosSessionStores()` helper + `authStore.unbindDevice()`

**Files:**
- Create: `apps/pos/src/lib/session/teardownPosSession.ts`
- Modify: `apps/pos/src/stores/authStore.ts` (AuthActions interface ~`login`/`logout` area; impl after `logout`)
- Test: `apps/pos/src/lib/session/__tests__/teardownPosSession.test.ts`, `apps/pos/src/stores/__tests__/authStore.test.ts`

- [ ] **Step 1: Write failing test for the helper**

`apps/pos/src/lib/session/__tests__/teardownPosSession.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';

const clearCart = vi.fn(); const clearAll = vi.fn(); const clearDraftState = vi.fn();
const clearVoucherTenders = vi.fn(); const paymentReset = vi.fn(); const productReset = vi.fn();
const clearOperator = vi.fn();

vi.mock('@/stores/cartStore', () => ({ useCartStore: { getState: () => ({ clearCart }) } }));
vi.mock('@/stores/refundFlowStore', () => ({ useRefundFlowStore: { getState: () => ({ clearAll }) } }));
vi.mock('@/stores/refundDraftStore', () => ({ useRefundDraftStore: { getState: () => ({ clearDraftState }) } }));
vi.mock('@/stores/paymentStore', () => ({ usePaymentStore: { getState: () => ({ clearVoucherTenders, reset: paymentReset }) } }));
vi.mock('@/stores/productStore', () => ({ useProductStore: { getState: () => ({ reset: productReset }) } }));
vi.mock('@/stores/operatorStore', () => ({ useOperatorStore: { getState: () => ({ clearOperator }) } }));

import { teardownPosSessionStores } from '../teardownPosSession';

describe('teardownPosSessionStores', () => {
  beforeEach(() => vi.clearAllMocks());
  it('clears each session store exactly once', () => {
    teardownPosSessionStores();
    for (const fn of [clearCart, clearAll, clearDraftState, clearVoucherTenders, paymentReset, productReset, clearOperator]) {
      expect(fn).toHaveBeenCalledTimes(1);
    }
  });
});
```

- [ ] **Step 2: Run → fails** (`cd apps/pos && pnpm vitest run src/lib/session/__tests__/teardownPosSession.test.ts` → module not found).

- [ ] **Step 3: Implement the helper**

`apps/pos/src/lib/session/teardownPosSession.ts`:

```ts
import { useCartStore } from '@/stores/cartStore';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useProductStore } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';

/**
 * Clear all POS session stores that a full device sign-out must reset.
 * Mirrors the cleanup formerly inline in Header.handleLogout (Sub-Spec B).
 * Kept out of authStore to avoid an operatorStore<->authStore import cycle.
 */
export function teardownPosSessionStores(): void {
  useCartStore.getState().clearCart();
  useRefundFlowStore.getState().clearAll();
  useRefundDraftStore.getState().clearDraftState();
  usePaymentStore.getState().clearVoucherTenders();
  usePaymentStore.getState().reset();
  useProductStore.getState().reset();
  useOperatorStore.getState().clearOperator();
}
```

- [ ] **Step 4: Run → passes.**

- [ ] **Step 5: Write failing tests for `unbindDevice`** in `authStore.test.ts`:

```ts
describe('unbindDevice', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ user: mockUser, token: 'tok', companies: mockCompanies, isAuthenticated: true });
  });
  it('clears LOGIN_TENANT_ID and tears down the session', () => {
    useAuthStore.getState().unbindDevice();
    expect(removeStoredValue).toHaveBeenCalledWith('login_tenant_id');
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().token).toBeNull();
  });
  it('logout() does NOT clear LOGIN_TENANT_ID (binding survives 401/setup paths)', () => {
    useAuthStore.getState().logout();
    expect(removeStoredValue).not.toHaveBeenCalledWith('login_tenant_id');
  });
});
```

Ensure the `@/lib/storage` mock's `StorageKeys` includes `LOGIN_TENANT_ID: 'login_tenant_id'` (added in Sub-Spec A; confirm present).

- [ ] **Step 6: Run → fails** (`unbindDevice` not a function).

- [ ] **Step 7: Implement `unbindDevice`**

In `authStore.ts`, add to the `AuthActions` interface (near `logout`):

```ts
  unbindDevice: () => void;
```

And implement it immediately after `logout`:

```ts
  // Sub-Spec B: deliberate, manager-initiated full device sign-out. Unlike
  // logout() (used by automatic 401s / setup / bootstrap, which keep the
  // device's tenant binding), this ALSO clears LOGIN_TENANT_ID so the next
  // login re-resolves the tenant email-first. Auth-owned only — feature-store
  // cleanup is done by teardownPosSessionStores() at the UI layer to avoid an
  // operatorStore<->authStore import cycle.
  unbindDevice: () => {
    get().logout();
    void removeStoredValue(StorageKeys.LOGIN_TENANT_ID);
  },
```

- [ ] **Step 8: Run → passes** (`cd apps/pos && pnpm vitest run src/stores/__tests__/authStore.test.ts src/lib/session/__tests__/teardownPosSession.test.ts`). Then `pnpm tsc --noEmit`.

- [ ] **Step 9: Commit**

```bash
git add apps/pos/src/lib/session/ apps/pos/src/stores/authStore.ts apps/pos/src/stores/__tests__/authStore.test.ts
git commit -m "feat(pos-logout): unbindDevice + teardownPosSessionStores helper"
```

---

## Task 2: Remove the home-header Logout button

**Files:** Modify `apps/pos/src/components/Header.tsx`; Test `apps/pos/src/components/__tests__/Header.test.tsx` (create if absent).

- [ ] **Step 1: Write/extend a failing test** asserting no logout control renders. If `Header.test.tsx` doesn't exist, create a minimal one mocking the stores/hooks Header needs (follow `LoginPage.test.tsx` mocking style; mock `useOperatorStore` to return a manager operator). Assert:

```ts
it('renders no device-logout button (manager operator)', () => {
  // operator with roles ['manager'] signed in
  render(<Header />);
  expect(screen.queryByTitle('header.logout')).toBeNull();
});
it('still renders Switch and Lock', () => {
  render(<Header />);
  expect(screen.getByTitle('header.switch')).toBeInTheDocument();
  expect(screen.getByTitle('header.lock')).toBeInTheDocument();
});
```

(If a full Header render is impractical due to heavy dependencies, instead assert via a focused test that the component module no longer references `handleLogout`/`showLogoutConfirm` — but prefer a render test. Report BLOCKED if Header cannot be rendered in jsdom and switch to the lighter assertion.)

- [ ] **Step 2: Run → fails** (logout button present).

- [ ] **Step 3: Remove the Logout button + modal + handler + state**

In `Header.tsx`:
- Delete the `{/* Logout — manager/admin only */}` block (`isManager &&` button with `title={t('header.logout')}`, ~lines 505-513).
- Delete the `{showLogoutConfirm && ( ... )}` confirmation modal block (~lines 566-592).
- Delete `const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);` (~line 81).
- Delete the `handleLogout()` function (~lines 382-390).
- Remove now-unused imports: `LogOut` from lucide (line 4) **only if** not used elsewhere in the file (grep first). Remove store imports `useCartStore`/`usePaymentStore`/`useProductStore`/`useRefundFlowStore`/`useRefundDraftStore` and the `logout` selector **only if** they are not referenced by any remaining code (e.g. `handleSwitchOperator` still uses cart/refund/payment stores — so those imports stay). Run `pnpm tsc --noEmit` to catch unused/missing.

Keep `isManager` if still used elsewhere; if it becomes unused, remove it too (tsc/eslint will flag).

- [ ] **Step 4: Run tests + tsc → pass.**

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/Header.tsx apps/pos/src/components/__tests__/Header.test.tsx
git commit -m "feat(pos-logout): remove device-logout button from home header"
```

---

## Task 3: Remove the PIN/lock-screen Sign Out

**Files:** Modify `apps/pos/src/pages/PinEntryPage.tsx`; Test `apps/pos/src/pages/__tests__/PinEntryPage.test.tsx` (create if absent).

- [ ] **Step 1: Write failing test** — render `PinEntryPage` (mock `useOperatorStore.verifyPin`, `useTranslation`) in both modes and assert no sign-out control:

```ts
it('renders no sign-out control (locked)', () => {
  render(<PinEntryPage isLocked />);
  expect(screen.queryByText('settings.signOutFromPin')).toBeNull();
});
it('renders no sign-out control (PIN entry)', () => {
  render(<PinEntryPage />);
  expect(screen.queryByText('settings.signOutFromPin')).toBeNull();
});
```

- [ ] **Step 2: Run → fails.**

- [ ] **Step 3: Remove sign-out** from `PinEntryPage.tsx`:
- Delete `const [showSignOut, setShowSignOut] = useState(false);` (line 25).
- Delete `handleSignOut()` (lines 27-37).
- Delete the `<button onClick={() => setShowSignOut(true)} ...>{t('settings.signOutFromPin')}</button>` (lines 91-96).
- Delete the `{showSignOut && ( ... )}` confirm modal (lines 99-125).
- Remove now-unused imports: `useCartStore`, `usePaymentStore`, `useProductStore`, `useRefundFlowStore`, `useRefundDraftStore`, `useAuthStore` (all were used only by `handleSignOut`). Keep `useOperatorStore` (used by `verifyPin`). `useState` is still used (`pin`, `error`, `verifying`). Run `pnpm tsc --noEmit` to confirm no unused/missing.

- [ ] **Step 4: Run tests + tsc → pass.**

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/PinEntryPage.tsx apps/pos/src/pages/__tests__/PinEntryPage.test.tsx
git commit -m "feat(pos-logout): remove device sign-out from PIN/lock screen"
```

---

## Task 4: Settings manager-only Device & Security section (unbind + Change terminal)

**Files:** Modify `apps/pos/src/pages/SettingsPage.tsx`; Test `apps/pos/src/pages/__tests__/SettingsPage.test.tsx` (create if absent).

- [ ] **Step 1: Write failing tests** (mock `useOperatorStore` to vary the operator's roles; mock terminal/auth/settings stores and `react-i18next` as in other page tests):

```ts
it('shows Device & Security (incl. unbind) only for a manager operator', () => {
  setOperatorRoles(['manager']);
  render(<SettingsPage />);
  expect(screen.getByTestId('device-security-section')).toBeInTheDocument();
  expect(screen.getByTestId('device-unbind-button')).toBeInTheDocument();
});
it('hides Device & Security for a cashier operator', () => {
  setOperatorRoles(['cashier']);
  render(<SettingsPage />);
  expect(screen.queryByTestId('device-security-section')).toBeNull();
  expect(screen.queryByTestId('device-unbind-button')).toBeNull();
});
it('confirming unbind runs teardown then unbindDevice', async () => {
  setOperatorRoles(['manager']);
  render(<SettingsPage />);
  fireEvent.click(screen.getByTestId('device-unbind-button'));
  fireEvent.click(screen.getByTestId('device-unbind-confirm'));
  expect(teardownSpy).toHaveBeenCalledTimes(1);
  expect(unbindSpy).toHaveBeenCalledTimes(1);
});
```

(Spy on `teardownPosSessionStores` via `vi.mock('@/lib/session/teardownPosSession', ...)` and on `authStore.unbindDevice`.)

- [ ] **Step 2: Run → fails.**

- [ ] **Step 3: Implement**

In `SettingsPage.tsx`:
- Add imports: `import { useOperatorStore } from '@/stores/operatorStore';`, `import { Modal } from '@/components/pos/Modal';`, `import { unbindDevice ... }` via `useAuthStore`, `import { teardownPosSessionStores } from '@/lib/session/teardownPosSession';`.
- In the component: `const operator = useOperatorStore((s) => s.operator); const isManager = operator?.roles?.some((r) => ['manager','admin','owner'].includes(r)) ?? false; const unbindDevice = useAuthStore((s) => s.unbindDevice);` and `const [showUnbindConfirm, setShowUnbindConfirm] = useState(false);`.
- **Remove** the Change-terminal block from the Terminal Info section (the `{terminal && (<div ...change terminal...>)}` at ~lines 581-600).
- Add a new section, rendered only when `isManager`, BEFORE the About section:

```tsx
{isManager && (
  <section data-testid="device-security-section" className="rounded-xl bg-white p-4 shadow-sm">
    <h2 className="mb-4 text-base font-bold text-gray-900">{t('settings.deviceSecurity')}</h2>
    {terminal && (
      <div className="mb-3">
        <p className="mb-2 text-xs text-gray-500">{t('terminal.changeTerminalDesc')}</p>
        <button
          onClick={() => { if (window.confirm(t('terminal.changeTerminalConfirm'))) { useTerminalStore.getState().reset(); navigate('/'); } }}
          className="flex w-full items-center justify-center gap-2 rounded-lg border border-orange-300 bg-orange-50 px-4 py-3 text-sm font-medium text-orange-700 hover:bg-orange-100"
        >
          <RefreshCw className="h-4 w-4" />{t('terminal.changeTerminal')}
        </button>
      </div>
    )}
    <button
      data-testid="device-unbind-button"
      onClick={() => setShowUnbindConfirm(true)}
      className="flex w-full items-center justify-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 hover:bg-red-100"
    >
      <LogOut className="h-4 w-4" />{t('settings.deviceUnbind')}
    </button>
  </section>
)}
```

Add `LogOut` to the lucide import line. Add a confirmation modal using the `Modal` component (rendered near the section / end of the page):

```tsx
<Modal
  isOpen={showUnbindConfirm}
  onClose={() => setShowUnbindConfirm(false)}
  title={t('settings.deviceUnbindConfirmTitle')}
  size="sm"
  footer={
    <div className="flex gap-3">
      <button onClick={() => setShowUnbindConfirm(false)} className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{t('settings.cancel')}</button>
      <button data-testid="device-unbind-confirm" onClick={() => { setShowUnbindConfirm(false); teardownPosSessionStores(); unbindDevice(); }} className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700">{t('settings.deviceUnbindConfirm')}</button>
    </div>
  }
>
  <p className="text-sm text-gray-600">{t('settings.deviceUnbindConfirmMessage')}</p>
</Modal>
```

(Confirm the `Modal` component's prop names — `isOpen`, `onClose`, `title`, `footer`, `size`, `children` — match `apps/pos/src/components/pos/Modal.tsx`; adjust if different.)

After `unbindDevice()`, the router routes to `/login` automatically (`isAuthenticated` is false). No manual navigate needed, but a `navigate('/login')` is harmless if the router needs it — verify behavior.

- [ ] **Step 4: Run tests + tsc → pass.**

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/SettingsPage.tsx apps/pos/src/pages/__tests__/SettingsPage.test.tsx
git commit -m "feat(pos-logout): manager-gated Device & Security (unbind + change terminal) in Settings"
```

---

## Task 5: i18n keys

**Files:** Modify `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` (the `settings` object).

- [ ] **Step 1: Add EN keys** under `settings`:

```json
    "deviceSecurity": "Device & Security",
    "deviceUnbind": "Sign out & unbind device",
    "deviceUnbindConfirmTitle": "Unbind this device?",
    "deviceUnbindConfirmMessage": "This signs the terminal out completely. A full login will be required next time, and the saved business will be cleared.",
    "deviceUnbindConfirm": "Unbind device",
```

- [ ] **Step 2: Add FR keys** under `settings`:

```json
    "deviceSecurity": "Appareil et sécurité",
    "deviceUnbind": "Déconnecter et dissocier l'appareil",
    "deviceUnbindConfirmTitle": "Dissocier cet appareil ?",
    "deviceUnbindConfirmMessage": "Cela déconnecte entièrement le terminal. Une connexion complète sera requise la prochaine fois, et l'établissement enregistré sera effacé.",
    "deviceUnbindConfirm": "Dissocier l'appareil",
```

- [ ] **Step 3: Verify JSON parses** (`node -e "require('./apps/pos/src/locales/en/pos.json');require('./apps/pos/src/locales/fr/pos.json');console.log('ok')"`).

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(pos-logout): i18n keys for Device & Security / unbind"
```

---

## Task 6: Gate (typecheck + lint + tests + regression)

- [ ] **Step 1:** `cd apps/pos && pnpm tsc --noEmit` → clean.
- [ ] **Step 2:** `pnpm eslint` the changed files → no new errors (no unused imports left behind by the removals).
- [ ] **Step 3:** `pnpm vitest run` the new/changed test files → pass.
- [ ] **Step 4:** `pnpm vitest run` (full POS suite) → no NEW failures vs the pre-B baseline (the 2 pre-existing `migrations.v37.test.ts` failures are unrelated; do not fix).
- [ ] **Step 5:** Commit any fixups: `git commit -m "chore(pos-logout): typecheck/lint fixups"`.

---

## Self-review notes (author)

- **Spec coverage:** unbindDevice clears binding + logout doesn't (T1); home logout removed (T2); PIN sign-out removed (T3); manager-gated Settings unbind + Change-terminal gated (T4); i18n (T5). Acceptance criteria 1-7 mapped. Lock-screen recovery is documented policy (no code).
- **Deferred:** audit emission (Sub-Spec C). The kept escapes (TerminalSetupPage, BootstrapErrorScreen, 401s) are intentionally untouched — they call `logout()`, not `unbindDevice()`, so the binding survives (regression covered by T1's "logout does NOT clear LOGIN_TENANT_ID").
- **Import-cycle guard:** `unbindDevice` stays auth-only; cross-store cleanup is in `teardownPosSession.ts` (UI/lib layer), imported by SettingsPage, NOT by authStore.
- **Risk:** Header/PinEntryPage import removals — rely on `tsc`/eslint to catch any selector still in use. `handleSwitchOperator` still uses cart/refund/payment stores in Header, so those imports remain.
