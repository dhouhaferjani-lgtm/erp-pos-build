/**
 * Bug 2 — cash checkout must store the cashier's TENDERED amount in
 * `pos_receipt_payments.amount`, not the cart total. Backend contract
 * documented at apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php:30-40
 *
 *     expected_cash = opening_float
 *                   + Σ(pos_receipt_payments.amount for cash payments)
 *                   − Σ(change_due)
 *                   − Σ(refunds)
 *
 * Pre-fix the POS sent amount = cart total, so for a €100 receipt with
 * €120 tendered + €20 change, expected_cash would compute as
 * `opening + 100 − 20 = opening + 80` instead of `opening + 120 − 20 =
 * opening + 100`, surfacing a phantom €20 shortage. The print path also
 * derived change as `Σ(payments.amount) − total = 100 − 100 = 0`, which
 * is Bug 2's headline symptom ("Monnaie rendue: 0" on every receipt).
 */

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

describe('paymentStore — cash payment amount is tendered (Bug 2)', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Houssem',
        email: 'h@example.com',
        tenantId: 't1',
        phone: null,
        status: 'active',
        locale: null,
        timezone: null,
        roles: [],
        permissions: [],
        emailVerified: true,
      },
      companyId: 'company-1',
      companies: [
        {
          id: 'company-1',
          name: 'Test Co',
          legalName: 'Test SA',
          tax_id: '123456789',
          countryCode: 'FR',
          address_street: '1 Rue Test',
          address_city: 'Paris',
          address_postal_code: '75001',
          currency: 'EUR',
          locale: 'fr',
          timezone: 'Europe/Paris',
        },
      ],
      token: 'tok',
      serverUrl: 'http://localhost',
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });
    useOperatorStore.setState({
      operator: { id: 'op-1', name: 'Cashier Alice', email: 'alice@example.com', roles: [] },
    } as never);
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
        id: 'shift-1',
        terminal_id: 'term-1',
        shift_number: 1,
        status: 'OPEN',
        opening_cash: '0.00',
        opened_at: '2026-05-20T08:00:00Z',
        user: { id: 'user-1', name: 'Houssem' },
      },
      hashChainReady: true,
    } as never);
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })],
    });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('over-tender: stores tenderedAmount (NOT cart total) in payments[0].amount', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    // Cart total = 50; cashier tendered 120; change = 70.
    await usePaymentStore
      .getState()
      .processCashCheckout('term-1', useCartStore.getState().items, 120);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        payments: [
          expect.objectContaining({
            methodCode: 'CASH',
            // BUG 2 FIX: amount must be tendered (120.00), NOT total (50.00).
            amount: '120.00',
          }),
        ],
      }),
    );
  });

  it('exact-tender: stores tenderedAmount (== cart total) in payments[0].amount', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    // Cart total = 50; cashier tendered 50; change = 0.
    await usePaymentStore
      .getState()
      .processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        payments: [
          expect.objectContaining({
            methodCode: 'CASH',
            amount: '50.00',
          }),
        ],
      }),
    );
  });

  it('uses currency decimals from auth store (TND scale 3)', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    // Switch company to TND so currency decimals = 3.
    useAuthStore.setState({
      companies: [
        {
          id: 'company-1',
          name: 'Test Co',
          legalName: 'Test SA',
          tax_id: 'TN1234567',
          countryCode: 'TN',
          address_street: '1 Avenue Test',
          address_city: 'Tunis',
          address_postal_code: '1000',
          currency: 'TND',
          locale: 'fr',
          timezone: 'Africa/Tunis',
        },
      ],
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.000', tax_amount: '0.000' })],
    });

    await usePaymentStore
      .getState()
      .processCashCheckout('term-1', useCartStore.getState().items, 60);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        payments: [expect.objectContaining({ methodCode: 'CASH', amount: '60.000' })],
      }),
    );
  });
});
