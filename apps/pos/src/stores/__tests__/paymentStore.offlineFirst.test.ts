import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(() => { throw new Error('createReceipt API must not be called in offline-first flow'); }),
  processReceiptPayments: vi.fn(() => { throw new Error('processReceiptPayments API must not be called at checkout time'); }),
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(async () => ({
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    changeDue: 50,
    fiscalHash: 'mock-hash',
  })),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({ execute: vi.fn(), select: vi.fn() })),
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
    }),
  },
}));

describe('paymentStore offline-first cash checkout', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: { id: 'user-1', name: 'Houssem', email: 'h@example.com', tenantId: 't1', phone: null, status: 'active', locale: null, timezone: null, roles: [], permissions: [], emailVerified: true },
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Test Co', legalName: 'Test SA', countryCode: 'FR', currency: 'EUR', locale: 'fr', timezone: 'Europe/Paris' }],
      token: 'tok',
      serverUrl: 'http://localhost',
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });
    useOperatorStore.setState({
      operator: { id: 'op-1', name: 'Cashier Alice', email: 'a@x.com', roles: [], permissions: [], can_discount: false, max_discount_percent: null },
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: true,
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })],
    });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('writes receipt to SQLite first and never calls createReceipt API', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    const { createReceipt } = await import('@/api/receiptApi');

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 100);

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    expect(createReceipt).not.toHaveBeenCalled();

    const state = usePaymentStore.getState();
    expect(state.lastReceipt?.receipt_number).toBe('MAIN-T001-2026-00000001');
    expect(state.changeDue).toBe(50);
    expect(state.error).toBeNull();
  });

  it('passes operator from useOperatorStore to createOfflineReceipt', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 100);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        terminalId: 'term-1',
        operatorId: 'op-1',
        operatorName: 'Cashier Alice',
        currency: 'EUR',
        payments: expect.arrayContaining([
          expect.objectContaining({ methodCode: 'CASH', amount: '50.00' }),
        ]),
      }),
    );
  });

  it('surfaces terminal bootstrap error cleanly when chain not initialized', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    vi.mocked(createOfflineReceipt).mockRejectedValueOnce(
      new Error('Terminal hash chain not initialized. Cannot create offline receipt.'),
    );

    await expect(
      usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 100),
    ).rejects.toThrow(/Terminal hash chain not initialized/);

    expect(usePaymentStore.getState().isProcessing).toBe(false);
    expect(usePaymentStore.getState().error).toMatch(/Terminal hash chain not initialized/);
  });

  it('forwards consumption_mode and table_id when provided', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    await usePaymentStore.getState().processCashCheckout(
      'term-1',
      useCartStore.getState().items,
      100,
      undefined,
      'SUR_PLACE',
      'table-7',
    );

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({ consumptionMode: 'SUR_PLACE', tableId: 'table-7' }),
    );
  });
});
