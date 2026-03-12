import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import type { CartItem } from '@/types/cart';

// Mock API modules
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
}));

import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { createReceipt, processReceiptPayments } from '@/api/receiptApi';

const mockCashMethod = {
  id: 'pm-1',
  code: 'CASH',
  name: 'Cash',
  is_physical: true,
  has_maturity: false,
  requires_third_party: false,
  is_push: false,
  has_deducted_fees: false,
  is_restricted: false,
  fee_type: null,
  fee_fixed: '0',
  fee_percent: '0',
  restriction_type: null,
  is_active: true,
  position: 1,
};

const mockCashRegister = {
  id: 'repo-1',
  code: 'CR-001',
  name: 'Cash Register 1',
  type: 'cash_register' as const,
  bank_name: null,
  account_number: null,
  iban: null,
  bic: null,
  balance: '500.00',
  is_active: true,
};

const mockCartItems: CartItem[] = [
  {
    id: 'cart-1',
    product: { id: 'prod-1', name: 'Widget', sku: 'W-001', price: '25.00' },
    quantity: 2,
    unit_price: '25.00',
    line_total: '50.00',
    tax_amount: '0.00',
  },
];

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
    // Set up payment config
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    // Mock receipt creation and payment
    vi.mocked(createReceipt).mockResolvedValue({
      id: 'receipt-1',
      receipt_number: 'R-001',
      total: '50.00',
      subtotal: '50.00',
      tax_amount: '0.00',
      discount_amount: '0.00',
      currency: 'EUR',
    });

    vi.mocked(processReceiptPayments).mockResolvedValue({
      receipt: { id: 'receipt-1', receipt_number: 'R-001', total: '50.00' },
      receipt_payments: [{ id: 'rp-1', payment_method_id: 'pm-1', amount: '50.00' }],
      treasury_payments: [{ id: 'tp-1', journal_entry_id: 'je-1' }],
      change_due: '0.00',
    });

    await usePaymentStore
      .getState()
      .processCashCheckout('terminal-1', mockCartItems, 100);

    const state = usePaymentStore.getState();
    expect(state.lastReceipt).not.toBeNull();
    expect(state.lastReceipt!.receipt_number).toBe('R-001');
    expect(state.changeDue).toBe(50); // 100 tendered - 50 total
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

  it('handles checkout API errors', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    vi.mocked(createReceipt).mockRejectedValue(new Error('Server error'));

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', mockCartItems, 50),
    ).rejects.toThrow('Server error');

    expect(usePaymentStore.getState().error).toBe('Server error');
    expect(usePaymentStore.getState().isProcessing).toBe(false);
  });
});
