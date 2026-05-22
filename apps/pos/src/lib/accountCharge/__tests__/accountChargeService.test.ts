import { beforeEach, describe, expect, it, vi } from 'vitest';

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
import {
  AccountChargeInputError,
  authorAccountCharge,
  buildAccountChargePayload,
  type AuthorAccountChargeInput,
} from '../accountChargeService';

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
  event_type: 'ACCOUNT_CHARGE',
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
        unitPrice: '100.000',
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
    expect(db.execute).toHaveBeenNthCalledWith(1, 'BEGIN TRANSACTION');
    expect(db.execute).toHaveBeenNthCalledWith(2, 'COMMIT');
    expect(incrementPendingCountSpy).toHaveBeenCalledOnce();
    expect(triggerSyncSpy).toHaveBeenCalledOnce();
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

  it('requires a selected customer', () => {
    expect(() =>
      buildAccountChargePayload(makeAccountChargeInput({ customer: null })),
    ).toThrow(AccountChargeInputError);
  });
});
