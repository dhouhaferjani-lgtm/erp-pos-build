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
      triggerSync: vi.fn(),
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

  it('preserves error class name in paymentStore.error when underlying error has empty message', async () => {
    // T0.1 regression: Tauri SQLite plugin can reject with Error subclasses that
    // carry a class name but empty `.message`. Old fallback collapsed this to
    // an empty string — the cashier saw "Échec du paiement" with zero clue what
    // failed and the console showed nothing because the catch was silent.
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    class SqliteBusyError extends Error {
      constructor() {
        super('');
        this.name = 'SqliteBusyError';
      }
    }
    vi.mocked(createOfflineReceipt).mockRejectedValueOnce(new SqliteBusyError());

    await expect(
      usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 100),
    ).rejects.toBeInstanceOf(SqliteBusyError);

    expect(usePaymentStore.getState().error).toContain('SqliteBusyError');
    expect(usePaymentStore.getState().isProcessing).toBe(false);
  });

  it('keeps the cashier banner opaque when checkout rejects with a non-Error string', async () => {
    // T0.1 + Codex review 2026-05-08 finding (b): Tauri IPC rejects with raw
    // strings (e.g. "error returned from database: NOT NULL constraint failed:
    // offline_receipts.payment_method_id"). Those strings can carry SQL fragments,
    // table names, file paths, or query data — content that does NOT belong in
    // the cashier UI. The banner stays opaque (generic i18n only); raw detail is
    // surfaced via console.error to devtools, not the user.
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    vi.mocked(createOfflineReceipt).mockRejectedValueOnce(
      'error returned from database: (code: 1) NOT NULL constraint failed: offline_receipts.payment_method_id',
    );

    await expect(
      usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 100),
    ).rejects.toBe(
      'error returned from database: (code: 1) NOT NULL constraint failed: offline_receipts.payment_method_id',
    );

    const banner = usePaymentStore.getState().error ?? '';
    expect(banner).not.toContain('NOT NULL constraint failed');
    expect(banner).not.toContain('offline_receipts.payment_method_id');
    expect(banner).not.toContain('payment_method_id');
    expect(usePaymentStore.getState().isProcessing).toBe(false);
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

  it('formats payment amount with 3 decimals for TND currency', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    // Switch company to TND
    useAuthStore.setState({
      companies: [{ id: 'company-1', name: 'Test Co', legalName: 'Test SA', countryCode: 'TN', currency: 'TND', locale: 'fr', timezone: 'Africa/Tunis' }],
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.000', tax_amount: '0.000' })],
    });

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 60);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        currency: 'TND',
        payments: [expect.objectContaining({ methodCode: 'CASH', amount: '50.000' })],
      }),
    );
  });

  it('card checkout writes to SQLite with card metadata in payments', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({ id: 'pm-card', code: 'CARD', is_physical: false, requires_third_party: true }),
      ],
      paymentRepositories: [
        makePaymentRepository({ id: 'repo-bank', type: 'bank_account' }),
      ],
    });

    await usePaymentStore.getState().processCardCheckout(
      'term-1',
      useCartStore.getState().items,
      { lastFour: '4242', reference: 'AUTH-XY' },
    );

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        payments: [expect.objectContaining({
          methodCode: 'CARD',
          cardLastFour: '4242',
          transactionReference: 'AUTH-XY',
        })],
      }),
    );
  });

  it('advanced split payment writes all payment lines to SQLite', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({ id: 'pm-cash', code: 'CASH' }),
        makePaymentMethod({ id: 'pm-card', code: 'CARD', is_physical: false, requires_third_party: true }),
      ],
      paymentRepositories: [
        makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
        makePaymentRepository({ id: 'repo-bank', type: 'bank_account' }),
      ],
    });

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      useCartStore.getState().items,
      [
        { payment_method_id: 'pm-cash', amount: 20, repository_id: 'repo-cash' },
        { payment_method_id: 'pm-card', amount: 30, repository_id: 'repo-bank', card_last_four: '1234', transaction_reference: 'AUTH-2' },
      ],
    );

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    const callArgs = vi.mocked(createOfflineReceipt).mock.calls[0]![1];
    expect(callArgs.payments).toHaveLength(2);
    expect(callArgs.payments[0]).toEqual(expect.objectContaining({ methodCode: 'CASH', amount: '20.00' }));
    expect(callArgs.payments[1]).toEqual(expect.objectContaining({ methodCode: 'CARD', amount: '30.00', cardLastFour: '1234' }));
  });

  it('advanced checkout throws clearly when a payment method is unknown', async () => {
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        useCartStore.getState().items,
        [{ payment_method_id: 'pm-ghost', amount: 50, repository_id: 'repo-cash' }],
      ),
    ).rejects.toThrow(/unknown payment method|method not found/i);
  });

  it('B3-followup audit (Finding 1): processAdvancedCheckout forwards instrument_type + instrument_serial into createOfflineReceipt for voucher tenders', async () => {
    // This test locks the production-path proof for B3: a voucher-bearing
    // AdvancedPaymentLine (which is what AdvancedPaymentsModal now emits when
    // paymentStore.voucherTenders is non-empty) must reach createOfflineReceipt
    // with the instrument fields populated. Without this branch the v3 fiscal
    // hash binds a null serial and the original B3 production bug re-surfaces
    // at the entry point.
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({ id: 'pm-cash', code: 'CASH' }),
        makePaymentMethod({ id: 'pm-store-voucher', code: 'store_voucher', is_physical: false, requires_third_party: false }),
      ],
      paymentRepositories: [
        makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
        makePaymentRepository({ id: 'repo-virtual', type: 'virtual' }),
      ],
    });

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      useCartStore.getState().items,
      [
        // Cash half — no instrument fields.
        { payment_method_id: 'pm-cash', amount: 25, repository_id: 'repo-cash' },
        // Voucher half — instrument fields populated, mimicking the
        // AdvancedPaymentsModal merge of paymentStore.voucherTenders.
        {
          payment_method_id: 'pm-store-voucher',
          amount: 25,
          repository_id: 'repo-virtual',
          instrument_type: 'store_voucher',
          instrument_serial: 'SV-2026-0099',
        },
      ],
    );

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    const callArgs = vi.mocked(createOfflineReceipt).mock.calls[0]![1];
    expect(callArgs.payments).toHaveLength(2);
    expect(callArgs.payments[0]).toEqual(expect.objectContaining({
      methodCode: 'CASH',
      amount: '25.00',
      instrumentType: undefined,
      instrumentSerial: undefined,
    }));
    expect(callArgs.payments[1]).toEqual(expect.objectContaining({
      methodCode: 'store_voucher',
      amount: '25.00',
      instrumentType: 'store_voucher',
      instrumentSerial: 'SV-2026-0099',
    }));
  });

  it('card checkout honors currency decimals (TND = 3 decimals)', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    useAuthStore.setState({
      ...useAuthStore.getState(),
      companies: [{ id: 'company-1', name: 'Test Co', legalName: 'Test SA', countryCode: 'TN', currency: 'TND', locale: 'fr', timezone: 'Africa/Tunis' }],
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.000', tax_amount: '0.000' })],
    });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-card', code: 'CARD', is_physical: false, requires_third_party: true })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-bank', type: 'bank_account' })],
    });

    await usePaymentStore.getState().processCardCheckout('term-1', useCartStore.getState().items, { lastFour: '1111', reference: 'X' });

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        currency: 'TND',
        payments: [expect.objectContaining({ methodCode: 'CARD', amount: '50.000' })],
      }),
    );
  });
});
