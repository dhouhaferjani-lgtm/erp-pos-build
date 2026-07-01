import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// Phase 1 Task 27 Pass 1: `createReceipt` / `processReceiptPayments` removed
// from `@/api/receiptApi`. Local-first checkout never calls them anyway —
// stub only the remaining read methods.
vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({ execute: vi.fn(), select: vi.fn() })),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(async () => {
    // Simulate ~5ms SQLite write
    await new Promise((r) => setTimeout(r, 5));
    return {
      receiptNumber: 'MAIN-T001-2026-' + String(Math.floor(Math.random() * 1e6)).padStart(8, '0'),
      total: '10.00',
      subtotal: '10.00',
      taxAmount: '0.00',
      discountAmount: '0.00',
      changeDue: 0,
      fiscalHash: 'h',
      idempotencyKey: crypto.randomUUID(),
      localId: crypto.randomUUID(),
    };
  }),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/offlineCheckoutService', () => ({
  executeCheckout: vi.fn(),
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

describe('paymentStore stress / throughput (offline-first)', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: { id: 'u1', name: 'U', email: 'u@x', tenantId: 't', phone: null, status: 'active', locale: null, timezone: null, roles: [], permissions: [], emailVerified: true },
      companyId: 'c1',
      companies: [{
        id: 'c1',
        name: 'X',
        legalName: 'X',
        tax_id: '123456789',
        countryCode: 'FR',
        address_street: '1 Rue Test',
        address_city: 'Paris',
        address_postal_code: '75001',
        currency: 'EUR',
        locale: 'fr',
        timezone: 'Europe/Paris',
      }],
      token: 't', serverUrl: 'http://', isAuthenticated: true, isLoading: false, isInitialized: true,
    });
    useOperatorStore.setState({
      operator: { id: 'op1', name: 'O', email: 'o@x', roles: [], permissions: [], can_discount: false, max_discount_percent: null },
      isLocked: false, lastActivity: Date.now(), hasPins: true,
    });
    useTerminalStore.setState({
      terminal: {
        id: 'term-1',
        code: 'T001',
        name: 'Counter 1',
        type: 'fixed',
        is_active: true,
        is_training_mode: false,
        hardware_identifier: null,
        location: { id: 'loc1', name: 'Main', code: 'MAIN' },
      },
      shift: {
        id: '019eb000-0000-7000-8000-000000000001',
        terminal_id: 'term-1',
        shift_number: 1,
        status: 'OPEN',
        opening_cash: '0.00',
        opened_at: '2026-05-20T08:00:00Z',
        user: { id: 'u1', name: 'U' },
      },
      hashChainReady: true,
    } as never);
    useCartStore.setState({ items: [makeCartItem({ line_total: '10.00', tax_amount: '0.00' })] });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('completes 100 sequential cash checkouts in under 5 seconds (50ms/receipt avg)', async () => {
    const items = useCartStore.getState().items;
    const start = performance.now();
    for (let i = 0; i < 100; i++) {
      await usePaymentStore.getState().processCashCheckout('term-1', items, '10.00');
    }
    const elapsed = performance.now() - start;
    expect(elapsed).toBeLessThan(5000);
    // eslint-disable-next-line no-console
    console.log(`[stress] 100 checkouts in ${elapsed.toFixed(0)}ms (${(elapsed / 100).toFixed(1)}ms/receipt)`);
  });

  it('P95 latency under 150ms per checkout', async () => {
    const items = useCartStore.getState().items;
    const timings: number[] = [];
    for (let i = 0; i < 50; i++) {
      const t0 = performance.now();
      await usePaymentStore.getState().processCashCheckout('term-1', items, '10.00');
      timings.push(performance.now() - t0);
    }
    timings.sort((a, b) => a - b);
    const p95 = timings[Math.floor(timings.length * 0.95)]!;
    // eslint-disable-next-line no-console
    console.log(`[stress] P95 latency: ${p95.toFixed(1)}ms`);
    expect(p95).toBeLessThan(150);
  });
});
