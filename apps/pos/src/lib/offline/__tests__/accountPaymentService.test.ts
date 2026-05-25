import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(),
}));

vi.mock('@/lib/db/repositories/pendingCustomerRepository', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/db/repositories/pendingCustomerRepository')>();
  return {
    ...actual,
    assertCustomerAliasMatches: vi.fn().mockResolvedValue(undefined),
  };
});

const incrementPendingCountSpy = vi.fn();
const triggerSyncSpy = vi.fn().mockResolvedValue(undefined);
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({
      incrementPendingCount: incrementPendingCountSpy,
      triggerSync: triggerSyncSpy,
    }),
  },
}));

import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { assertCustomerAliasMatches, StaleCustomerAliasConflictError } from '@/lib/db/repositories/pendingCustomerRepository';
import {
  AccountPaymentInputError,
  buildAccountPaymentPayload,
  createAccountPayment,
  type CreateAccountPaymentInput,
} from '../accountPaymentService';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

const appendResult = {
  id: '99999999-9999-4999-8999-999999999999',
  tenant_id: '11111111-1111-4111-8111-111111111111',
  company_id: '22222222-2222-4222-8222-222222222222',
  terminal_id: '33333333-3333-4333-8333-333333333333',
  operator_id: '44444444-4444-4444-8444-444444444444',
  event_type: 'ACCOUNT_PAYMENT',
  event_version: 1,
  signature_version: 'hash-chain-integrity-v1',
  sequence_number: 7,
  event_time_device: '2026-05-21T10:15:30Z',
  business_date: '2026-05-21',
  reference_event_id: null,
  reference_document_id: null,
  source_event_class: 'account_payments',
  source_event_id: '55555555-5555-4555-8555-555555555555',
  canonical_bytes: '{"event_type":"ACCOUNT_PAYMENT"}',
  previous_hash: 'a'.repeat(64),
  current_hash: 'b'.repeat(64),
  sync_status: 'pending',
  signature_status: 'not_required',
  created_at: '2026-05-21T10:15:30Z',
} as const;

const baseInput: CreateAccountPaymentInput = {
  tenantId: '11111111-1111-4111-8111-111111111111',
  companyId: '22222222-2222-4222-8222-222222222222',
  terminalId: '33333333-3333-4333-8333-333333333333',
  terminalName: 'Register 1',
  operatorId: '44444444-4444-4444-8444-444444444444',
  operatorName: 'Cashier',
  shiftId: '66666666-6666-4666-8666-666666666666',
  currency: 'TND',
  seller: {
    name: 'AutoERP Demo SARL',
    taxNumber: '1234567AM000',
    countryCode: 'TN',
    street: '1 Avenue Habib Bourguiba',
    city: 'Tunis',
    postalCode: '1000',
  },
  customer: {
    id: '77777777-7777-4777-8777-777777777777',
    tenant_id: '11111111-1111-4111-8111-111111111111',
    company_id: '22222222-2222-4222-8222-222222222222',
    name: 'Mariam Ben Ali',
    phone: '+21611111111',
    email: null,
    tax_number: null,
    customer_category: 'retail',
    receivable_balance: '300.000',
    credit_balance: '0.000',
    credit_limit: '500.000',
    payment_terms_days: 15,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-05-21T10:10:00.000Z',
    is_active: 1,
    customer_sync_status: 'synced',
  },
  payment: {
    amount: '100',
    methodCode: 'CASH',
    repositoryId: '88888888-8888-4888-8888-888888888888',
  },
  eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
  businessDate: '2026-05-21',
};

