# POS Offline-First Checkout: Complete Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the IziPOS checkout flow 100% reliable offline. When the server is unreachable, checkout must succeed locally using the existing offline receipt infrastructure, with transparent sync when connectivity resumes. Zero data loss, zero blocked sales.

**Architecture:** POS desktop (Tauri 2 + React) at `apps/pos/`, Backend (Laravel) at `apps/api/`. Offline storage is SQLite via `@tauri-apps/plugin-sql`. Connectivity monitoring via `connectivityStore`. Sync via `SyncScheduler` + `syncService`.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Tailwind CSS 4, Tauri 2, Vitest, Laravel 12, PHPUnit

**Root Cause:** The offline receipt infrastructure (`createOfflineReceipt`, sync queue, connectivity store, fiscal hash chain) is fully implemented but **completely disconnected** from the payment flow. `paymentStore.ts` always calls the API with no fallback. The fix is wiring the existing offline path into the checkout flow with connectivity-aware branching.

**Existing Infrastructure (DO NOT rebuild — wire up):**
- `createOfflineReceipt()` in `apps/pos/src/lib/offline/receiptService.ts` — fiscal hash chain, local storage, idempotency
- `offlineReceiptRepository.ts` — SQLite CRUD for offline receipts
- `paymentRepository.ts` — `getAllPaymentMethods()`, `getAllPaymentRepositories()` from SQLite
- `connectivityStore.ts` — `isOnline`, `serverReachable`, browser events
- `syncScheduler.ts` + `syncService.ts` — push offline receipts, pull config, exponential backoff
- `terminalStateRepository.ts` — hash chain state

---

## Task 1: Add DB-Fallback to Payment Config Loading

**Why:** When `fetchPaymentConfig()` fails (network down during shift open), `paymentMethods` stays empty → every checkout attempt fails with "No cash payment method configured". The SQLite DB already has payment methods synced from previous sessions.

**Files:**
- Modify: `apps/pos/src/stores/paymentStore.ts:147-157`
- Read: `apps/pos/src/lib/db/repositories/paymentRepository.ts` (lines 71-85 — `getAllPaymentMethods`, `getAllPaymentRepositories`)
- Read: `apps/pos/src/lib/db/index.ts` (for `getDb()` accessor)

- [ ] **Step 1: Write failing test — paymentStore falls back to SQLite when API fails**

Create `apps/pos/src/__tests__/stores/paymentStore.fallback.test.ts`. Test that when `fetchPaymentMethods()` throws, the store loads from `getAllPaymentMethods(db)` instead, and `paymentMethods` is populated.

Mock `@/api/paymentApi` to reject, mock `@/lib/db/repositories/paymentRepository` to return test data, mock `@/lib/db` to return a fake db handle.

```typescript
it('falls back to SQLite payment methods when API fails', async () => {
  // API rejects
  vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
  vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
  // SQLite returns cached data
  vi.mocked(getAllPaymentMethods).mockResolvedValue([mockCashMethod]);
  vi.mocked(getAllPaymentRepositories).mockResolvedValue([mockCashRegister]);

  await usePaymentStore.getState().fetchPaymentConfig();

  expect(usePaymentStore.getState().paymentMethods).toHaveLength(1);
  expect(usePaymentStore.getState().paymentRepositories).toHaveLength(1);
});
```

- [ ] **Step 2: Implement DB fallback in fetchPaymentConfig**

In `paymentStore.ts`, modify `fetchPaymentConfig`:

```typescript
fetchPaymentConfig: async () => {
  try {
    const [methods, repositories] = await Promise.all([
      fetchPaymentMethods(),
      fetchPaymentRepositories(),
    ]);
    set({ paymentMethods: methods, paymentRepositories: repositories });
  } catch (error) {
    console.warn('[POS] API payment config failed, loading from SQLite:', error);
    try {
      const db = await getDb();
      const [methods, repositories] = await Promise.all([
        getAllPaymentMethods(db),
        getAllPaymentRepositories(db),
      ]);
      if (methods.length > 0) {
        set({ paymentMethods: methods, paymentRepositories: repositories });
        console.info('[POS] Loaded payment config from SQLite cache');
      } else {
        console.error('[POS] No cached payment config in SQLite');
      }
    } catch (dbError) {
      console.error('[POS] SQLite fallback also failed:', dbError);
    }
  }
},
```

