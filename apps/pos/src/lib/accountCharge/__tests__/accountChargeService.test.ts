import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(),
}));

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
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import type { FiscalEventTypeValue } from '@/lib/fiscal/FiscalEventPayloadRegistry';
import {
  AccountChargeInputError,
  authorAccountCharge,
  buildAccountChargePayload,
  type AuthorAccountChargeInput,
} from '../accountChargeService';

function makeMockDb() {
  const db = {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
  // Single-writer architecture: register the same mock as the gate writer so
  // BEGIN/COMMIT + tx statements land on this mock's assertion surface.
  __resetWriteGateForTesting();
  setWriter(db as unknown as SqlSurface);
  return db;
}

const appendResult = {
  id: '99999999-9999-4999-8999-999999999999',
  tenant_id: '11111111-1111-4111-8111-111111111111',
  company_id: '22222222-2222-4222-8222-222222222222',
  terminal_id: '33333333-3333-4333-8333-333333333333',
  operator_id: '44444444-4444-4444-8444-444444444444',
  event_type: 'ACCOUNT_CHARGE',
  chain_context: 'operational',
  event_version: 1,
  signature_version: 'hash-chain-integrity-v1',
  sequence_number: 7,
  event_time_device: '2026-05-21T10:15:30Z',
  business_date: '2026-05-21',
  reference_event_id: null,
  reference_document_id: null,
  source_event_class: 'account_charge',
  source_event_id: '66666666-6666-4666-8666-666666666666',
  canonical_bytes: '{"event_type":"ACCOUNT_CHARGE"}',
  previous_hash: 'a'.repeat(64),
  current_hash: 'b'.repeat(64),
  sync_status: 'pending',
  signature_status: 'not_required',
  created_at: '2026-05-21T10:15:30Z',
} as const;

function makeAppendResult(
  eventType: FiscalEventTypeValue,
  id: string,
  sequenceNumber: number,
): FiscalEventAppendResult {
  return {
    ...appendResult,
    id,
    event_type: eventType,
    sequence_number: sequenceNumber,
    canonical_bytes: `{"event_type":"${eventType}"}`,
    current_hash: String(sequenceNumber).repeat(64).slice(0, 64),
  };
}

function makeAttachedCustomer(): NonNullable<AuthorAccountChargeInput['customer']> {
  return {
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
    payment_terms_days: 30,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-default-v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-05-21T10:10:00.000Z',
    is_active: 1,
    customer_sync_status: 'synced',
  };
}

function makeAccountChargeInput(overrides: Partial<AuthorAccountChargeInput> = {}): AuthorAccountChargeInput {
  return {
    tenantId: '11111111-1111-4111-8111-111111111111',
    companyId: '22222222-2222-4222-8222-222222222222',
    terminalId: '33333333-3333-4333-8333-333333333333',
    terminalName: 'Register 1',
    operatorId: '44444444-4444-4444-8444-444444444444',
    operatorName: 'Cashier',
    shiftId: '55555555-5555-4555-8555-555555555555',
    accountChargeUuid: '66666666-6666-4666-8666-666666666666',
    currency: 'TND',
    seller: {
      name: 'AutoERP Demo SARL',
      taxNumber: '1234567AM000',
      countryCode: 'TN',
      street: '1 Avenue Habib Bourguiba',
      city: 'Tunis',
      postalCode: '1000',
    },
    customer: makeAttachedCustomer(),
    lines: [
      {
        lineUuid: '88888888-8888-4888-8888-888888888888',
        productId: 'prod-default',
        sku: 'SKU-DEFAULT',
        name: 'Default item',
        quantity: '1.000',
        unitPrice: '119.000',
        lineSubtotal: '100.000',
        lineVat: '19.000',
        lineDiscountAmount: '0.000',
        lineDiscountReason: null,
        vatRate: '19.00',
        taxCategoryCode: '',
        gtin: null,
      },
    ],
    vatBreakdown: [
      {
        netAmount: '100.000',
        vatAmount: '19.000',
        grossAmount: '119.000',
        rate: '19.00',
        taxCategoryCode: '',
      },
    ],
    subtotal: '100.000',
    vatTotal: '19.000',
    total: '119.000',
    transactionDiscountAmount: '0.000',
    transactionDiscountReason: null,
    eventTimeDevice: new Date('2026-05-21T10:15:30.000Z'),
    businessDate: '2026-05-21',
    ...overrides,
  };
}

describe('accountChargeService', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getFiscalEventEngine).mockResolvedValue({
      append: vi.fn().mockResolvedValue(appendResult),
    } as never);
  });

  it('appends ACCOUNT_CHARGE through FiscalEventEngine and returns printable data', async () => {
    const db = makeMockDb();

    const result = await authorAccountCharge(db, makeAccountChargeInput());
    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;

    expect(engine.append).toHaveBeenCalledWith(
      db,
      expect.objectContaining({
        event_type: 'ACCOUNT_CHARGE',
        tenant_id: '11111111-1111-4111-8111-111111111111',
        company_id: '22222222-2222-4222-8222-222222222222',
        terminal_id: '33333333-3333-4333-8333-333333333333',
        operator_id: '44444444-4444-4444-8444-444444444444',
        source_event_class: 'account_charge',
        source_event_id: '66666666-6666-4666-8666-666666666666',
      }),
    );
    expect(result.fiscalEvent.event_type).toBe('ACCOUNT_CHARGE');
    expect(result.printable.title).toBe('ACCOUNT CHARGE RECEIPT');
    expect(result.printable.amountChargedToAccount).toBe('119.000');
    expect(result.payload.credit_decision.credit_available_after).toBe('81.000');
    expect(db.execute).toHaveBeenNthCalledWith(1, 'BEGIN IMMEDIATE TRANSACTION');
    expect(db.execute).toHaveBeenNthCalledWith(2, 'COMMIT');
    expect(incrementPendingCountSpy).toHaveBeenCalledOnce();
    expect(triggerSyncSpy).toHaveBeenCalledOnce();
  });

  it('rejects externally supplied override evidence without local approval authoring', async () => {
    const db = makeMockDb();

    await expect(authorAccountCharge(db, makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        receivable_balance: '450.000',
      },
      overrideEvidence: {
        approval_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        approval_scope: 'credit_limit_override',
        override_event_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        policy_version: 'phase3-default-v1',
        target_account_status: 'active',
        target_amount: '119.000',
        target_customer_id: '77777777-7777-4777-8777-777777777777',
      },
    }))).rejects.toThrow(AccountChargeInputError);

    expect(db.execute).not.toHaveBeenCalled();
  });

  it('authors approval, override, and account charge events atomically for a credit-limit override', async () => {
    const db = makeMockDb();
    const approvalEvent = makeAppendResult(
      'OPERATOR_APPROVAL_GRANTED',
      'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      7,
    );
    const overrideEvent = makeAppendResult(
      'OVERRIDE_CREDIT_LIMIT',
      'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      8,
    );
    const accountChargeEvent = makeAppendResult(
      'ACCOUNT_CHARGE',
      '99999999-9999-4999-8999-999999999999',
      9,
    );
    const append = vi.fn()
      .mockResolvedValueOnce(approvalEvent)
      .mockResolvedValueOnce(overrideEvent)
      .mockResolvedValueOnce(accountChargeEvent);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({ append } as never);

    const result = await authorAccountCharge(db, makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        receivable_balance: '450.000',
      },
      overrideApproval: {
        approvalId: 'approval-1',
        approvalScope: 'credit_limit_override',
        cashierUserId: '44444444-4444-4444-8444-444444444444',
        reasonCode: 'customer_exception',
        reasonText: 'Known account in good standing',
        requestedAtDevice: new Date('2026-05-21T10:14:00.000Z'),
        resolvedAtDevice: new Date('2026-05-21T10:14:30.000Z'),
        supervisorUserId: 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        supervisorUserSnapshot: {
          display_name: 'Supervisor',
          role_names: ['Store Manager'],
        },
      },
    }));

    expect(db.execute).toHaveBeenNthCalledWith(1, 'BEGIN IMMEDIATE TRANSACTION');
    expect(db.execute).toHaveBeenNthCalledWith(2, 'COMMIT');
    expect(append).toHaveBeenCalledTimes(3);
    expect(append.mock.calls.map(([, request]) => request.event_type)).toEqual([
      'OPERATOR_APPROVAL_GRANTED',
      'OVERRIDE_CREDIT_LIMIT',
      'ACCOUNT_CHARGE',
    ]);
    expect(append.mock.calls[0]![1]).toMatchObject({
      operator_id: 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
      source_event_class: 'operator_approval',
      source_event_id: 'approval-1',
    });
    expect(append.mock.calls[1]![1]).toMatchObject({
      operator_id: 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
      reference_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      source_event_class: 'account_charge_override',
      source_event_id: 'approval-1:credit_limit_override',
    });
    expect(result.payload.credit_decision).toMatchObject({
      decision: 'approved_with_override',
      limit_exceeded: true,
      override_evidence: {
        approval_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        approval_scope: 'credit_limit_override',
        override_event_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        policy_version: 'phase3-default-v1',
        target_account_status: 'active',
        target_amount: '119.000',
        target_customer_id: '77777777-7777-4777-8777-777777777777',
      },
    });
  });

  it('authors account-status overrides for suspended customers', async () => {
    const db = makeMockDb();
    const append = vi.fn()
      .mockResolvedValueOnce(makeAppendResult(
        'OPERATOR_APPROVAL_GRANTED',
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        7,
      ))
      .mockResolvedValueOnce(makeAppendResult(
        'OVERRIDE_ACCOUNT_STATUS',
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        8,
      ))
      .mockResolvedValueOnce(makeAppendResult(
        'ACCOUNT_CHARGE',
        '99999999-9999-4999-8999-999999999999',
        9,
      ));
    vi.mocked(getFiscalEventEngine).mockResolvedValue({ append } as never);

    const result = await authorAccountCharge(db, makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        account_status: 'suspended',
      },
      overrideApproval: {
        approvalId: 'approval-2',
        approvalScope: 'account_status_override',
        cashierUserId: '44444444-4444-4444-8444-444444444444',
        reasonCode: 'temporary_hold_exception',
        reasonText: 'Approved by manager',
        requestedAtDevice: new Date('2026-05-21T10:14:00.000Z'),
        resolvedAtDevice: new Date('2026-05-21T10:14:30.000Z'),
        supervisorUserId: 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        supervisorUserSnapshot: {
          display_name: 'Supervisor',
          role_names: ['Store Manager'],
        },
      },
    }));

    expect(append.mock.calls.map(([, request]) => request.event_type)).toEqual([
      'OPERATOR_APPROVAL_GRANTED',
      'OVERRIDE_ACCOUNT_STATUS',
      'ACCOUNT_CHARGE',
    ]);
    expect(result.payload.credit_decision).toMatchObject({
      decision: 'approved_with_override',
      limit_exceeded: false,
      override_evidence: {
        approval_scope: 'account_status_override',
        target_account_status: 'suspended',
      },
    });
  });

  it('rejects any payment line before append', async () => {
    const db = makeMockDb();

    await expect(
      authorAccountCharge(db, makeAccountChargeInput({ payments: [{ amount: '119.000' }] })),
    ).rejects.toThrow(/account_charge_payments_forbidden/);

    expect(getFiscalEventEngine).not.toHaveBeenCalled();
    expect(db.execute).not.toHaveBeenCalled();
  });

  it('fails closed when credit policy rejects the charge', () => {
    expect(() =>
      buildAccountChargePayload(makeAccountChargeInput({
        customer: {
          ...makeAttachedCustomer(),
          credit_limit: '100.000',
        },
      })),
    ).toThrow(/credit_limit_exceeded/);
  });

  it('fails closed before append when the selected customer is inactive', async () => {
    const db = makeMockDb();

    await expect(
      authorAccountCharge(db, makeAccountChargeInput({
        customer: {
          ...makeAttachedCustomer(),
          is_active: 0,
        },
      })),
    ).rejects.toThrow(/account_charge_credit_rejected:customer_inactive/);

    expect(getFiscalEventEngine).not.toHaveBeenCalled();
    expect(db.execute).not.toHaveBeenCalled();
  });

  it('classifies business customers as B2B facture draft requests', () => {
    const payload = buildAccountChargePayload(makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        customer_category: 'business',
      },
    }));

    expect(payload.invoice_classification).toBe('b2b_facture_draft_requested');
  });

  it('classifies non-business customers as B2C charge receipts', () => {
    const payload = buildAccountChargePayload(makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        customer_category: 'retail',
      },
    }));

    expect(payload.invoice_classification).toBe('b2c_charge_receipt');
  });

  it('lets existing credit balance offset a new charge in decision and snapshot math', () => {
    const payload = buildAccountChargePayload(makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        receivable_balance: '0.000',
        credit_balance: '100.000',
        credit_limit: '500.000',
      },
      total: '550.000',
      subtotal: '550.000',
      vatTotal: '0.000',
      lines: [{
        ...makeAccountChargeInput().lines[0]!,
        lineSubtotal: '550.000',
        lineVat: '0.000',
      }],
      vatBreakdown: [{
        netAmount: '550.000',
        vatAmount: '0.000',
        grossAmount: '550.000',
        rate: '0.00',
        taxCategoryCode: '',
      }],
    }));

    expect(payload.credit_decision.credit_available_after).toBe('50.000');
    expect(payload.local_balance_snapshot.projected_receivable_balance_after).toBe('550.000');
    expect(payload.local_balance_snapshot.projected_credit_balance_after).toBe('100.000');
    expect(payload.local_balance_snapshot.projected_net_balance_after).toBe('450.000');
  });

  it('clamps projected net to zero when existing credit fully offsets the charge', () => {
    const payload = buildAccountChargePayload(makeAccountChargeInput({
      customer: {
        ...makeAttachedCustomer(),
        receivable_balance: '0.000',
        credit_balance: '100.000',
        credit_limit: '500.000',
      },
      total: '50.000',
      subtotal: '50.000',
      vatTotal: '0.000',
      lines: [{
        ...makeAccountChargeInput().lines[0]!,
        lineSubtotal: '50.000',
        lineVat: '0.000',
        unitPrice: '50.000',
        vatRate: '0.00',
      }],
      vatBreakdown: [{
        netAmount: '50.000',
        vatAmount: '0.000',
        grossAmount: '50.000',
        rate: '0.00',
        taxCategoryCode: '',
      }],
    }));

    expect(payload.credit_decision.credit_available_after).toBe('500.000');
    expect(payload.local_balance_snapshot.projected_receivable_balance_after).toBe('50.000');
    expect(payload.local_balance_snapshot.projected_credit_balance_after).toBe('100.000');
    expect(payload.local_balance_snapshot.projected_net_balance_after).toBe('0.000');
  });

  it('requires a selected customer', () => {
    expect(() =>
      buildAccountChargePayload(makeAccountChargeInput({ customer: null })),
    ).toThrow(AccountChargeInputError);
  });
});
