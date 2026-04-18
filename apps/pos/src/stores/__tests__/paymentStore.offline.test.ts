import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
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

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(),
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

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({
      scheduler: null,
      pendingReceiptCount: 0,
      setPendingCount: vi.fn(),
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

describe('paymentStore - offline checkout integration', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod, mockCardMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister, mockCardRepo]);
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
});