Import `getDb` from `@/lib/db` and `getAllPaymentMethods`, `getAllPaymentRepositories` from `@/lib/db/repositories/paymentRepository`.

- [ ] **Step 3: Write test — API success does NOT hit SQLite**

Ensure the happy path is unchanged: when API succeeds, SQLite is never called.

- [ ] **Step 4: Run tests, verify green**

```bash
cd apps/pos && pnpm vitest run src/__tests__/stores/paymentStore.fallback.test.ts
```

---

## Task 2: Create Offline Checkout Service

**Why:** The payment store needs a single entry point that decides online vs. offline checkout. This service encapsulates that decision and calls either the API path or `createOfflineReceipt()`.

**Files:**
- Create: `apps/pos/src/lib/offline/offlineCheckoutService.ts`
- Read: `apps/pos/src/lib/offline/receiptService.ts` (the existing `createOfflineReceipt`)
- Read: `apps/pos/src/stores/connectivityStore.ts`
- Read: `apps/pos/src/stores/authStore.ts` (for operator/company info)

- [ ] **Step 1: Write failing tests for offlineCheckoutService**

Create `apps/pos/src/__tests__/lib/offline/offlineCheckoutService.test.ts`.

Test cases:
1. When online → calls `createReceipt` API + `processReceiptPayments` API → returns online result
2. When offline → calls `createOfflineReceipt` → returns offline result with `isOffline: true`
3. When online but API fails mid-checkout → falls back to `createOfflineReceipt`
4. Offline result contains all fields needed for success modal (receipt number, total, change due)

- [ ] **Step 2: Implement offlineCheckoutService**

Create `apps/pos/src/lib/offline/offlineCheckoutService.ts`:

```typescript
import type Database from '@tauri-apps/plugin-sql';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import type { CartItem } from '@/types/cart';
import type { CreateReceiptResponse, ProcessReceiptPaymentsResponse } from '@/types/receipt';

export interface CheckoutInput {
  terminalId: string;
  operatorId: string;
  operatorName: string;
  cartItems: CartItem[];
  currency: string;
  paymentMethodId: string;
  paymentRepositoryId: string;
  tenderedAmount: number;
  receiptData: Record<string, unknown>; // Built by buildReceiptData
  transactionDiscount?: { type: 'percentage' | 'fixed'; value: string; reason?: string };
}

export interface CheckoutResult {
  isOffline: boolean;
  receiptId: string;
  receiptNumber: string;
  total: string;
  subtotal: string;
  taxAmount: string;
  discountAmount: string;
  changeDue: number;
  fiscalHash?: string;
  // Online-only fields (null when offline)
  onlineReceipt: CreateReceiptResponse | null;
  onlinePayment: ProcessReceiptPaymentsResponse | null;
}

export async function executeCheckout(
  db: Database,
  input: CheckoutInput,
): Promise<CheckoutResult> {
  const { isOnline } = useConnectivityStore.getState();

  if (isOnline) {
    try {
      return await onlineCheckout(input);
    } catch (error) {
      console.warn('[POS] Online checkout failed, falling back to offline:', error);
      return await offlineCheckout(db, input);
    }
  }

  return await offlineCheckout(db, input);
}
```

The `onlineCheckout` function calls `createReceipt` + `processReceiptPayments` (existing API flow).
The `offlineCheckout` function calls `createOfflineReceipt` (existing offline flow).

Both return a normalized `CheckoutResult`.

- [ ] **Step 3: Add timeout wrapper for online checkout attempts**

When connectivity is flaky, the API might hang. Add a 5-second timeout around the online attempt before falling back to offline:

```typescript
const ONLINE_CHECKOUT_TIMEOUT_MS = 5_000;

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  let timeoutId: ReturnType<typeof setTimeout>;
  const timeout = new Promise<never>((_, reject) => {
    timeoutId = setTimeout(() => reject(new Error('Checkout timeout')), ms);
  });
  try {
    return await Promise.race([promise, timeout]);
  } finally {
    clearTimeout(timeoutId!);
  }
}
```

