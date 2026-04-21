import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn().mockReturnValue({ isOnline: true }),
  },
}));

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(),
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('mock-hash'),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
  advanceHashChain: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: vi.fn(),
}));

import { executeCheckout, type CheckoutInput } from '../offlineCheckoutService';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { makeCartItem } from '@/test/helpers';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

function makeInput(overrides: Partial<CheckoutInput> = {}): CheckoutInput {
  return {
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Test Operator',
    cartItems: [makeCartItem({ line_total: '50.00', tax_amount: '5.00' })],
    currency: 'EUR',
    paymentMethodId: 'pm-1',
    paymentRepositoryId: 'repo-1',
    tenderedAmount: 100,
    receiptData: { terminal_id: 'terminal-1', lines: [] },
    ...overrides,
  };
}

describe('offlineCheckoutService - executeCheckout', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: true,
      serverReachable: true,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });
  });

  it('uses online path when connected and API succeeds', async () => {
    vi.mocked(createReceipt).mockResolvedValue({
      id: 'receipt-online',
      receipt_number: 'R-001',
      total: '50.00',
      subtotal: '50.00',
      tax_amount: '5.00',
      discount_amount: '0.00',
      currency: 'EUR',
    });
    vi.mocked(processReceiptPayments).mockResolvedValue({
      receipt: { id: 'receipt-online', receipt_number: 'R-001', total: '50.00' },
      receipt_payments: [{ id: 'rp-1', payment_method_id: 'pm-1', amount: '50.00' }],
      treasury_payments: [{ id: 'tp-1', journal_entry_id: 'je-1' }],
      change_due: '50.00',
    });

    const result = await executeCheckout(db, makeInput());

    expect(result.isOffline).toBe(false);
    expect(result.receiptId).toBe('receipt-online');
    expect(result.total).toBe('50.00');
    expect(result.changeDue).toBe(50);
    expect(createReceipt).toHaveBeenCalled();
    expect(createOfflineReceipt).not.toHaveBeenCalled();
  });

  it('uses offline path when disconnected', async () => {
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    vi.mocked(createOfflineReceipt).mockResolvedValue({
      receiptNumber: 'MAIN-T001-2026-00000001',
      total: '50.00',
      subtotal: '50.00',
      taxAmount: '5.00',
      discountAmount: '0.00',
      changeDue: 50,
      fiscalHash: 'offline-hash-123',
      idempotencyKey: 'idem-001',
      localId: 'local-id-001',
    });

    const result = await executeCheckout(db, makeInput());

    expect(result.isOffline).toBe(true);
    expect(result.receiptNumber).toBe('MAIN-T001-2026-00000001');
    expect(result.total).toBe('50.00');
    expect(result.changeDue).toBe(50);
    expect(result.fiscalHash).toBe('offline-hash-123');
    expect(createReceipt).not.toHaveBeenCalled();
    expect(createOfflineReceipt).toHaveBeenCalled();
  });

  it('falls back to offline when online but API fails', async () => {
    vi.mocked(createReceipt).mockRejectedValue(new Error('Server error'));
    vi.mocked(createOfflineReceipt).mockResolvedValue({
      receiptNumber: 'MAIN-T001-2026-00000002',
      total: '50.00',
      subtotal: '50.00',
      taxAmount: '5.00',
      discountAmount: '0.00',
      changeDue: 50,
      fiscalHash: 'fallback-hash-456',
      idempotencyKey: 'idem-002',
      localId: 'local-id-002',
    });

    const result = await executeCheckout(db, makeInput());

    expect(result.isOffline).toBe(true);
    expect(result.receiptNumber).toBe('MAIN-T001-2026-00000002');
    expect(createReceipt).toHaveBeenCalled();
    expect(createOfflineReceipt).toHaveBeenCalled();
  });

  it('falls back to offline when payment processing fails after receipt creation', async () => {
    vi.mocked(createReceipt).mockResolvedValue({
      id: 'receipt-partial',
      receipt_number: 'R-002',
      total: '50.00',
      subtotal: '50.00',
      tax_amount: '5.00',
      discount_amount: '0.00',
      currency: 'EUR',
    });
    vi.mocked(processReceiptPayments).mockRejectedValue(new Error('Payment failed'));
    vi.mocked(createOfflineReceipt).mockResolvedValue({
      receiptNumber: 'MAIN-T001-2026-00000003',
      total: '50.00',
      subtotal: '50.00',
      taxAmount: '5.00',
      discountAmount: '0.00',
      changeDue: 50,
      fiscalHash: 'fallback-hash-789',
      idempotencyKey: 'idem-003',
      localId: 'local-id-003',
    });

    const result = await executeCheckout(db, makeInput());

    expect(result.isOffline).toBe(true);
    expect(createOfflineReceipt).toHaveBeenCalled();
  });

  it('offline result contains all fields needed for success modal', async () => {
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    vi.mocked(createOfflineReceipt).mockResolvedValue({
      receiptNumber: 'MAIN-T001-2026-00000004',
      total: '45.00',
      subtotal: '50.00',
      taxAmount: '5.00',
      discountAmount: '5.00',
      changeDue: 55,
      fiscalHash: 'hash-abc',
      idempotencyKey: 'idem-004',
      localId: 'local-id-004',
    });

    const result = await executeCheckout(db, makeInput());

    expect(result.receiptNumber).toBeDefined();
    expect(result.total).toBe('45.00');
    expect(result.subtotal).toBe('50.00');
    expect(result.taxAmount).toBe('5.00');
    expect(result.discountAmount).toBe('5.00');
    expect(result.changeDue).toBe(55);
    expect(result.fiscalHash).toBe('hash-abc');
  });
});
