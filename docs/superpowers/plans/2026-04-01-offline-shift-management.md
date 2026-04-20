# POS Offline Shift Management

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shift management work offline. Currently, openShift(), closeShift(), and fetchCurrentShift() are all online-only. If the app restarts while offline, the shift is lost and the cashier is stuck on the "Open Shift" screen unable to sell.

**Architecture:** POS desktop (Tauri 2 + React) at `apps/pos/`, shift state in Zustand, persistent storage via Tauri Store (`izipos-settings.json`).

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Vitest

**Root Cause:** The `Shift` object lives only in Zustand memory. On restart, `fetchCurrentShift()` calls the API; when offline, it fails and sets `shift: null`, sending the user to "Open Shift" screen. Opening a shift also requires an API call with no offline fallback.

---

## Task 1: Persist Shift to Tauri Store

**Why:** The shift must survive app restarts. When the API fails on startup, the app should restore the shift from local storage.

**Files:**
- Modify: `apps/pos/src/lib/storage.ts` — add `SHIFT` storage key
- Modify: `apps/pos/src/stores/terminalStore.ts` — persist shift on open, restore on fetch failure, clear on close

- [ ] **Step 1: Write failing test — fetchCurrentShift restores shift from storage when API fails**

- [ ] **Step 2: Add SHIFT key to StorageKeys**

```typescript
export const StorageKeys = {
  // ...existing keys...
  SHIFT: 'current_shift',
} as const;
```

- [ ] **Step 3: Persist shift when opened or fetched from API**

In `openShift()`, after setting the shift in state, persist it:
```typescript
const shift = await apiPost<Shift>('/pos/shifts/open', body);
await setStoredValue(StorageKeys.SHIFT, shift);
set({ shift, isLoading: false });
```

In `fetchCurrentShift()`, persist when API succeeds:
```typescript
const shift = await apiGet<Shift | null>(`/pos/shifts/current/${terminal.code}`);
if (shift) {
  await setStoredValue(StorageKeys.SHIFT, shift);
}
set({ shift });
```

- [ ] **Step 4: Restore shift from storage when API fails**

In `fetchCurrentShift()`:
```typescript
try {
  const shift = await apiGet<Shift | null>(`/pos/shifts/current/${terminal.code}`);
  if (shift) {
    await setStoredValue(StorageKeys.SHIFT, shift);
  } else {
    await removeStoredValue(StorageKeys.SHIFT);
  }
  set({ shift });
} catch {
  // Offline fallback: restore from persistent storage
  const cachedShift = await getStoredValue<Shift>(StorageKeys.SHIFT);
  set({ shift: cachedShift });
}
```

- [ ] **Step 5: Clear shift from storage on close and reset**

In `closeShift()`:
```typescript
await removeStoredValue(StorageKeys.SHIFT);
set({ shift: null, isLoading: false });
```

In `reset()`:
```typescript
void removeStoredValue(StorageKeys.SHIFT);
```

- [ ] **Step 6: Run tests, verify green**

---

## Task 2: Offline Shift Open

**Why:** If the app restarts offline with no cached shift, the cashier needs to open a shift to sell. This must work without an API call, with the shift synced later.

**Files:**
- Modify: `apps/pos/src/stores/terminalStore.ts` — add offline shift open fallback

- [ ] **Step 1: Write failing test — openShift works when API fails**

- [ ] **Step 2: Implement offline shift open**

When the API call fails, create a local shift object:

```typescript
openShift: async (openingCash: string, cashierId?: string) => {
  const { terminal } = get();
  if (!terminal) throw new Error('No terminal configured');

  set({ isLoading: true });
  try {
    const body: Record<string, string> = {
      terminal_code: terminal.code,
      opening_cash: openingCash,
    };
    if (cashierId) {
      body['cashier_id'] = cashierId;
    }
    const shift = await apiPost<Shift>('/pos/shifts/open', body);
    await setStoredValue(StorageKeys.SHIFT, shift);
    set({ shift, isLoading: false });
  } catch {
    // Offline fallback: create local shift
    const authState = useAuthStore.getState();
    const user = authState.user;
    const offlineShift: Shift = {
      id: `offline-${crypto.randomUUID()}`,
      terminal_id: terminal.id,
      shift_number: 0, // Will be assigned by server on sync
      status: 'OPEN',
      opening_cash: openingCash,
      opened_at: new Date().toISOString(),
      user: {
        id: cashierId ?? user?.id ?? '',
        name: user?.name ?? 'Operator',
      },
    };
    await setStoredValue(StorageKeys.SHIFT, offlineShift);
    set({ shift: offlineShift, isLoading: false });
  }
},
```

- [ ] **Step 3: Run tests, verify green**

---

## Task 3: Offline Shift Close (Queue for Sync)

**Why:** End-of-day shift close must work offline. The Z-report system already handles offline Z-reports, so this just needs to update local state and queue the close.

**Files:**
- Modify: `apps/pos/src/stores/terminalStore.ts`

- [ ] **Step 1: Write failing test — closeShift works when API fails**

- [ ] **Step 2: Implement offline shift close**

```typescript
closeShift: async (actualCash: string) => {
  const { shift } = get();
  if (!shift) throw new Error('No active shift');

  set({ isLoading: true });
  try {
    await apiPost<Shift>(`/pos/shifts/${shift.id}/close`, {
      actual_cash: actualCash,
    });
  } catch {
    console.warn('[Terminal] Shift close API failed (offline), closing locally');
    // Shift close will be synced when Z-report is pushed
  }
  await removeStoredValue(StorageKeys.SHIFT);
  set({ shift: null, isLoading: false });
},
```

- [ ] **Step 3: Run tests, verify green**

---

## Task 4: Run Full Suite and Commit

- [ ] **Step 1: Run all tests**
- [ ] **Step 2: Commit and push to main + dev**