Use this to wrap the online checkout attempt.

- [ ] **Step 4: Run tests, verify green**

---

## Task 3: Wire Offline Checkout Into paymentStore

**Why:** `paymentStore.ts` currently always calls the API. Replace the direct API calls with `executeCheckout()` from the new service.

**Files:**
- Modify: `apps/pos/src/stores/paymentStore.ts` (lines 123-327)
- Read: `apps/pos/src/lib/offline/offlineCheckoutService.ts` (from Task 2)

- [ ] **Step 1: Write failing tests — processCashCheckout works offline**

Create `apps/pos/src/__tests__/stores/paymentStore.offline.test.ts`.

Test cases:
1. Cash checkout succeeds when offline (uses `createOfflineReceipt`)
2. Card checkout succeeds when offline
3. Advanced checkout succeeds when offline
4. `lastReceipt` state is populated correctly from offline result
5. `changeDue` is calculated correctly from offline result
6. `isProcessing` is set/cleared correctly during offline checkout

- [ ] **Step 2: Refactor getOrCreateReceipt to use executeCheckout**

Replace the current `getOrCreateReceipt` function (lines 123-142) and the payment processing in each checkout method. The key change:

```typescript
// BEFORE (always online):
const receipt = await getOrCreateReceipt(...);
const paymentResponse = await processReceiptPayments(receipt.id, { payments: [...] });

// AFTER (online with offline fallback):
const db = await getDb();
const result = await executeCheckout(db, {
  terminalId,
  operatorId: operator.id,
  operatorName: operator.name,
  cartItems,
  currency: company.currency,
  paymentMethodId: cashMethod.id,
  paymentRepositoryId: cashRegister.id,
  tenderedAmount,
  receiptData: buildReceiptData(terminalId, cartItems, transactionDiscount, consumptionMode, tableId),
  transactionDiscount,
});
```

Update `set()` calls to build `lastReceipt` from `CheckoutResult` (both online and offline cases).

- [ ] **Step 3: Add `isOfflineReceipt` flag to PaymentState**

Add to `PaymentState`:
```typescript
isOfflineReceipt: boolean;
```

Set it from `result.isOffline` after checkout. This flag is used by the success modal to show appropriate messaging (Task 7).

- [ ] **Step 4: Update processCardCheckout and processAdvancedCheckout similarly**

Apply the same `executeCheckout` pattern to card and advanced checkout methods. For card checkout offline, the card reference data should be stored in the offline receipt for later sync.

**Important:** For advanced payments (split tender), the offline receipt service currently only supports single payment method. If the user is offline and tries advanced payment, either:
- Store all payment lines in the offline receipt JSON (extend `OfflineReceiptInput`)
- Or restrict advanced payments to online-only with clear messaging

Decision: Extend `OfflineReceiptInput` to accept multiple payment lines. This is the safer option for offline-first.

- [ ] **Step 5: Run all payment store tests**

```bash
cd apps/pos && pnpm vitest run src/__tests__/stores/paymentStore
```

---

## Task 4: Extend Offline Receipt for Multi-Payment and Card Data

**Why:** `createOfflineReceipt` currently only handles a single `paymentMethodId`. Advanced checkout needs multiple payment lines. Card checkout needs card reference data.

**Files:**
- Modify: `apps/pos/src/lib/offline/receiptService.ts`
- Modify: `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
- Read: `apps/pos/src/lib/sync/syncService.ts` (to verify sync payload handles new fields)

- [ ] **Step 1: Write failing tests for multi-payment offline receipts**

Create `apps/pos/src/__tests__/lib/offline/receiptService.multipay.test.ts`.

Test cases:
1. Single payment (cash) — existing behavior, must still work
2. Multi-payment (cash + card split) — stores all payment lines
3. Card payment with `card_last_four` and `transaction_reference`
4. Fiscal hash computation is unchanged (based on total, not payment split)

- [ ] **Step 2: Extend OfflineReceiptInput to accept payment lines**

```typescript
interface OfflinePaymentLine {
  paymentMethodId: string;
  paymentRepositoryId: string;
  amount: number;
  cardLastFour?: string;
  transactionReference?: string;
}

