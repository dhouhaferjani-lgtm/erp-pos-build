import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';

// Mock API modules
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({
    execute: vi.fn(),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn(),
  }),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/offlineCheckoutService', () => ({
  executeCheckout: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      serverUrl: 'http://localhost',
      token: 'test-token',
      user: { id: 'user-1', name: 'Test User' },
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { executeCheckout } from '@/lib/offline/offlineCheckoutService';

const mockCashMethod = makePaymentMethod();
const mockCashRegister = makePaymentRepository();

const mockCartItems = [
  makeCartItem({
    id: 'cart-1',
    product: { id: 'prod-1', name: 'Widget', sku: 'W-001', price: '25.00' },
    quantity: 2,
    unit_price: '25.00',
    line_total: '50.00',
  }),
];

function makeOnlineResult(overrides: Partial<CheckoutResult> = {}): CheckoutResult {
  return {
    isOffline: false,
    receiptId: 'receipt-1',
    receiptNumber: 'R-001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    changeDue: 50,
    onlineReceipt: {
      id: 'receipt-1',
      receipt_number: 'R-001',
      total: '50.00',
      subtotal: '50.00',
      tax_amount: '0.00',
      discount_amount: '0.00',
      currency: 'EUR',
    },
    onlinePayment: {
      receipt: { id: 'receipt-1', receipt_number: 'R-001', total: '50.00' },
      receipt_payments: [{ id: 'rp-1', payment_method_id: 'pm-1', amount: '50.00' }],
      treasury_payments: [{ id: 'tp-1', journal_entry_id: 'je-1' }],
      change_due: '0.00',
    },
    ...overrides,
  };
}

describe('paymentStore', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
  });

  it('fetches payment configuration', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);

    await usePaymentStore.getState().fetchPaymentConfig();

    const state = usePaymentStore.getState();
    expect(state.paymentMethods).toHaveLength(1);
    expect(state.paymentRepositories).toHaveLength(1);
  });

  it('processes cash checkout end-to-end', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    vi.mocked(executeCheckout).mockResolvedValue(makeOnlineResult());

    await usePaymentStore
      .getState()
      .processCashCheckout('terminal-1', mockCartItems, 100);

    const state = usePaymentStore.getState();
    expect(state.lastReceipt).not.toBeNull();
    expect(state.lastReceipt!.receipt_number).toBe('R-001');
    expect(state.changeDue).toBe(50);
    expect(state.isProcessing).toBe(false);
  });

  it('throws when no cash payment method configured', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', mockCartItems, 50),
    ).rejects.toThrow('No cash payment method configured');
  });

  it('throws when no cash register configured', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([]);
    await usePaymentStore.getState().fetchPaymentConfig();

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', mockCartItems, 50),
    ).rejects.toThrow('No cash register configured');
  });

  it('handles checkout errors', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    vi.mocked(executeCheckout).mockRejectedValue(new Error('Server error'));

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', mockCartItems, 50),
    ).rejects.toThrow('Server error');

    expect(usePaymentStore.getState().error).toBe('Server error');
    expect(usePaymentStore.getState().isProcessing).toBe(false);
  });
});
