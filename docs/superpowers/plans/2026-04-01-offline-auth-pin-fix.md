# POS Offline Auth & PIN Verification Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three chained bugs that force username/password re-entry when the POS is offline. After this fix, operators can start a new day with PIN-only login even without internet.

**Architecture:** POS desktop (Tauri 2 + React) at `apps/pos/`, Backend (Laravel) at `apps/api/`. Auth tokens stored in Tauri Store (persistent). Operator PINs cached in SQLite as bcrypt hashes.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Vitest, bcryptjs

**Root Cause:** Three functions assume network availability for operations that must work offline:
1. `authStore.initialize()` → `checkSession()` → network error → `logout()` — wipes all auth
2. `operatorStore.verifyPin()` → always hits API, no SQLite fallback
3. `operatorStore.checkHasPins()` → always hits API, no SQLite fallback

---

## Task 1: Fix authStore.initialize() — Don't Logout on Network Errors

**Why:** When the app starts offline, `checkSession()` calls `GET /auth/me` which throws a network error. The catch block treats ALL errors as "token expired" and calls `logout()`, wiping the entire auth state. Only a 401 should trigger logout.

**Files:**
- Modify: `apps/pos/src/stores/authStore.ts:109-118`
- Modify: `apps/pos/src/stores/__tests__/authStore.test.ts`

- [ ] **Step 1: Write failing test — initialize keeps auth when checkSession fails with network error**

Add test to `authStore.test.ts`:
```typescript
it('keeps auth state when checkSession fails with network error (offline)', async () => {
  // Stored values exist (previous session)
  vi.mocked(getStoredValue)
    .mockResolvedValueOnce('jwt-token-123')
    .mockResolvedValueOnce(mockUser)
    .mockResolvedValueOnce('company-1')
    .mockResolvedValueOnce(mockCompanies);

  // checkSession throws a network error (NOT a 401)
  vi.mocked(apiGet).mockRejectedValue(new Error('Failed to fetch'));

  await useAuthStore.getState().initialize();

  const state = useAuthStore.getState();
  expect(state.isAuthenticated).toBe(true);  // Should stay authenticated
  expect(state.token).toBe('jwt-token-123');  // Token preserved
  expect(state.user).toEqual(mockUser);       // User preserved
  expect(state.isInitialized).toBe(true);
});
```

- [ ] **Step 2: Write failing test — initialize logs out when checkSession returns 401**

```typescript
it('logs out when checkSession returns 401 (token expired)', async () => {
  vi.mocked(getStoredValue)
    .mockResolvedValueOnce('expired-token')
    .mockResolvedValueOnce(mockUser)
    .mockResolvedValueOnce('company-1')
    .mockResolvedValueOnce(mockCompanies);

  // checkSession throws a 401 ApiRequestError
  const { ApiRequestError } = await import('@/lib/api');
  vi.mocked(apiGet).mockRejectedValue(
    new ApiRequestError(401, 'Unauthorized', 'UNAUTHORIZED')
  );

  await useAuthStore.getState().initialize();

  const state = useAuthStore.getState();
  expect(state.isAuthenticated).toBe(false);  // Should be logged out
  expect(state.token).toBeNull();
});
```

- [ ] **Step 3: Implement the fix**

In `authStore.ts`, import `ApiRequestError` and change the catch block:
```typescript
import { apiGet, apiPost, ApiRequestError } from '@/lib/api';
// ...
try {
  await get().checkSession();
} catch (error) {
  if (error instanceof ApiRequestError && error.status === 401) {
    get().logout();
  } else {
    console.warn('[auth] Session check failed (likely offline), keeping cached auth');
  }
}
```

- [ ] **Step 4: Run tests, verify green**

---

## Task 2: Fix operatorStore.checkHasPins() — SQLite Fallback

**Why:** `checkHasPins()` is called on app startup to determine whether to show PIN entry or PIN setup. When offline, the API call fails and `hasPins` stays `null`, causing a perpetual loading spinner.

**Files:**
- Modify: `apps/pos/src/stores/operatorStore.ts:61-65`
- Modify: `apps/pos/src/stores/__tests__/operatorStore.test.ts`

- [ ] **Step 1: Write failing test — checkHasPins falls back to SQLite when API fails**

```typescript
it('falls back to SQLite when checkHasPins API fails (offline)', async () => {
  vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));
  vi.mocked(hasOperatorPins).mockResolvedValue(true);

  const result = await useOperatorStore.getState().checkHasPins();

  expect(result).toBe(true);
  expect(useOperatorStore.getState().hasPins).toBe(true);
});
```

- [ ] **Step 2: Implement SQLite fallback**

