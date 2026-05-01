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

  /**
   * B3-followup audit (Minor 1, 2026-05-01): `onlineCheckout()` was extended
   * in commit 9f7874db to forward `input.payments[]` with instrument fields
   * rather than hardcoding a single-cash payment.  These two cases lock that
   * branch so a regression (e.g. reverting the conditional spread back to a
   * hardcoded single-cash row) fails loudly.
   */
  it('online path forwards instrument fields to processReceiptPayments when input carries a voucher payment', async () => {
    vi.mocked(createReceipt).mockResolvedValue({
      id: 'receipt-voucher',
      receipt_number: 'R-VCH-001',
      total: '75.00',
      subtotal: '75.00',
      tax_amount: '7.50',
      discount_amount: '0.00',
      currency: 'EUR',
    });
    vi.mocked(processReceiptPayments).mockResolvedValue({
      receipt: { id: 'receipt-voucher', receipt_number: 'R-VCH-001', total: '75.00' },
      receipt_payments: [
        { id: 'rp-cash', payment_method_id: 'pm-cash', amount: '25.00' },
        { id: 'rp-vchr', payment_method_id: 'pm-store-voucher', amount: '50.00' },
      ],
      treasury_payments: [],
      change_due: '0.00',
    });

    const input = makeInput({
      payments: [
        {
          methodCode: 'CASH',
          amount: '25.00',
          paymentMethodId: 'pm-cash',
          repositoryId: 'repo-cash',
        },
        {
          methodCode: 'store_voucher',
          amount: '50.00',
          paymentMethodId: 'pm-store-voucher',
          repositoryId: 'repo-virtual',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-9999',
        },
      ],
    });

    const result = await executeCheckout(db, input);

    expect(result.isOffline).toBe(false);
    expect(result.receiptId).toBe('receipt-voucher');

    expect(processReceiptPayments).toHaveBeenCalledOnce();
    const [, body] = vi.mocked(processReceiptPayments).mock.calls[0]!;
    expect(body.payments).toHaveLength(2);

    // Cash row must NOT carry instrument fields.
    const cashRow = body.payments[0]!;
    expect(cashRow.payment_method_id).toBe('pm-cash');
    expect(cashRow.amount).toBe(25);
    expect(cashRow.repository_id).toBe('repo-cash');
    expect(cashRow.instrument_type).toBeUndefined();
    expect(cashRow.instrument_serial).toBeUndefined();

    // Voucher row MUST carry instrument fields verbatim.
    const voucherRow = body.payments[1]!;
    expect(voucherRow.payment_method_id).toBe('pm-store-voucher');
    expect(voucherRow.amount).toBe(50);
    expect(voucherRow.repository_id).toBe('repo-virtual');
    expect(voucherRow.instrument_type).toBe('store_voucher');
    expect(voucherRow.instrument_serial).toBe('SV-2026-9999');
  });

  it('online path falls back to single-cash payment when input.payments is absent (sanity: conditional spread)', async () => {
    vi.mocked(createReceipt).mockResolvedValue({
      id: 'receipt-cash-only',
      receipt_number: 'R-CASH-001',
      total: '50.00',
      subtotal: '50.00',
      tax_amount: '5.00',
      discount_amount: '0.00',
      currency: 'EUR',
    });
    vi.mocked(processReceiptPayments).mockResolvedValue({
      receipt: { id: 'receipt-cash-only', receipt_number: 'R-CASH-001', total: '50.00' },
      receipt_payments: [{ id: 'rp-1', payment_method_id: 'pm-1', amount: '50.00' }],
      treasury_payments: [],
      change_due: '0.00',
    });

    // Explicitly omit `payments` to hit the fallback branch.
    const input = makeInput({ payments: undefined });

    await executeCheckout(db, input);

    expect(processReceiptPayments).toHaveBeenCalledOnce();
    const [, body] = vi.mocked(processReceiptPayments).mock.calls[0]!;
    expect(body.payments).toHaveLength(1);

    const singleRow = body.payments[0]!;
    expect(singleRow.payment_method_id).toBe('pm-1');
    expect(singleRow.amount).toBe(50);   // parseFloat(receipt.total)
    expect(singleRow.instrument_type).toBeUndefined();
    expect(singleRow.instrument_serial).toBeUndefined();
  });
});