interface OfflineReceiptInput {
  // ... existing fields ...
  payments: OfflinePaymentLine[]; // replaces single paymentMethodId/paymentRepositoryId
  tenderedAmount: number;
  // Keep backward compat: paymentMethodId/paymentRepositoryId become the first payment line
}
```

- [ ] **Step 3: Store payment lines as JSON in offline_receipts**

The `offline_receipts` table has `payment_method_id` and `payment_repository_id` columns (single values). Rather than migrating the table, store the full payment lines in a new `payment_lines` JSON column OR store the primary payment in the existing columns and additional payments in a JSON field.

Simpler approach: keep existing columns for the primary payment (backward-compatible with sync), add a `payment_lines_json` TEXT column for the full array.

Add SQLite migration:
```sql
ALTER TABLE offline_receipts ADD COLUMN payment_lines_json TEXT DEFAULT NULL;
```

- [ ] **Step 4: Update syncService receiptToPayload to include payment lines**

Ensure `pushOfflineReceipts` sends the full payment lines to the server's `/pos/receipts/sync` endpoint.

- [ ] **Step 5: Run tests, verify green**

---

## Task 5: Reduce API Timeout for POS Context

**Why:** The 30-second `connectTimeout` in `api.ts` means a cashier waits 30 seconds for checkout to fail before any fallback kicks in. Unacceptable for POS. The offline checkout service has its own 5-second timeout (Task 2), but the underlying fetch also needs a shorter timeout.

**Files:**
- Modify: `apps/pos/src/lib/api.ts:91`

- [ ] **Step 1: Write test — API request respects timeout**

Test that a request that takes longer than the timeout is rejected.

- [ ] **Step 2: Reduce connectTimeout to 10 seconds**

```typescript
// api.ts line 91
connectTimeout: 10_000,  // 10 seconds — POS context, fail fast for offline fallback
```

**Rationale:** 10 seconds at the fetch level gives the server a reasonable window. The checkout-specific 5-second timeout in `offlineCheckoutService` catches the checkout case faster. Other API calls (sync, product pull) can tolerate 10 seconds.

- [ ] **Step 3: Run existing API tests to verify no regressions**

---

## Task 6: Persist Cart to localStorage

**Why:** If checkout fails or the app crashes, the cart is lost (in-memory only via Zustand). A cashier re-entering 20 items is a real pain point.

**Files:**
- Modify: `apps/pos/src/stores/cartStore.ts`

- [ ] **Step 1: Write failing test — cart persists across store resets**

Test that after adding items to the cart, creating a new store instance loads the same items from localStorage.

- [ ] **Step 2: Add Zustand persist middleware to cartStore**

Zustand has built-in `persist` middleware. Add it to `cartStore`:

```typescript
import { persist } from 'zustand/middleware';

export const useCartStore = create<CartStore>()(
  persist(
    (set, get) => ({
      // ... existing store implementation ...
    }),
    {
      name: 'pos-cart',
      partialize: (state) => ({
        items: state.items,
        transactionDiscount: state.transactionDiscount,
      }),
    },
  ),
);
```

Only persist `items` and `transactionDiscount` — derived values are recomputed.

- [ ] **Step 3: Clear persisted cart on successful checkout**

After checkout success (in `HomePage.tsx` `handleNewSale`), the `clearCart()` call already resets items. Zustand persist will automatically write the empty state. Verify this works.

- [ ] **Step 4: Write test — cart clears from persistence after checkout**

- [ ] **Step 5: Run cart store tests**

```bash
cd apps/pos && pnpm vitest run src/__tests__/stores/cartStore
```

---

## Task 7: Offline Checkout UX — User Feedback

**Why:** When checkout completes offline, the user needs to know: (a) the sale was saved, (b) it will sync later, (c) they can see pending sync count. No silent failures.

**Files:**
- Modify: `apps/pos/src/components/organisms/CheckoutSuccessModal/CheckoutSuccessModal.tsx`
- Modify: `apps/pos/src/components/organisms/Header/Header.tsx` (sync status indicator)
- Add i18n keys: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`

