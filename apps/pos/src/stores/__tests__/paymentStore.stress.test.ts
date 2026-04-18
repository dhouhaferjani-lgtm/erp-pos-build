import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
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
  beforeEach(() => {
    vi.clearAllMocks();
    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: { id: 'u1', name: 'U', email: 'u@x', tenantId: 't', phone: null, status: 'active', locale: null, timezone: null, roles: [], permissions: [], emailVerified: true },
      companyId: 'c1',
      companies: [{ id: 'c1', name: 'X', legalName: 'X', countryCode: 'FR', currency: 'EUR', locale: 'fr', timezone: 'Europe/Paris' }],
      token: 't', serverUrl: 'http://', isAuthenticated: true, isLoading: false, isInitialized: true,
    });
    useOperatorStore.setState({
      operator: { id: 'op1', name: 'O', email: 'o@x', roles: [], permissions: [], can_discount: false, max_discount_percent: null },
      isLocked: false, lastActivity: Date.now(), hasPins: true,
    });
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
      await usePaymentStore.getState().processCashCheckout('term-1', items, 10);
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
      await usePaymentStore.getState().processCashCheckout('term-1', items, 10);
      timings.push(performance.now() - t0);
    }
    timings.sort((a, b) => a - b);
    const p95 = timings[Math.floor(timings.length * 0.95)]!;
    // eslint-disable-next-line no-console
    console.log(`[stress] P95 latency: ${p95.toFixed(1)}ms`);
    expect(p95).toBeLessThan(150);
  });
});
