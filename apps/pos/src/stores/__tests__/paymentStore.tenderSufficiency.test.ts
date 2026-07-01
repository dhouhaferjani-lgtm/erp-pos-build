/**
 * D0-4 precision sweep — paymentStore tender-sufficiency gates
 *
 * DISCRIMINATING tests (fail on old float code, pass on bccomp strings):
 *
 * 1. estimateCartTotal must return a decimal STRING at currency scale — never a JS number.
 *    Old: `return Number(subtotal)` → 1000×"0.001" TND yields number `1`, not string "1.000".
 *    Test: `expect(estimateCartTotal(...)).toBe("1.000")` → FAILS on old code (1 !== "1.000").
 *
 * 2. Zero-clamp path must return "0.000" (string), not 0 (number).
 *    Old: `return bccomp(total,'0') < 0 ? 0 : Number(total)` → literal `0` (number).
 *    Test: `expect(...).toBe("0.000")` → FAILS on old code.
 *
 * 3. With a valid discount, return type must be string.
 *    Old: `return Number("9.000")` = `9` (number). Test expects "9.000" → FAILS.
 *
 * BEHAVIORAL regression tests (gate meaning preserved after removal of epsilon):
 *
 * 4. TND scale-3: exact tender = total → gate does NOT trigger (bccomp = 0, not < 0).
 * 5. TND scale-3: tender 1 millime short → gate DOES trigger (bccomp = -1 < 0).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
// estimateCartTotal will be exported once the fix is applied (currently unexported).
// Before fix: importing it yields `undefined` → "estimateCartTotal is not a function" → RED ✓
import { estimateCartTotal, usePaymentStore } from '@/stores/paymentStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// ── Module-level mocks required by paymentStore ──────────────────────────────

vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn().mockResolvedValue({
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '9.999',
    subtotal: '9.999',
    taxAmount: '0.000',
    discountAmount: '0.000',
    changeDue: 0,
    idempotencyKey: 'idem-d0-4',
    localId: 'local-d0-4',
  }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({ execute: vi.fn(), select: vi.fn() }),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({
      scheduler: null,
      pendingReceiptCount: 0,
      setPendingCount: vi.fn(),
      triggerSync: vi.fn(),
    }),
  },
}));

// ── TND company auth helper ───────────────────────────────────────────────────
function setTndAuthState(): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test Cashier',
      email: 'cashier@example.com',
      tenantId: 't-tnd',
      phone: null,
      status: 'active',
      locale: null,
      timezone: null,
      roles: [],
      permissions: [],
      emailVerified: true,
    },
    companyId: 'company-tnd',
    companies: [
      {
        id: 'company-tnd',
        name: 'Pharmacie Tunis',
        legalName: 'Pharmacie Tunis SARL',
        tax_id: 'TN0001234',
        countryCode: 'TN',
        address_street: '1 Avenue Habib Bourguiba',
        address_city: 'Tunis',
        address_postal_code: '1000',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
      },
    ],
    token: 'tok',
    serverUrl: 'http://localhost',
    isAuthenticated: true,
    isLoading: false,
    isInitialized: true,
  });
}

// ── estimateCartTotal string-return tests (DISCRIMINATING) ───────────────────
describe('D0-4: estimateCartTotal returns decimal STRING at currency scale', () => {
  it('TND scale-3: integer-valued subtotal returns "1.000" as string, not 1 as number', () => {
    // 1000 items × "0.001" TND.
    // bcsum(["0.001"×1000], 3) = "1.000".
    // OLD code: Number("1.000") = 1 (JS number) → expect("1.000") FAILS (1 !== "1.000")
    // NEW code: returns "1.000" (string) → PASSES
    const items = Array.from({ length: 1000 }, (_, i) =>
      makeCartItem({ id: `item-${i}`, line_total: '0.001' }),
    );
    expect(estimateCartTotal(items, undefined, 'TND')).toBe('1.000');
  });

  it('TND scale-3: zero-clamped total returns "0.000" as string, not 0 as number', () => {
    // Discount (9.000) exceeds subtotal (5.000) → total clamped to zero.
    // OLD code: `return bccomp(total, '0') < 0 ? 0 : Number(total)` → literal number `0`
    // → expect("0.000") FAILS (0 !== "0.000")
    // NEW code: `return ... ? bcformat('0', decimals) : total` → "0.000" → PASSES
    const items = [makeCartItem({ id: 'item-1', line_total: '5.000' })];
    const discount = { type: 'fixed' as const, value: '9.000' };
    expect(estimateCartTotal(items, discount, 'TND')).toBe('0.000');
  });

  it('TND scale-3: with valid fixed discount returns discounted amount as string', () => {
    // 10.000 − 1.000 = 9.000.
    // OLD code: Number("9.000") = 9 (JS number) → expect("9.000") FAILS (9 !== "9.000")
    // NEW code: returns "9.000" → PASSES
    const items = [makeCartItem({ id: 'item-1', line_total: '10.000' })];
    const discount = { type: 'fixed' as const, value: '1.000' };
    expect(estimateCartTotal(items, discount, 'TND')).toBe('9.000');
  });

  it('TND scale-3: zero or negative discount value is ignored — returns subtotal string', () => {
    // OLD guard: `parseFloat("0") <= 0 → return Number(subtotal)` → returns number.
    // NEW guard: `bccomp("0","0") <= 0 → return subtotal` → returns string.
    const items = [makeCartItem({ id: 'item-1', line_total: '7.500' })];
    const discount = { type: 'fixed' as const, value: '0' };
    expect(estimateCartTotal(items, discount, 'TND')).toBe('7.500');
  });
});

// ── processCashCheckout tender gate behavioral tests ─────────────────────────
describe('D0-4: processCashCheckout tender gate — bccomp exact, no epsilon', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    setTndAuthState();
    useOperatorStore.setState({
      operator: { id: 'op-1', name: 'Cashier Slim', email: 'slim@example.com', roles: [] },
    } as never);
    useTerminalStore.setState({
      terminal: {
        id: 'term-1',
        code: 'T001',
        name: 'Caisse 1',
        type: 'fixed',
        is_active: true,
        is_training_mode: false,
        hardware_identifier: null,
        location: { id: 'loc1', name: 'Principale', code: 'MAIN' },
      },
      shift: {
        id: '019eb000-0000-7000-8000-000000000001',
        terminal_id: 'term-1',
        shift_number: 1,
        status: 'OPEN',
        opening_cash: '0.000',
        opened_at: '2026-07-01T08:00:00Z',
        user: { id: 'user-1', name: 'Test Cashier' },
      },
      hashChainReady: true,
    } as never);
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('TND scale-3: tender exactly equal to total does NOT trigger gate', async () => {
    // Cart: 3 × 3.333 TND = 9.999 TND. Tender: "9.999" — exact match.
    // bccomp("9.999", "9.999") = 0 → NOT < 0 → gate does not trigger → checkout resolves.
    const items = [
      makeCartItem({ id: 'i1', line_total: '3.333' }),
      makeCartItem({ id: 'i2', line_total: '3.333' }),
      makeCartItem({ id: 'i3', line_total: '3.333' }),
    ];
    await expect(
      usePaymentStore.getState().processCashCheckout('term-1', items, '9.999'),
    ).resolves.toBeUndefined();
  });

  it('TND scale-3: tender 1 millime short triggers gate and throws', async () => {
    // Tender "9.998" vs total "9.999": bccomp("9.998","9.999") = -1 < 0 → gate triggers.
    const items = [
      makeCartItem({ id: 'i1', line_total: '3.333' }),
      makeCartItem({ id: 'i2', line_total: '3.333' }),
      makeCartItem({ id: 'i3', line_total: '3.333' }),
    ];
    await expect(
      usePaymentStore.getState().processCashCheckout('term-1', items, '9.998'),
    ).rejects.toThrow('Cash tender tolerance requires a manager-authored');
  });
});