describe('accountPaymentService', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(crypto, 'randomUUID').mockReturnValue('55555555-5555-4555-8555-555555555555');
    vi.mocked(getFiscalEventEngine).mockResolvedValue({
      append: vi.fn().mockResolvedValue(appendResult),
    } as never);
  });

  it('appends ACCOUNT_PAYMENT through FiscalEventEngine and returns printable data', async () => {
    const db = makeMockDb();

    const result = await createAccountPayment(db, baseInput);
    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;

    expect(engine.append).toHaveBeenCalledWith(
      db,
      expect.objectContaining({
        event_type: 'ACCOUNT_PAYMENT',
        tenant_id: baseInput.tenantId,
        company_id: baseInput.companyId,
        terminal_id: baseInput.terminalId,
        operator_id: baseInput.operatorId,
        event_time_device: '2026-05-21T10:15:30Z',
        business_date: '2026-05-21',
        source_event_class: 'account_payments',
        source_event_id: '55555555-5555-4555-8555-555555555555',
      }),
    );
    const request = vi.mocked(engine.append).mock.calls[0]![1];
    expect(request.payload).toMatchObject({
      receipt_type_code: 'ACCOUNT_PAYMENT',
      account_payment_uuid: '55555555-5555-4555-8555-555555555555',
      payment: { amount: '100.000', method_code: 'CASH' },
      local_balance_snapshot: {
        net_balance_before: '300.000',
        projected_net_balance_after: '200.000',
      },
    });
    expect(db.execute).toHaveBeenNthCalledWith(1, 'BEGIN TRANSACTION');
    expect(db.execute).toHaveBeenNthCalledWith(2, 'COMMIT');
    expect(incrementPendingCountSpy).toHaveBeenCalledOnce();
    expect(triggerSyncSpy).toHaveBeenCalledOnce();
    expect(result.printableData.receipt_kind).toBe('account_payment');
    expect(result.printableData.customer_name).toBe('Mariam Ben Ali');
    expect(result.printableData.account_balance_before).toBe('300.000');
    expect(result.printableData.account_balance_after).toBe('200.000');
  });

  it('rejects zero amount outside training mode', () => {
    expect(() =>
      buildAccountPaymentPayload({
        ...baseInput,
        accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
        eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
        payment: { ...baseInput.payment, amount: '0' },
      }),
    ).toThrow(AccountPaymentInputError);
  });

  it('allows zero amount in training mode', () => {
    const payload = buildAccountPaymentPayload({
      ...baseInput,
      accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
      eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
      isTraining: true,
      payment: { ...baseInput.payment, amount: '0' },
    });

    expect(payload.training_flag).toBe(true);
    expect(payload.payment.amount).toBe('0.000');
  });

  it('records stale balance metadata in canonical payload', () => {
    const payload = buildAccountPaymentPayload({
      ...baseInput,
      accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
      eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
      balanceSnapshotStale: true,
    });

    expect(payload.staleness).toEqual({
      balance_snapshot_stale: true,
      customer_snapshot_stale: false,
      mirror_last_synced_at: '2026-05-21T10:10:00.000Z',
      staleness_reason: 'older_than_threshold',
    });
  });

  it('normalizes TN seller tax numbers to compact canonical form', () => {
    const payload = buildAccountPaymentPayload({
      ...baseInput,
      accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
      eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
      seller: {
        ...baseInput.seller,
        taxNumber: '1234567/A/M/000',
      },
    });

    expect(payload.seller.tax_number).toBe('1234567AM000');
  });

  it('normalizes TN customer tax numbers to compact canonical form', () => {
    const payload = buildAccountPaymentPayload({
      ...baseInput,
      accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
      eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
      customer: {
        ...baseInput.customer,
        tax_number: '7654321/B/M/000',
      },
    });

    expect(payload.customer.tax_number).toBe('7654321BM000');
  });

  it('rejects a customer row from another company', () => {
    expect(() =>
      buildAccountPaymentPayload({
        ...baseInput,
        accountPaymentUuid: '55555555-5555-4555-8555-555555555555',
        eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
        customer: { ...baseInput.customer, company_id: 'other-company' },
      }),
    ).toThrow(/different tenant or company/);
  });

  it('rejects stale alias conflicts before sealing', async () => {
    const db = makeMockDb();
    vi.mocked(assertCustomerAliasMatches).mockRejectedValueOnce(
      new StaleCustomerAliasConflictError(
        baseInput.tenantId,
        baseInput.companyId,
        '77777777-7777-4777-8777-777777777777',
        'server-partner-1',
        'server-partner-2',
      ),
    );

    await expect(
      createAccountPayment(db, {
        ...baseInput,
        customer: {
          ...baseInput.customer,
          customer_sync_status: 'pending_create',
        },
        expectedServerCustomerAliasId: 'server-partner-2',
      }),
    ).rejects.toBeInstanceOf(StaleCustomerAliasConflictError);

    expect(getFiscalEventEngine).not.toHaveBeenCalled();
    expect(db.execute).not.toHaveBeenCalled();
  });
});
