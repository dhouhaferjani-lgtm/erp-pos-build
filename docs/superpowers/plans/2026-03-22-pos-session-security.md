# POS Session Security & Configurable Lock Behavior Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Protect the POS terminal from accidental logout (manager-only with confirmation), add configurable inactivity timeout and lock-after-sale, and provide a sign-out option on the PIN screen.

**Architecture:** All changes are frontend-only (React). New settings (`inactivityTimeout`, `lockAfterSale`) go in `settingsStore` (which already has Zustand `persist`). The logout button in Header becomes role-gated. The PIN entry page gets a sign-out link. No backend changes.

**Tech Stack:** React 19, TypeScript, Zustand 5, react-i18next, Tailwind CSS 4

**Spec:** `docs/superpowers/specs/2026-03-22-pos-session-security.md`

---

### Task 1: Add security settings to `settingsStore`

**Files:**
- Modify: `apps/pos/src/stores/settingsStore.ts`
- Modify: `apps/pos/src/stores/operatorStore.ts`

Add `inactivityTimeout` and `lockAfterSale` to `settingsStore` (persisted). Remove `lockTimeoutMs` and `LOCK_TIMEOUT_MS` from `operatorStore`.

- [ ] **Step 1: Add new fields to `settingsStore`**

In `apps/pos/src/stores/settingsStore.ts`, add to the `SettingsState` interface:

```typescript
/** Inactivity timeout in seconds before auto-lock. 0 = never. */
inactivityTimeout: number;
/** Lock the screen after completing a sale. */
lockAfterSale: boolean;
setInactivityTimeout: (seconds: number) => void;
setLockAfterSale: (enabled: boolean) => void;
```

Add defaults in the `persist` create block:

```typescript
inactivityTimeout: 300,
lockAfterSale: false,

setInactivityTimeout: (seconds: number) => {
  set({ inactivityTimeout: seconds });
},

setLockAfterSale: (enabled: boolean) => {
  set({ lockAfterSale: enabled });
},
```

- [ ] **Step 2: Remove `lockTimeoutMs` from `operatorStore`**

In `apps/pos/src/stores/operatorStore.ts`:
- Delete `const LOCK_TIMEOUT_MS = 5 * 60 * 1000;` (line 33)
- Remove `lockTimeoutMs: number` from `OperatorState` interface (line 18)
- Remove `lockTimeoutMs: LOCK_TIMEOUT_MS` from `initialState` (line 39)

- [ ] **Step 2b: Update `operatorStore` test**

In `apps/pos/src/stores/__tests__/operatorStore.test.ts`, remove `lockTimeoutMs: 5 * 60 * 1000,` from the `beforeEach` setState call (around line 28). This field no longer exists on the state type.

- [ ] **Step 3: Update `AppShell.tsx` to read from `settingsStore`**

In `apps/pos/src/components/AppShell.tsx`:
- Add import: `import { useSettingsStore } from '@/stores/settingsStore';`
- Remove `const lockTimeoutMs = useOperatorStore((s) => s.lockTimeoutMs);` (line 18) — do NOT replace it with a reactive selector since we read the value imperatively inside the interval callback.

- Update the interval check (line 44-48):

```typescript
const interval = setInterval(() => {
  const timeout = useSettingsStore.getState().inactivityTimeout;
  if (timeout === 0) return; // "Never" — skip check
  const { lastActivity } = useOperatorStore.getState();
  if (Date.now() - lastActivity > timeout * 1000) {
    lock();
  }
}, 10_000);
```

- Update the `useEffect` dependency array: remove `lockTimeoutMs` — the new array is `[operator, handleActivity, lock]`.

- [ ] **Step 4: Verify it compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/stores/settingsStore.ts apps/pos/src/stores/operatorStore.ts apps/pos/src/stores/__tests__/operatorStore.test.ts apps/pos/src/components/AppShell.tsx
git commit -m "feat(pos): add configurable inactivity timeout and lock-after-sale settings"
```

---

### Task 2: Manager-only logout with confirmation dialog

**Files:**
- Modify: `apps/pos/src/components/Header.tsx`

Gate the logout button to manager/admin/owner roles and add a confirmation modal before executing logout.

- [ ] **Step 1: Add role check helper and state for confirmation dialog**

In `apps/pos/src/components/Header.tsx`, add after the existing state declarations (around line 50):

```typescript
const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);

