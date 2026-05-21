import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePaymentStore, type AttachedCheckoutCustomer } from '../paymentStore';

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
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

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/terminalMutex', () => ({
  lockTerminal: vi.fn(),
}));

vi.mock('@/lib/fiscal/FiscalEventEngine', () => ({
  ConcurrentChainAdvanceError: class ConcurrentChainAdvanceError extends Error {},
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      serverUrl: 'http://localhost',
      token: 'test-token',
      user: { id: 'user-1', name: 'Test User', tenantId: 'tenant-1' },
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: { getState: vi.fn().mockReturnValue({ operator: null }) },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: vi.fn().mockReturnValue({ terminal: null, shift: null }) },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: { getState: vi.fn().mockReturnValue({ triggerSync: vi.fn() }) },
}));

function attached(overrides: Partial<AttachedCheckoutCustomer> = {}): AttachedCheckoutCustomer {
  return {
    id: 'customer-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    name: 'Mariam Ben Ali',
    phone: '+216 20 100 200',
    email: null,
    tax_number: null,
    customer_category: 'retail',
    receivable_balance: '42.500',
    credit_balance: '0.000',
    balance_updated_at: '2026-05-21T08:00:00.000Z',
    customer_sync_status: 'synced',
    ...overrides,
  };
}

describe('paymentStore customer attach state', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
  });

  it('attaches and detaches a checkout customer', () => {
    usePaymentStore.getState().attachCustomer(attached());

    expect(usePaymentStore.getState().selectedCustomer).toMatchObject({
      id: 'customer-1',
      customer_sync_status: 'synced',
    });

    usePaymentStore.getState().detachCustomer();

    expect(usePaymentStore.getState().selectedCustomer).toBeNull();
  });

  it('fails loudly when customer scope is missing', () => {
    expect(() => {
      usePaymentStore.getState().attachCustomer(attached({ tenant_id: '' }));
    }).toThrow('tenant_id');
  });

  it('clears attached customer on reset and new-sale receipt clear', () => {
    usePaymentStore.getState().attachCustomer(attached());
    usePaymentStore.getState().clearLastReceipt();
    expect(usePaymentStore.getState().selectedCustomer).toBeNull();

    usePaymentStore.getState().attachCustomer(attached());
    usePaymentStore.getState().reset();
    expect(usePaymentStore.getState().selectedCustomer).toBeNull();
  });
});
