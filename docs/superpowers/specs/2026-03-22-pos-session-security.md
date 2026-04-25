# POS Session Security & Configurable Lock Behavior

## Problem

The current POS logout button is visible to all operators and has no confirmation dialog. A single tap disconnects the terminal from the business, forcing a full email/password re-login — a major disruption in a coffee shop or retail environment where the device should stay activated.

Additionally, the inactivity auto-lock timeout is hardcoded at 5 minutes with no way to configure it, and there's no option to auto-lock after each sale — a standard feature in quick-service POS systems for shared terminals.

## Industry Context

Every major POS system (Square, Toast, Clover, Shopify, Lightspeed, Aloha) follows a two-tier model:

- **Device activation** (persistent): Admin/manager binds the terminal to a business with full credentials. Done once, survives restarts.
- **Operator PIN** (ephemeral): Staff authenticate with a short PIN for each session. Resets on lock/timeout/restart.

Key patterns adopted:
- Cashiers can **lock** but cannot **log out** the device
- Only managers/owners can log out, with confirmation
- Inactivity timeout is configurable
- "Lock after each sale" is a toggle for shared terminals (standard in coffee shops)

## Solution

Three changes, all frontend-only (no backend changes needed):

### 1. Protected Logout (Manager-Only)

**Current behavior:** Logout button visible to all operators in the Header. One tap logs out, clears all auth data, returns to email/password login page.

**New behavior:**
- The logout button is **conditionally rendered** (not CSS-hidden) for operators without a manager or admin role
- Cashiers see only: Lock and Switch Operator buttons
- Managers/admins see: Lock, Switch Operator, and Logout buttons
- When a manager taps Logout, a **confirmation dialog** appears:
  - Title: "Sign Out Terminal?"
  - Message: "This will disconnect the terminal from the business. You will need to enter the account email and password to reconnect."
  - Buttons: "Cancel" (secondary) and "Sign Out" (destructive/red)
- The existing logout logic (clear all stores, return to login page) executes only after confirmation

**Role check:** The operator object returned by PIN verify includes `roles: string[]`. Check for `'manager'`, `'admin'`, or `'owner'` role using defensive optional chaining (`operator?.roles?.includes(...)`) in case the field is missing at runtime. The check is purely visual (the backend doesn't have a logout endpoint — it's client-side token clearing).

### 2. Configurable Inactivity Timeout

**Current behavior:** Hardcoded `LOCK_TIMEOUT_MS = 5 * 60 * 1000` in `operatorStore.ts`. Checked every 10 seconds in `AppShell.tsx`.

**New behavior:**
- Replace hardcoded constant with a configurable setting
- Preset options (in seconds): 30, 60, 120, 300 (default), 600, 0 (never)
- Stored in `settingsStore` (which already has Zustand `persist` middleware) since these are device-level preferences, not operator-level state
- `AppShell.tsx` reads the timeout value from `settingsStore` instead of the hardcoded constant
- When set to 0 ("Never"), the inactivity check is skipped entirely

### 3. Lock After Each Sale

**Current behavior:** After checkout, `CheckoutSuccessModal` shows the receipt/print options. When dismissed, returns to the POS home screen with the same operator active.

