import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePaymentStore, type AttachedCheckoutCustomer } from '../paymentStore';

// ── Mock the audit emit ──
const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({ execute: vi.fn(), select: vi.fn().mockResolvedValue([]), close: vi.fn() }),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/receiptService', () => ({ createOfflineReceipt: vi.fn() }));
vi.mock('@/lib/offline/terminalMutex', () => ({ lockTerminal: vi.fn() }));
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

// Cart session id is the Checkout aggregate id for attach/detach.
let cartSessionId: string | null = 'cart-session-abc';
vi.mock('@/stores/cartStore', () => ({
  useCartStore: { getState: () => ({ getCartSessionId: () => cartSessionId }) },
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
    credit_limit: '500.000',
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

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

describe('paymentStore — audit emits', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    cartSessionId = 'cart-session-abc';
    usePaymentStore.getState().reset();
  });

  describe('pos.voucher_double_spend_attempt (addVoucherPayment duplicate guard)', () => {
    it('emits on a re-add of an already-applied voucher and still throws', () => {
      usePaymentStore.getState().addVoucherPayment('V-100', '10.00');
      expect(recordAuditEvent).not.toHaveBeenCalled();

      expect(() => usePaymentStore.getState().addVoucherPayment('V-100', '7.50')).toThrow();

      const call = lastCallOfType('pos.voucher_double_spend_attempt')!;
      expect(call.aggregateType).toBe('Voucher');
      expect(call.aggregateId).toBe('V-100'); // voucher code is the aggregate id
      const payload = call.payload as Record<string, unknown>;
      expect(payload.attempted_amount).toBe('7.50');
      expect(payload.caught_by).toBe('idempotent_check');
      // no PAN / secret keys leaked
      expect(JSON.stringify(payload)).not.toMatch(/pan|card_number/i);
    });

    it('does NOT emit on a non-duplicate voucher add', () => {
      usePaymentStore.getState().addVoucherPayment('V-100', '10.00');
      usePaymentStore.getState().addVoucherPayment('V-200', '5.00');
      expect(lastCallOfType('pos.voucher_double_spend_attempt')).toBeUndefined();
    });

    it('still throws even if the audit emit fails', () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      usePaymentStore.getState().addVoucherPayment('V-100', '10.00');
      expect(() => usePaymentStore.getState().addVoucherPayment('V-100', '1.00')).toThrow();
    });
  });

  describe('pos.customer_attached (attachCustomer)', () => {
    it('emits with customer_id and the cart-session Checkout aggregate', () => {
      usePaymentStore.getState().attachCustomer(attached());

      const call = lastCallOfType('pos.customer_attached')!;
      expect(call.aggregateType).toBe('Checkout');
      expect(call.aggregateId).toBe('cart-session-abc');
      expect((call.payload as Record<string, unknown>).customer_id).toBe('customer-1');
    });

    it('falls back to the customer id as aggregate when no cart session exists', () => {
      cartSessionId = null;
      usePaymentStore.getState().attachCustomer(attached());

      const call = lastCallOfType('pos.customer_attached')!;
      expect(call.aggregateId).toBe('customer-1');
    });

    it('does not emit (and rethrows) when scope validation fails', () => {
      expect(() => usePaymentStore.getState().attachCustomer(attached({ tenant_id: '' }))).toThrow('tenant_id');
      expect(lastCallOfType('pos.customer_attached')).toBeUndefined();
    });

    it('an emit failure does not break attachCustomer', () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      usePaymentStore.getState().attachCustomer(attached());
      expect(usePaymentStore.getState().selectedCustomer?.id).toBe('customer-1');
    });
  });

  describe('pos.customer_detached (detachCustomer)', () => {
    it('emits customer_id and attached_duration_ms', () => {
      usePaymentStore.getState().attachCustomer(attached());
      usePaymentStore.getState().detachCustomer();

      const call = lastCallOfType('pos.customer_detached')!;
      expect(call.aggregateType).toBe('Checkout');
      expect(call.aggregateId).toBe('cart-session-abc');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.customer_id).toBe('customer-1');
      expect(typeof payload.attached_duration_ms).toBe('number');
      expect(payload.attached_duration_ms as number).toBeGreaterThanOrEqual(0);
    });

    it('does not emit when no customer was attached', () => {
      usePaymentStore.getState().detachCustomer();
      expect(lastCallOfType('pos.customer_detached')).toBeUndefined();
    });

    it('an emit failure does not break detachCustomer', () => {
      usePaymentStore.getState().attachCustomer(attached());
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      usePaymentStore.getState().detachCustomer();
      expect(usePaymentStore.getState().selectedCustomer).toBeNull();
    });
  });
});
