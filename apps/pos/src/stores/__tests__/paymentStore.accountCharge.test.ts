import { describe, it, expect, vi, beforeEach } from 'vitest';
import { makeCartItem } from '@/test/helpers';

// ─── Mocks ──────────────────────────────────────────────────────────────────
//
// Mirrors the established harness from paymentStore.offlineFirst.test.ts: the
// account-charge authoring path is mocked at its boundary
// (`authorAccountCharge`), `getDatabase` returns a stub Database, the ESC/POS
// receipt builder is stubbed to a sentinel, and the identity stores are seeded
// with a tenant/company-matched eligible customer + a single-line cart in
// `beforeEach`. `isBalanceStale` is stubbed so the test does not depend on the
// real staleness clock.

vi.mock('@/lib/accountCharge/accountChargeService', () => ({
  authorAccountCharge: vi.fn(),
}));

// Spread the real module so sibling exports (e.g.
// `buildEscPosAccountPaymentReceiptData`, imported transitively by
// accountPaymentService) keep working; only override the charge builder with
// a spy. The spy is declared inside the factory (the factory is hoisted above
// top-level consts) and retrieved via `vi.mocked` after import.
vi.mock('@/lib/buildReceiptData', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/buildReceiptData')>()),
  buildEscPosAccountChargeReceiptData: vi.fn(() => ({
    receipt_number: 'ac-1',
    payments: [],
  })),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({ execute: vi.fn(), select: vi.fn() })),
}));

vi.mock('@/lib/db/repositories/customerRepository', () => ({
  isBalanceStale: vi.fn(() => false),
}));

// These imports are only used by other paymentStore actions but must be mocked
// so importing the store does not pull in heavy real implementations.
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { buildEscPosAccountChargeReceiptData as buildEscPosAccountChargeReceiptDataImport } from '@/lib/buildReceiptData';
import { authorAccountCharge as authorAccountChargeImport } from '@/lib/accountCharge/accountChargeService';
import { isBalanceStale as isBalanceStaleImport } from '@/lib/db/repositories/customerRepository';

const buildEscPosAccountChargeReceiptData = vi.mocked(buildEscPosAccountChargeReceiptDataImport);
const authorAccountCharge = vi.mocked(authorAccountChargeImport);
const isBalanceStale = vi.mocked(isBalanceStaleImport);

const SUCCESS_RESULT = {
  accountChargeUuid: 'ac-1',
  fiscalEventId: 'fe-1',
  total: '119.000',
  currency: 'TND',
  fiscalHash: 'h',
  sequenceNumber: 1,
  canonicalBytes: '{}',
  payload: {
    event_time_device: '2026-06-04T10:00:00.000Z',
    local_balance_snapshot: {
      projected_receivable_balance_after: '419.000',
      projected_credit_balance_after: '0.000',
    },
  },
  printable: { title: 'ACCOUNT CHARGE RECEIPT' },
  fiscalEvent: { id: 'fe-1' },
};

function eligibleCustomer(): AttachedCheckoutCustomer {
  return {
    id: 'cust-1',
    tenant_id: 't1',
    company_id: 'company-1',
    name: 'Acme Garage',
    phone: null,
    email: null,
    tax_number: null,
    customer_category: 'business',
    receivable_balance: '300.000',
    credit_balance: '0.000',
    credit_limit: '1000.000',
    payment_terms_days: 30,
    charge_account_enabled: true,
    charge_policy_version: 'v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-06-04T09:00:00.000Z',
    is_active: true,
    customer_sync_status: 'synced',
  };
}

describe('processAccountCharge', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authorAccountCharge.mockReset();
    authorAccountCharge.mockResolvedValue(SUCCESS_RESULT as never);
    isBalanceStale.mockReturnValue(false);
    buildEscPosAccountChargeReceiptData.mockReturnValue({ receipt_number: 'ac-1', payments: [] } as never);

    usePaymentStore.getState().reset();
    usePaymentStore.setState({ selectedCustomer: eligibleCustomer() });

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
      token: 'tok',
      serverUrl: 'http://localhost',
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    } as never);

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
        opening_cash: '0.000',
        opened_at: '2026-06-04T08:00:00Z',
        user: { id: 'user-1', name: 'Houssem' },
      },
      hashChainReady: true,
    } as never);

    useCartStore.setState({
      items: [makeCartItem({ line_total: '119.000', tax_amount: '19.000' })],
      transactionDiscount: undefined,
    } as never);
  });

  it('authors a charge with no payment lines, sets print data, clears cart, updates balance', async () => {
    const result = await usePaymentStore.getState().processAccountCharge('term-1');

    expect(result?.fiscalEventId).toBe('fe-1');
    expect(authorAccountCharge).toHaveBeenCalledOnce();

    const callArg = authorAccountCharge.mock.calls[0]![1];
    expect(callArg.payments).toBeUndefined();
    expect(callArg.overrideApproval).toBeNull();

    expect(buildEscPosAccountChargeReceiptData).toHaveBeenCalledWith({
      payload: SUCCESS_RESULT.printable,
      currencyCode: 'TND',
    });

    const state = usePaymentStore.getState();
    expect(state.lastReceiptPrintData).not.toBeNull();
    expect(state.lastReceipt?.receipt_number).toBe('ac-1');
    expect(state.selectedCustomer?.receivable_balance).toBe('419.000');
    expect(state.selectedCustomer?.credit_balance).toBe('0.000');
    expect(state.selectedCustomer?.balance_updated_at).toBe('2026-06-04T10:00:00.000Z');
    expect(state.isProcessing).toBe(false);
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('does not author when no eligible customer is attached', async () => {
    usePaymentStore.setState({ selectedCustomer: null });

    await expect(
      usePaymentStore.getState().processAccountCharge('term-1'),
    ).rejects.toThrow();
    expect(authorAccountCharge).not.toHaveBeenCalled();
    expect(usePaymentStore.getState().error).not.toBeNull();
  });

  it('does not author when the attached customer is not charge-enabled', async () => {
    usePaymentStore.setState({
      selectedCustomer: { ...eligibleCustomer(), charge_account_enabled: false },
    });

    await expect(
      usePaymentStore.getState().processAccountCharge('term-1'),
    ).rejects.toThrow();
    expect(authorAccountCharge).not.toHaveBeenCalled();
  });

  it('does not author when charge_account_enabled is the numeric-falsy 0', async () => {
    usePaymentStore.setState({
      selectedCustomer: { ...eligibleCustomer(), charge_account_enabled: 0 },
    });

    await expect(
      usePaymentStore.getState().processAccountCharge('term-1'),
    ).rejects.toThrow();
    expect(authorAccountCharge).not.toHaveBeenCalled();
  });

  it('passes overrideApproval through to the service', async () => {
    const override = {
      approvalId: 'a',
      approvalScope: 'credit_limit_override' as const,
      cashierUserId: 'c',
      reasonCode: 'r',
      reasonText: null,
      requestedAtDevice: new Date(),
      resolvedAtDevice: new Date(),
      supervisorUserId: 's',
      supervisorUserSnapshot: {},
    };

    await usePaymentStore
      .getState()
      .processAccountCharge('term-1', { overrideApproval: override });

    expect(authorAccountCharge.mock.calls[0]![1].overrideApproval).toBe(override);
  });
});