**New behavior:**
- New boolean setting `lockAfterSale` in `settingsStore` (default: `false`)
- When enabled, after the checkout success modal is dismissed (via "New Sale" button in `HomePage.tsx`'s `handleNewSale` callback), the operator session locks — returning to the PIN entry screen
- The lock happens after the modal flow completes (not before printing/cash drawer operations)
- The cart and payment state clear as usual

### 4. Sign Out from PIN/Lock Screen

**Current behavior:** When the app starts with a valid token, it goes directly to the PIN entry screen. There is no way to log out from this screen — a different manager who doesn't have a PIN on this tenant's account is completely stuck.

**New behavior:**
- Add a small "Sign Out" text link at the bottom of the PIN entry screen (both the lock screen and the initial PIN screen)
- Tapping it shows the same confirmation dialog as the Header logout (Title: "Sign Out Terminal?", with Cancel/Confirm)
- After confirmation, executes the full logout (clear auth token, return to email/password login)
- This is visible to everyone on the PIN screen since the purpose is to allow a different account to log in — there's no operator role context at this point (no one is authenticated as an operator yet)

## Implementation Details

### Files Modified

**`apps/pos/src/components/Header.tsx`:**
- Import `useOperatorStore` to read current operator roles
- Conditionally render the logout button (JSX conditional, not CSS hiding): only render if `operator?.roles?.includes('manager')` or `'admin'` or `'owner'`
- Add a confirmation dialog using the existing Modal component (not `window.confirm`) before executing `handleLogout()`

**`apps/pos/src/pages/PinEntryPage.tsx`:**
- Add a "Sign Out" text link at the bottom of the PIN entry screen
- On tap, show confirmation dialog (same pattern as Header logout)
- After confirmation, call `authStore.logout()` to clear all auth and return to login page
- Visible on both the lock screen variant and the initial PIN entry variant

**`apps/pos/src/stores/operatorStore.ts`:**
- Remove the hardcoded `LOCK_TIMEOUT_MS` constant
- Remove the `lockTimeoutMs` field from state (replaced by `settingsStore.inactivityTimeout`)
- `AppShell.tsx` will read timeout from `settingsStore` instead

**`apps/pos/src/stores/settingsStore.ts`:**
- Add to state (persisted automatically via existing Zustand `persist` middleware):
  - `inactivityTimeout: number` (seconds, default: 300)
  - `lockAfterSale: boolean` (default: false)
- Add actions:
  - `setInactivityTimeout(seconds: number)`
  - `setLockAfterSale(enabled: boolean)`

**`apps/pos/src/components/AppShell.tsx`:**
- Read `inactivityTimeout` from `operatorStore` instead of the hardcoded constant
- If `inactivityTimeout === 0`, skip the interval check entirely
- Convert seconds to milliseconds for the comparison: `inactivityTimeout * 1000`

**`apps/pos/src/pages/HomePage.tsx`:**
- In the `handleNewSale` callback (called when CheckoutSuccessModal is dismissed), check `settingsStore.lockAfterSale`
- If true, call `operatorStore.lock()` to return to PIN screen after clearing cart/payment state
- This happens after the modal flow completes (print/cash drawer operations finish inside the modal before dismiss)

**`apps/pos/src/pages/SettingsPage.tsx`:**
- Add a new "Security" section between "Touch & Display" and "Receipt Printer"
- Contains:
  - **Inactivity timeout**: Segmented control or dropdown with presets (30s, 1min, 2min, 5min, 10min, Never)
  - **Lock after each sale**: Toggle switch
- Both read/write from `settingsStore`

**`apps/pos/src/locales/en/pos.json`:**
- Add keys under `settings`:
  - `security`: "Security"
  - `inactivityTimeout`: "Auto-Lock After Inactivity"
  - `inactivityTimeoutDesc`: "Lock the screen after a period of inactivity"
  - `lockAfterSale`: "Lock After Each Sale"
  - `lockAfterSaleDesc`: "Return to PIN screen after completing a transaction"
  - `timeout30s`: "30 seconds"
  - `timeout1m`: "1 minute"
  - `timeout2m`: "2 minutes"
  - `timeout5m`: "5 minutes"
  - `timeout10m`: "10 minutes"
  - `timeoutNever`: "Never"
  - `signOutTerminal`: "Sign Out Terminal?"
  - `signOutConfirmMessage`: "This will disconnect the terminal from the business. You will need to enter the account email and password to reconnect."
  - `signOut`: "Sign Out"
  - `signOutFromPin`: "Sign out of this account"

**`apps/pos/src/locales/fr/pos.json`:**
- Add corresponding French translations for all keys above

### What Does NOT Change

- **Backend**: No API changes. Roles/permissions already sent on PIN verify.
- **PIN flow**: PIN entry, PIN setup, PIN verification — all unchanged.
- **Shift management**: Open/close shift — unchanged.
- **Switch Operator**: Unchanged (clears operator, shows PIN screen).
- **Inactivity lock mechanism**: Same interval check in AppShell, just reads timeout from store.

## Testing

- **Manual: Cashier cannot see logout** — Log in with a cashier PIN, verify logout button is hidden
- **Manual: Manager sees logout with confirmation** — Log in with a manager PIN, verify logout button visible, verify confirmation dialog appears and Cancel works
- **Manual: Inactivity timeout options** — Change timeout in Settings, verify lock triggers at the configured interval
- **Manual: "Never" timeout** — Set to Never, verify screen does not auto-lock
- **Manual: Lock after sale** — Enable toggle, complete a sale, verify PIN screen appears after modal dismisses
- **Manual: Lock after sale disabled** — Disable toggle, complete a sale, verify POS home screen remains active
- **Manual: Settings persist** — Change timeout and lock-after-sale, restart app, verify settings retained
- **Manual: Sign out from PIN screen** — Lock the screen (or restart app), verify "Sign out" link appears at bottom of PIN screen, verify confirmation dialog works, verify it returns to login page

## Out of Scope

- Manager override (inline PIN prompt for restricted actions) — separate feature
- Configurable lock-after-sale delay (e.g., lock 3 seconds after sale)
- Per-operator timeout settings (this is a device-level setting, not per-user)
- Backend-enforced logout protection (current approach is client-side role check, which is appropriate since logout is client-side token clearing)
