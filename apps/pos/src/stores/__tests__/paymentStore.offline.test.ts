import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn(),
  getAllPaymentRepositories: vi.fn(),
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
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { executeCheckout } from '@/lib/offline/offlineCheckoutService';
import { getDatabase } from '@/lib/db';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';

const mockDb = {
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  select: vi.fn().mockResolvedValue([]),
  close: vi.fn().mockResolvedValue(undefined),
} as unknown as import('@tauri-apps/plugin-sql').default;

const mockCashMethod = makePaymentMethod({ id: 'pm-1', code: 'CASH', is_physical: true, has_maturity: false });
const mockCardMethod = makePaymentMethod({
  id: 'pm-2',
  code: 'card',
  is_physical: false,
  requires_third_party: true,
});
const mockCashRegister = makePaymentRepository({ id: 'repo-1', type: 'cash_register' });
const mockCardRepo = makePaymentRepository({ id: 'repo-2', type: 'bank_account' });

function makeOfflineResult(overrides: Partial<CheckoutResult> = {}): CheckoutResult {
  return {
    isOffline: true,
    receiptId: 'offline-MAIN-T001-2026-00000001',
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    changeDue: 50,
    fiscalHash: 'offline-hash-123',
    onlineReceipt: null,
    onlinePayment: null,
    ...overrides,
  };
}

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

describe('paymentStore - offline checkout integration', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod, mockCardMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister, mockCardRepo]);
  });

  it('cash checkout succeeds with offline result', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();
    vi.mocked(executeCheckout).mockResolvedValue(makeOfflineResult());

    const items = [makeCartItem({ line_total: '50.00' })];
    await usePaymentStore.getState().processCashCheckout('terminal-1', items, 100);

    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(true);
    expect(state.lastReceipt).not.toBeNull();
    expect(state.lastReceipt!.receipt_number).toBe('MAIN-T001-2026-00000001');
    expect(state.changeDue).toBe(50);
    expect(state.isProcessing).toBe(false);
  });

  it('cash checkout succeeds with online result', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();
    vi.mocked(executeCheckout).mockResolvedValue(makeOnlineResult());

    const items = [makeCartItem({ line_total: '50.00' })];
    await usePaymentStore.getState().processCashCheckout('terminal-1', items, 100);

    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(false);
    expect(state.lastReceipt).not.toBeNull();
    expect(state.lastReceipt!.id).toBe('receipt-1');
  });

  it('card checkout succeeds with offline result', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();
    vi.mocked(executeCheckout).mockResolvedValue(makeOfflineResult());

    const items = [makeCartItem({ line_total: '50.00' })];
    await usePaymentStore.getState().processCardCheckout('terminal-1', items);

    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(true);
    expect(state.changeDue).toBe(50);
  });

  it('advanced checkout succeeds with offline result', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();
    vi.mocked(executeCheckout).mockResolvedValue(makeOfflineResult({ changeDue: 0 }));

    const items = [makeCartItem({ line_total: '50.00' })];
    const payments = [{ payment_method_id: 'pm-1', amount: 50, repository_id: 'repo-1' }];
    await usePaymentStore.getState().processAdvancedCheckout('terminal-1', items, payments);

    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(true);
  });

  it('sets isProcessing during checkout and clears after', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();

    let capturedProcessing = false;
    vi.mocked(executeCheckout).mockImplementation(async () => {
      capturedProcessing = usePaymentStore.getState().isProcessing;
      return makeOnlineResult();
    });

    const items = [makeCartItem()];
    await usePaymentStore.getState().processCashCheckout('terminal-1', items, 100);

    expect(capturedProcessing).toBe(true);
    expect(usePaymentStore.getState().isProcessing).toBe(false);
  });

  it('sets error and clears isProcessing when executeCheckout throws', async () => {
    await usePaymentStore.getState().fetchPaymentConfig();
    vi.mocked(executeCheckout).mockRejectedValue(new Error('Both online and offline failed'));

    const items = [makeCartItem()];
    await expect(
      usePaymentStore.getState().processCashCheckout('terminal-1', items, 50),
    ).rejects.toThrow('Both online and offline failed');

    const state = usePaymentStore.getState();
    expect(state.isProcessing).toBe(false);
    expect(state.error).toBe('Both online and offline failed');
  });
});