const isManager = operator?.roles?.some((r) =>
  ['manager', 'admin', 'owner'].includes(r),
) ?? false;
```

- [ ] **Step 2: Conditionally render the logout button**

Replace the logout button block (lines 202-209):

```tsx
{/* Logout — manager/admin only */}
{isManager && (
  <button
    onClick={() => setShowLogoutConfirm(true)}
    className="flex h-11 w-11 items-center justify-center rounded-lg bg-primary-800 text-primary-200 hover:bg-primary-700"
    title={t('header.logout')}
  >
    <LogOut className="h-5 w-5" />
  </button>
)}
```

- [ ] **Step 3: Add confirmation dialog**

After the CashDrawerModal section (before the closing `</>` on line 260), add:

```tsx
{/* Logout Confirmation */}
{showLogoutConfirm && (
  <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div className="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
      <h3 className="text-lg font-bold text-gray-900">
        {t('settings.signOutTerminal')}
      </h3>
      <p className="mt-2 text-sm text-gray-600">
        {t('settings.signOutConfirmMessage')}
      </p>
      <div className="mt-6 flex gap-3">
        <button
          onClick={() => setShowLogoutConfirm(false)}
          className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          {t('settings.cancel')}
        </button>
        <button
          onClick={() => {
            setShowLogoutConfirm(false);
            handleLogout();
          }}
          className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700"
        >
          {t('settings.signOut')}
        </button>
      </div>
    </div>
  </div>
)}
```

- [ ] **Step 4: Verify it compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/Header.tsx
git commit -m "feat(pos): manager-only logout button with confirmation dialog"
```

---

### Task 3: Lock after each sale

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx`

When `lockAfterSale` is enabled, lock the operator session after the checkout success modal is dismissed.

- [ ] **Step 1: Update `handleNewSale` in `HomePage.tsx`**

Find the `handleNewSale` callback (around line 373):

```typescript
const handleNewSale = useCallback(() => {
  setShowSuccessModal(false);
  clearCart();
  clearLastReceipt();
  setSelectedTableId(null);
}, [clearCart, clearLastReceipt]);
```

Replace with:

```typescript
const handleNewSale = useCallback(() => {
  setShowSuccessModal(false);
  clearCart();
  clearLastReceipt();
  setSelectedTableId(null);

  // Lock screen after sale if enabled
  if (useSettingsStore.getState().lockAfterSale) {
    useOperatorStore.getState().lock();
  }
}, [clearCart, clearLastReceipt]);
```

Add the imports at the top of the file if not already present:

```typescript
import { useSettingsStore } from '@/stores/settingsStore';
import { useOperatorStore } from '@/stores/operatorStore';
```

Note: Using `.getState()` inside the callback (not a hook selector) is intentional — it reads the current value at call time without adding a reactive dependency.

- [ ] **Step 2: Verify it compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src/pages/HomePage.tsx
git commit -m "feat(pos): lock screen after each sale when enabled"
```

---

### Task 4: Sign out from PIN screen

**Files:**
- Modify: `apps/pos/src/pages/PinEntryPage.tsx`

Add a "Sign out" link at the bottom of the PIN entry page with a confirmation dialog.

- [ ] **Step 1: Add sign-out state and handler**

In `apps/pos/src/pages/PinEntryPage.tsx`, add imports and state:

```typescript
import { useAuthStore } from '@/stores/authStore';
import { useCartStore } from '@/stores/cartStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useProductStore } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';
```

Add state inside the component (after existing state declarations around line 18):

```typescript
const [showSignOut, setShowSignOut] = useState(false);

function handleSignOut() {
  useCartStore.getState().clearCart();
  usePaymentStore.getState().reset();
  useProductStore.getState().reset();
  useOperatorStore.getState().clearOperator();
  useAuthStore.getState().logout();
}
```

- [ ] **Step 2: Add sign-out link below the PinPad**

After the `<PinPad>` component (line 70), add:

```tsx
<button
  onClick={() => setShowSignOut(true)}
  className="mt-6 w-full text-center text-sm text-gray-400 hover:text-gray-600"
>
  {t('settings.signOutFromPin')}
</button>
```

- [ ] **Step 3: Add confirmation dialog**

After the closing `</div>` of the main container (before the final `</div>` on line 73), add:

```tsx
{showSignOut && (
  <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div className="mx-4 w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
      <h3 className="text-lg font-bold text-gray-900">
        {t('settings.signOutTerminal')}
      </h3>
      <p className="mt-2 text-sm text-gray-600">
        {t('settings.signOutConfirmMessage')}
      </p>
      <div className="mt-6 flex gap-3">
        <button
          onClick={() => setShowSignOut(false)}
          className="flex-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          {t('settings.cancel')}
        </button>
        <button
          onClick={handleSignOut}
          className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700"
        >
          {t('settings.signOut')}
        </button>
      </div>
    </div>
  </div>
)}
```