```typescript
checkHasPins: async () => {
  try {
    const result = await apiGet<{ has_pins: boolean }>('/pos/auth/has-pins');
    set({ hasPins: result.has_pins });
    return result.has_pins;
  } catch {
    // Offline fallback: check local SQLite cache
    try {
      const { companyId } = useAuthStore.getState();
      const db = await getDatabase(companyId ?? '');
      const hasPins = await hasOperatorPins(db);
      set({ hasPins });
      return hasPins;
    } catch {
      return false;
    }
  }
},
```

- [ ] **Step 3: Run tests, verify green**

---

## Task 3: Fix operatorStore.verifyPin() — Offline bcrypt Verification

**Why:** `verifyPin()` always calls `POST /pos/auth/verify-pin`. When offline, PIN entry fails even though PIN hashes are cached in SQLite. We need to verify the PIN locally using bcryptjs.

**Files:**
- Modify: `apps/pos/src/stores/operatorStore.ts:42-49`
- Modify: `apps/pos/src/stores/__tests__/operatorStore.test.ts`
- Read: `apps/pos/src/lib/db/repositories/operatorPinRepository.ts` (getAllOperators)

**Dependencies:** bcryptjs (already installed in package.json)

- [ ] **Step 1: Write failing test — verifyPin falls back to SQLite+bcrypt when API fails**

```typescript
it('verifies PIN offline via SQLite + bcrypt when API fails', async () => {
  vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));
  // Mock bcrypt hash of '1234'
  vi.mocked(getAllOperators).mockResolvedValue([{
    id: 'op-1',
    name: 'Jane Cashier',
    email: 'jane@example.com',
    pin_hash: '$2a$10$...', // Will use real bcrypt hash in test
    roles: ['cashier'],
    permissions: ['pos.sell'],
    can_discount: true,
    max_discount_percent: 10,
  }]);

  await useOperatorStore.getState().verifyPin('1234');

  expect(useOperatorStore.getState().operator).not.toBeNull();
  expect(useOperatorStore.getState().operator!.name).toBe('Jane Cashier');
});
```

- [ ] **Step 2: Write failing test — offline PIN verification rejects wrong PIN**

```typescript
it('rejects wrong PIN in offline mode', async () => {
  vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));
  vi.mocked(getAllOperators).mockResolvedValue([{
    id: 'op-1',
    name: 'Jane Cashier',
    // ... with pin_hash for '1234'
  }]);

  await expect(
    useOperatorStore.getState().verifyPin('9999')
  ).rejects.toThrow();
});
```

- [ ] **Step 3: Implement offline PIN verification**

```typescript
import bcrypt from 'bcryptjs';
import { getDatabase } from '@/lib/db';
import { getAllOperators } from '@/lib/db/repositories/operatorPinRepository';
import { useAuthStore } from '@/stores/authStore';

verifyPin: async (pin: string) => {
  try {
    const operator = await apiPost<Operator>('/pos/auth/verify-pin', { pin });
    set({ operator, isLocked: false, lastActivity: Date.now() });
  } catch (error) {
    // Offline fallback: verify against cached bcrypt hashes in SQLite
    const { companyId } = useAuthStore.getState();
    const db = await getDatabase(companyId ?? '');
    const operators = await getAllOperators(db);

    for (const op of operators) {
      if (bcrypt.compareSync(pin, op.pin_hash)) {
        set({
          operator: {
            id: op.id,
            name: op.name,
            email: op.email,
            roles: op.roles,
            permissions: op.permissions,
            can_discount: op.can_discount,
            max_discount_percent: op.max_discount_percent,
          },
          isLocked: false,
          lastActivity: Date.now(),
        });
        return;
      }
    }

    // No match found — rethrow original error if it was a network issue,
    // or throw invalid PIN error
    throw new Error('Invalid PIN');
  }
},
```

- [ ] **Step 4: Run tests, verify green**

---

## Task 4: Run Full Test Suite and Verify

- [ ] **Step 1: Run all POS tests**
```bash
cd apps/pos && pnpm vitest run
```

- [ ] **Step 2: Verify no regressions**

- [ ] **Step 3: Commit and push**

---

## Summary of Changes

| File | Change |
|------|--------|
| `stores/authStore.ts` | Import `ApiRequestError`, only logout on 401 in initialize() |
| `stores/operatorStore.ts` | Add SQLite fallback to `checkHasPins()` and `verifyPin()` with bcrypt |
| `stores/__tests__/authStore.test.ts` | Tests for offline auth persistence |
| `stores/__tests__/operatorStore.test.ts` | Tests for offline PIN verification |
| `package.json` | bcryptjs dependency (already added) |