- [ ] **Step 1: Write test — success modal shows offline banner when isOfflineReceipt is true**

- [ ] **Step 2: Add offline indicator to CheckoutSuccessModal**

When `isOfflineReceipt` is true (from paymentStore), show a non-alarming banner:

```
✓ Sale saved locally
Will sync automatically when connection resumes.
Receipt: [offline receipt number]
```

Use `t('checkout.offlineSaved')` and `t('checkout.offlineWillSync')` keys.

- [ ] **Step 3: Add i18n keys for offline checkout messages**

English (`en/pos.json`):
```json
{
  "checkout": {
    "offlineSaved": "Sale saved locally",
    "offlineWillSync": "Will sync automatically when connection resumes",
    "pendingSyncCount": "{{count}} sale(s) pending sync"
  }
}
```

French (`fr/pos.json`):
```json
{
  "checkout": {
    "offlineSaved": "Vente enregistrée localement",
    "offlineWillSync": "Synchronisation automatique au retour de la connexion",
    "pendingSyncCount": "{{count}} vente(s) en attente de synchronisation"
  }
}
```

- [ ] **Step 4: Show pending sync count in Header**

The Header already has connectivity awareness (`isOnline` from `useConnectivityStore`). Add a badge showing the count of pending offline receipts when > 0.

Use `getPendingReceiptCount(db)` from `offlineReceiptRepository.ts` on an interval or after each checkout.

- [ ] **Step 5: Run component tests**

---

## Task 8: Trigger Immediate Sync After Reconnect

**Why:** When the POS goes back online after offline sales, we want receipts to sync ASAP — not wait for the next 1-minute scheduler tick.

**Files:**
- Modify: `apps/pos/src/stores/connectivityStore.ts`
- Modify: `apps/pos/src/lib/sync/syncScheduler.ts`

- [ ] **Step 1: Write failing test — sync triggers immediately on reconnect**

Test that when `isOnline` transitions from `false` → `true`, `syncScheduler.syncNow()` is called.

- [ ] **Step 2: Add onReconnect callback to connectivityStore**

```typescript
// In connectivityStore, when isOnline transitions false → true:
const wasOffline = !get().isOnline;
set({ isOnline: true, serverReachable: true, lastCheckedAt: Date.now() });
if (wasOffline && onReconnectCallback) {
  onReconnectCallback();
}
```

- [ ] **Step 3: Wire SyncScheduler to listen for reconnect**

In the app initialization (where `SyncScheduler` is created and `connectivityStore.startMonitoring()` is called), register a callback:

```typescript
// When connectivity resumes, trigger immediate sync
useConnectivityStore.subscribe((state, prev) => {
  if (state.isOnline && !prev.isOnline) {
    void scheduler.syncNow();
  }
});
```

- [ ] **Step 4: Run tests**

---

## Task 9: Handle Thermal Receipt Printing for Offline Sales