- [ ] **Step 4: Verify it compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/PinEntryPage.tsx
git commit -m "feat(pos): add sign-out option on PIN entry screen"
```

---

### Task 5: Security settings UI in SettingsPage

**Files:**
- Modify: `apps/pos/src/pages/SettingsPage.tsx`

Add a "Security" section with inactivity timeout selector and lock-after-sale toggle.

- [ ] **Step 1: Import settingsStore selectors**

At the top of `apps/pos/src/pages/SettingsPage.tsx`, add to imports from `settingsStore`:

```typescript
import { useSettingsStore, SUPPORTED_LANGUAGES } from '@/stores/settingsStore';
```

Inside the component, add selectors (near the other settingsStore selectors around line 42):

```typescript
const inactivityTimeout = useSettingsStore((s) => s.inactivityTimeout);
const lockAfterSale = useSettingsStore((s) => s.lockAfterSale);
const setInactivityTimeout = useSettingsStore((s) => s.setInactivityTimeout);
const setLockAfterSale = useSettingsStore((s) => s.setLockAfterSale);
```

- [ ] **Step 2: Define timeout presets**

Add a constant outside the component (after imports):

```typescript
const TIMEOUT_PRESETS = [
  { value: 30, labelKey: 'settings.timeout30s' },
  { value: 60, labelKey: 'settings.timeout1m' },
  { value: 120, labelKey: 'settings.timeout2m' },
  { value: 300, labelKey: 'settings.timeout5m' },
  { value: 600, labelKey: 'settings.timeout10m' },
  { value: 0, labelKey: 'settings.timeoutNever' },
] as const;
```

- [ ] **Step 3: Add Security section JSX**

Add a new section between the "Touch & Display" section and the "Receipt Printer" section (after the closing `</section>` around line 272, before the Receipt Printer `<section>`). Import `Shield` from lucide-react in the existing import statement.

```tsx
{/* Security */}
<section className="rounded-xl bg-white p-4 shadow-sm">
  <div className="mb-4 flex items-center gap-2">
    <Shield className="h-5 w-5 text-gray-700" />
    <h2 className="text-base font-bold text-gray-900">
      {t('settings.security')}
    </h2>
  </div>

  <div className="space-y-4">
    {/* Inactivity timeout */}
    <div>
      <label className="mb-1 block text-sm font-medium text-gray-900">
        {t('settings.inactivityTimeout')}
      </label>
      <p className="mb-2 text-xs text-gray-500">
        {t('settings.inactivityTimeoutDesc')}
      </p>
      <div className="flex flex-wrap gap-2">
        {TIMEOUT_PRESETS.map((preset) => (
          <button
            key={preset.value}
            onClick={() => setInactivityTimeout(preset.value)}
            className={cn(
              'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              inactivityTimeout === preset.value
                ? 'bg-blue-600 text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
            )}
          >
            {t(preset.labelKey)}
          </button>
        ))}
      </div>
    </div>

    {/* Lock after each sale */}
    <div className="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-3">
      <div>
        <span className="text-sm font-medium text-gray-900">
          {t('settings.lockAfterSale')}
        </span>
        <p className="text-xs text-gray-500">
          {t('settings.lockAfterSaleDesc')}
        </p>
      </div>
      <button
        onClick={() => setLockAfterSale(!lockAfterSale)}
        className={cn(
          'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
          lockAfterSale ? 'bg-blue-600' : 'bg-gray-300',
        )}
        role="switch"
        aria-checked={lockAfterSale}
      >
        <span
          className={cn(
            'inline-block h-4 w-4 rounded-full bg-white transition-transform',
            lockAfterSale ? 'translate-x-6' : 'translate-x-1',
          )}
        />
      </button>
    </div>
  </div>
</section>
```

- [ ] **Step 4: Verify it compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/SettingsPage.tsx
git commit -m "feat(pos): add Security settings section with timeout and lock-after-sale"
```

---

### Task 6: Add i18n translation keys

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`
- Modify: `apps/pos/src/locales/fr/pos.json`

- [ ] **Step 1: Add English translation keys**

In `apps/pos/src/locales/en/pos.json`, add to the `settings` object (after existing settings keys):

```json
"security": "Security",
"inactivityTimeout": "Auto-Lock After Inactivity",
"inactivityTimeoutDesc": "Lock the screen after a period of inactivity",
"lockAfterSale": "Lock After Each Sale",
"lockAfterSaleDesc": "Return to PIN screen after completing a transaction",
"timeout30s": "30 seconds",
"timeout1m": "1 minute",
"timeout2m": "2 minutes",
"timeout5m": "5 minutes",
"timeout10m": "10 minutes",
"timeoutNever": "Never",
"signOutTerminal": "Sign Out Terminal?",
"signOutConfirmMessage": "This will disconnect the terminal from the business. You will need to enter the account email and password to reconnect.",
"signOut": "Sign Out",
"signOutFromPin": "Sign out of this account"
```

- [ ] **Step 2: Add French translation keys**

In `apps/pos/src/locales/fr/pos.json`, add to the `settings` object:

```json
"security": "Sécurité",
"inactivityTimeout": "Verrouillage automatique après inactivité",
"inactivityTimeoutDesc": "Verrouiller l'écran après une période d'inactivité",
"lockAfterSale": "Verrouiller après chaque vente",
"lockAfterSaleDesc": "Retourner à l'écran PIN après avoir terminé une transaction",
"timeout30s": "30 secondes",
"timeout1m": "1 minute",
"timeout2m": "2 minutes",
"timeout5m": "5 minutes",
"timeout10m": "10 minutes",
"timeoutNever": "Jamais",
"signOutTerminal": "Déconnecter le terminal ?",
"signOutConfirmMessage": "Cela déconnectera le terminal de l'entreprise. Vous devrez entrer l'adresse email et le mot de passe du compte pour vous reconnecter.",
"signOut": "Déconnecter",
"signOutFromPin": "Se déconnecter de ce compte"
```

- [ ] **Step 3: Verify frontend compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json
git commit -m "feat(pos): add i18n keys for session security settings"
```
