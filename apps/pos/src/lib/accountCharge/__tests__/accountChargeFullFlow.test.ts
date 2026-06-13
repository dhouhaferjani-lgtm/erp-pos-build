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
import {
  authorAccountCharge,
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

describe('accountCharge full flow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.unstubAllGlobals();
  });

  it('authors ACCOUNT_CHARGE locally and queues fiscal-event sync only', async () => {
    const db = makeMockDb();
    const appendSpy = vi.fn().mockResolvedValue(appendResult);
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({ append: appendSpy } as never);

    const result = await authorAccountCharge(db, makeAccountChargeInput());

    expect(result.fiscalEvent.event_type).toBe('ACCOUNT_CHARGE');
    expect(appendSpy).toHaveBeenCalledWith(
      db,
      expect.objectContaining({
        event_type: 'ACCOUNT_CHARGE',
        source_event_class: 'account_charge',
        source_event_id: '66666666-6666-4666-8666-666666666666',
      }),
    );
    expect(incrementPendingCountSpy).toHaveBeenCalledOnce();
    expect(triggerSyncSpy).toHaveBeenCalledOnce();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('does not append or sync when credit limit is insufficient', async () => {
    const db = makeMockDb();
    const appendSpy = vi.fn().mockResolvedValue(appendResult);
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({ append: appendSpy } as never);

    await expect(
      authorAccountCharge(db, makeAccountChargeInput({
        customer: {
          ...makeAttachedCustomer(),
          credit_limit: '100.000',
        },
      })),
    ).rejects.toThrow(/account_charge_credit_rejected:credit_limit_exceeded/);

    expect(getFiscalEventEngine).not.toHaveBeenCalled();
    expect(appendSpy).not.toHaveBeenCalled();
    expect(incrementPendingCountSpy).not.toHaveBeenCalled();
    expect(triggerSyncSpy).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('does not append or sync when the balance snapshot is hard-stale', async () => {
    const db = makeMockDb();
    const appendSpy = vi.fn().mockResolvedValue(appendResult);
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({ append: appendSpy } as never);

    await expect(
      authorAccountCharge(db, makeAccountChargeInput({
        customer: {
          ...makeAttachedCustomer(),
          balance_updated_at: '2026-05-21T07:00:00.000Z',
        },
        hardStaleAfterMinutes: 60,
      })),
    ).rejects.toThrow(/account_charge_credit_rejected:balance_snapshot_hard_stale/);

    expect(getFiscalEventEngine).not.toHaveBeenCalled();
    expect(appendSpy).not.toHaveBeenCalled();
    expect(incrementPendingCountSpy).not.toHaveBeenCalled();
    expect(triggerSyncSpy).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