**Why:** After checkout success, `HomePage.tsx` (line 179) calls `fetchReceipt(lastReceipt.id)` to get full receipt data for ESC/POS thermal printing. This API call fails offline because the receipt doesn't exist on the server yet.

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx:170-192`
- Read: `apps/pos/src/lib/buildReceiptData.ts`

- [ ] **Step 1: Write failing test — receipt prints from local data when offline**

- [ ] **Step 2: Build ESC/POS data from offline receipt when isOfflineReceipt is true**

Instead of calling `fetchReceipt(lastReceipt.id)` (which hits API), build the receipt data from the local `CheckoutResult` + cart items already in memory.

```typescript
useEffect(() => {
  if (!showSuccessModal || !lastReceipt || escPosData) return;

  if (isOfflineReceipt) {
    // Build from local data — no API call needed
    const localData = buildEscPosFromOfflineResult(lastReceipt, cartItems, receiptVisibility);
    setEscPosData(localData);
    return;
  }

  // Online: fetch full receipt from API (existing behavior)
  // ...
}, [showSuccessModal, lastReceipt, isOfflineReceipt, escPosData, receiptVisibility]);
```

Create a `buildEscPosFromOfflineResult` helper that maps `CheckoutResult` fields to the same `ReceiptData` shape that `buildEscPosReceiptData` returns.

- [ ] **Step 3: Run tests**

---

## Task 10: Integration Test — Full Offline Checkout E2E

**Why:** Verify the complete flow: offline detection → local receipt creation → success modal → cart clear → sync on reconnect.

**Files:**
- Create: `apps/pos/src/__tests__/integration/offlineCheckout.test.ts`

- [ ] **Step 1: Write integration test — complete offline cash checkout**

Mock connectivity as offline, mock SQLite DB operations, mock terminal state. Execute full flow:
1. `fetchPaymentConfig()` fails API, loads from SQLite ✓
2. Add items to cart ✓
3. `processCashCheckout()` → `executeCheckout()` → `createOfflineReceipt()` ✓
4. `lastReceipt` populated, `isOfflineReceipt` = true ✓
5. `changeDue` correct ✓
6. Cart cleared ✓

- [ ] **Step 2: Write integration test — online checkout with mid-flight fallback**

Mock connectivity as online, but `createReceipt` API rejects. Verify fallback to offline:
1. `processCashCheckout()` → tries online → fails → falls back to offline ✓
2. Result is same as pure offline ✓

- [ ] **Step 3: Write integration test — sync pushes offline receipts when back online**

1. Create offline receipt (from step 1)
2. Set connectivity to online
3. Trigger sync
4. Verify `pushOfflineReceipts` sends correct payload
5. Verify receipt status changes to `synced`

- [ ] **Step 4: Run full test suite**

```bash
cd apps/pos && pnpm vitest run
```

---

## Task 11: Verify Preflight and Type Safety

**Why:** All changes must pass PHPStan, TypeScript strict, ESLint, and the full test suite.

**Files:**
- All modified files from Tasks 1-10

- [ ] **Step 1: Run TypeScript check**

```bash
cd apps/pos && pnpm typecheck
```

- [ ] **Step 2: Run ESLint**

```bash
cd apps/pos && pnpm lint
```

- [ ] **Step 3: Run full Vitest suite**

```bash
cd apps/pos && pnpm vitest run
```

- [ ] **Step 4: Fix any issues found**

- [ ] **Step 5: Run preflight if backend changes were needed**

```bash
./scripts/preflight.sh
```

---

## Summary of Changes

| File | Change | Task |
|------|--------|------|
| `stores/paymentStore.ts` | Add SQLite fallback for payment config, wire `executeCheckout`, add `isOfflineReceipt` state | 1, 3 |
| `lib/offline/offlineCheckoutService.ts` | **NEW** — connectivity-aware checkout router with timeout | 2 |
| `lib/offline/receiptService.ts` | Extend for multi-payment and card data | 4 |
| `lib/db/repositories/offlineReceiptRepository.ts` | Add `payment_lines_json` column handling | 4 |
| `lib/api.ts` | Reduce timeout from 30s to 10s | 5 |
| `stores/cartStore.ts` | Add Zustand persist middleware | 6 |
| `components/organisms/CheckoutSuccessModal` | Offline success banner | 7 |
| `components/organisms/Header` | Pending sync count badge | 7 |
| `locales/en/pos.json`, `locales/fr/pos.json` | Offline checkout i18n keys | 7 |
| `stores/connectivityStore.ts` | Reconnect event for immediate sync | 8 |
| `lib/sync/syncScheduler.ts` | Listen for reconnect, sync immediately | 8 |
| `pages/HomePage.tsx` | Build receipt print data locally for offline sales | 9 |

**Estimated test files:**
- `__tests__/stores/paymentStore.fallback.test.ts`
- `__tests__/stores/paymentStore.offline.test.ts`
- `__tests__/lib/offline/offlineCheckoutService.test.ts`
- `__tests__/lib/offline/receiptService.multipay.test.ts`
- `__tests__/integration/offlineCheckout.test.ts`
