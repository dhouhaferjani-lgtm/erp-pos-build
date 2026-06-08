import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore, type Terminal } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(async () => ({
    localId: 'receipt-local-1',
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    changeDue: 0,
    fiscalHash: 'mock-hash',
    idempotencyKey: 'idem-1',
  })),
}));

vi.mock('@/lib/offline/accountPaymentService', () => ({
  createAccountPayment: vi.fn(async () => ({
    accountPaymentUuid: 'account-payment-1',
    fiscalEventId: 'fiscal-event-account-payment-1',
    receiptNumber: 'AP-T001-2026-00000001',
    total: '10.00',
    currency: 'EUR',
    fiscalHash: 'mock-account-hash',
    sequenceNumber: 1,
    canonicalBytes: '{"event_type":"ACCOUNT_PAYMENT"}',
    payload: {
      event_time_device: '2026-06-05T00:00:00.000Z',
      local_balance_snapshot: {
        projected_receivable_balance_after: '32.50',
        projected_credit_balance_after: '0.00',
        projected_net_balance_after: '32.50',
      },
    },
    printableData: null,
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

const terminalWithBranchSeller = {
  id: 'term-1',
  code: 'T001',
  name: 'Counter 1',
  type: 'fixed',
  is_active: true,
  fiscal_schema_version: 3,
  is_training_mode: false,
  hardware_identifier: null,
  location: {
    id: 'loc1',
    name: 'Main',
    code: 'MAIN',
    tax_id: 'BRANCH-FR-TAX',
    vat_number: 'FRBRANCHVAT',
    legal_identifiers: { siret: '55210055400014' },
  },
} satisfies Terminal;

function attachedCustomer(overrides: Partial<AttachedCheckoutCustomer> = {}): AttachedCheckoutCustomer {
  return {
    id: 'customer-1',
    tenant_id: 't1',
    company_id: 'company-1',
    name: 'Mariam Ben Ali',
    phone: '+216 20 100 200',
    email: null,
    tax_number: null,
    customer_category: 'retail',
    receivable_balance: '42.50',
    credit_balance: '0.00',
    credit_limit: '500.00',
    payment_terms_days: 15,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-05-21T08:00:00.000Z',
    is_active: 1,
    customer_sync_status: 'synced',
    ...overrides,
  };
}

describe('paymentStore branch seller tax source', () => {
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
      companies: [{
        id: 'company-1',
        name: 'Test Co',
        legalName: 'Test SA',
        tax_id: 'COMPANY-FR-TAX',
        countryCode: 'FR',
        address_street: '1 Rue Test',
        address_city: 'Paris',
        address_postal_code: '75001',
        currency: 'EUR',
        locale: 'fr',
        timezone: 'Europe/Paris',
      }],
      token: 'tok',
      serverUrl: 'http://localhost',
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });
    useOperatorStore.setState({
      operator: {
        id: 'op-1',
        name: 'Cashier Alice',
        email: 'a@x.com',
        roles: [],
        permissions: [],
        can_discount: false,
        max_discount_percent: null,
      },
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: true,
    });
    useTerminalStore.setState({
      terminal: terminalWithBranchSeller,
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
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })],
    });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('uses the branch tax id in SALE_RECEIPT seller payloads before company fallback', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        seller: expect.objectContaining({
          taxNumber: 'BRANCH-FR-TAX',
        }),
      }),
    );
  });

  it('falls back to the company tax id for SALE_RECEIPT seller payloads when branch tax id is null', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    useTerminalStore.setState({
      terminal: {
        ...terminalWithBranchSeller,
        location: {
          ...terminalWithBranchSeller.location,
          tax_id: null,
        },
      },
    });

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        seller: expect.objectContaining({
          taxNumber: 'COMPANY-FR-TAX',
        }),
      }),
    );
  });

  it('uses the branch tax id in ACCOUNT_PAYMENT seller payloads before company fallback', async () => {
    const { createAccountPayment } = await import('@/lib/offline/accountPaymentService');

    usePaymentStore.getState().attachCustomer(attachedCustomer());

    await usePaymentStore.getState().processAccountPayment('term-1', '10.00');

    expect(createAccountPayment).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        seller: expect.objectContaining({
          taxNumber: 'BRANCH-FR-TAX',
        }),
      }),
    );
  });

  it('falls back to the company tax id for ACCOUNT_PAYMENT seller payloads when branch tax id is null', async () => {
    const { createAccountPayment } = await import('@/lib/offline/accountPaymentService');
    useTerminalStore.setState({
      terminal: {
        ...terminalWithBranchSeller,
        location: {
          ...terminalWithBranchSeller.location,
          tax_id: null,
        },
      },
    });
    usePaymentStore.getState().attachCustomer(attachedCustomer());

    await usePaymentStore.getState().processAccountPayment('term-1', '10.00');

    expect(createAccountPayment).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        seller: expect.objectContaining({
          taxNumber: 'COMPANY-FR-TAX',
        }),
      }),
    );
  });
});
