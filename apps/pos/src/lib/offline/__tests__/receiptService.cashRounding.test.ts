/**
 * Task 9 — `receiptService` authors SALE_RECEIPT v3 from the SEALED checkout
 * snapshot.
 *
 * The snapshot is built ONCE at tender time (`buildCheckoutPolicySnapshot`) and
 * is the only thing that decides what gets signed: the rounded total, the
 * signed adjustment, the denomination and the tolerance outcome. These tests
 * pin that the SIGNED payload and the `offline_receipts` mirror both come from
 * the snapshot and never from re-derived live state.
 *
 * The engine is mocked (same harness as `receiptService.test.ts`), so what is
 * asserted here is the payload BUILT, not the payload validated — the append-time
 * key-set validation is pinned by `FiscalEventEngine.test.ts` and the v3 binds by
 * `SaleReceiptV3Payload.test.ts`.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn(),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getReceiptByIdempotencyKey: vi.fn().mockResolvedValue(null),
  insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
  scheduleDebouncedSync: vi.fn(),
}));

vi.mock('@/lib/offline/voucherRepository', () => ({
  findByCode: vi.fn().mockResolvedValue(null),
  updateVoucherBalanceAndStatus: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({ incrementPendingCount: vi.fn() }),
  },
}));

import { createOfflineReceipt, CartTotalIntegrityError } from '../receiptService';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import {
  buildCheckoutPolicySnapshot,
  type CheckoutPolicySnapshot,
} from '@/lib/payment/checkoutPolicySnapshot';
import type { PaymentPolicy } from '@/stores/paymentPolicyStore';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';
import type { SaleReceiptV3PayloadInput } from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import { makeCartItem } from '@/test/helpers';

function makeMockDb(): import('@tauri-apps/plugin-sql').default {
  const db = {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
  __resetWriteGateForTesting();
  setWriter(db as unknown as SqlSurface);
  return db;
}

const terminalState = {
  terminal_id: '4f0d7e79-6ef6-46b4-85ea-f0c339f3f0db',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'a'.repeat(64),
  last_hash: 'b'.repeat(64),
  hash_sequence: 5,
  manager_pin_throttle_until: null,
  manager_pin_failed_attempts: 0,
  fiscal_schema_version: 3 as const,
};

const seller = {
  name: 'AutoERP Demo SARL',
  taxNumber: '1234567/A/M/000',
  countryCode: 'TN',
  street: '1 Avenue Habib Bourguiba',
  city: 'Tunis',
  postalCode: '1000',
};

const tndPolicy: PaymentPolicy = {
  cashRoundingEnabled: true,
  cashRoundingDenomination: '0.050',
  tenderToleranceEnabled: true,
  tenderTolerancePercentage: '0',
  tenderToleranceMaxAmount: '0',
  currencyCode: 'TND',
  currencyScale: 3,
  refreshedAt: '2026-07-29 08:00:00',
};

/**
 * Real snapshots from the real builder — never hand-written object literals.
 * A fixture that disagreed with the builder would pin the wrong contract, and
 * the whole point of the snapshot is that ONE function decides the rounding.
 */
function snapshotFor(input: {
  exactTotal: string;
  tenderedAmount: string;
  fiscalSchemaVersion: number | null;
  policy?: PaymentPolicy | null;
}): CheckoutPolicySnapshot {
  return buildCheckoutPolicySnapshot({
    exactTotal: input.exactTotal,
    currency: 'TND',
    legs: [{ methodCode: 'CASH', amount: input.tenderedAmount }],
    tenderedAmount: input.tenderedAmount,
    isCashMethodCode: (code) => code === 'CASH',
    policy: input.policy === undefined ? tndPolicy : input.policy,
    fiscalSchemaVersion: input.fiscalSchemaVersion,
    invoiceType: 'SALE',
    autoAcceptCountThisShift: 0,
  });
}

function makeInput(overrides: {
  cartItems: ReturnType<typeof makeCartItem>[];
  tenderedAmount: string;
  policySnapshot: CheckoutPolicySnapshot;
  payments: Array<{ methodCode: string; amount: string }>;
}): Parameters<typeof createOfflineReceipt>[1] {
  return {
    tenantId: '11111111-1111-4111-8111-111111111111',
    companyId: '22222222-2222-4222-8222-222222222222',
    terminalId: terminalState.terminal_id,
    operatorId: '33333333-3333-4333-8333-333333333333',
    operatorName: 'Cashier',
    shiftId: '55555555-5555-4555-8555-555555555555',
    currency: 'TND',
    seller,
    paymentMethodId: 'pm-1',
    paymentRepositoryId: 'repo-1',
    idempotencyKey: '66666666-6666-4666-8666-666666666666',
    ...overrides,
  };
}

async function appendedPayload(): Promise<SaleReceiptV3PayloadInput> {
  // `getFiscalEventEngine` is async, so the recorded result value is the
  // Promise, not the engine.
  const engine = await (vi.mocked(getFiscalEventEngine).mock.results[0]!.value as Promise<{
    append: ReturnType<typeof vi.fn>;
  }>);
  return engine.append.mock.calls[0]![1].payload as SaleReceiptV3PayloadInput;
}

describe('createOfflineReceipt — v3 rounding from the sealed snapshot', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
    vi.mocked(getFiscalEventEngine).mockResolvedValue({
      append: vi.fn().mockResolvedValue({
        id: '8a28b902-1204-4a17-a121-51ea17034ee2',
        tenant_id: '11111111-1111-4111-8111-111111111111',
        company_id: '22222222-2222-4222-8222-222222222222',
        terminal_id: terminalState.terminal_id,
        operator_id: '33333333-3333-4333-8333-333333333333',
        event_type: 'SALE_RECEIPT',
        event_version: 3,
        signature_version: 'hash-chain-integrity-v1',
        sequence_number: 6,
        event_time_device: '2026-07-29T10:00:00Z',
        business_date: '2026-07-29',
        reference_event_id: null,
        reference_document_id: null,
        source_event_class: 'offline_receipts',
        source_event_id: '44444444-4444-4444-8444-444444444444',
        canonical_bytes: '{"business_date":"2026-07-29"}',
        previous_hash: 'a'.repeat(64),
        current_hash: 'c'.repeat(64),
        sync_status: 'pending',
        signature_status: 'not_required',
        created_at: '2026-07-29T10:00:00Z',
      }),
    } as never);
  });

  it('signs the rounded total and persists the three mirror columns', async () => {
    const db = makeMockDb();
    const roundedSnapshot = snapshotFor({
      exactTotal: '9.997',
      tenderedAmount: '10.000',
      fiscalSchemaVersion: 3,
    });
    // Guard the fixture itself: if the builder ever stops rounding this cart,
    // the assertions below would pass vacuously.
    expect(roundedSnapshot.roundingApplied).toBe(true);
    expect(roundedSnapshot.roundedTotal).toBe('10.000');

    const result = await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({
        id: 'i1',
        unit_price: '9.997',
        line_total: '9.997',
        tax_rate: '0',
        tax_amount: '0.000',
      })],
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: roundedSnapshot,
    }));

    expect(result.total).toBe('10.000');
    const payload = await appendedPayload();
    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('0.003');
    expect(payload.cash_rounding_denomination).toBe('0.050');

    const row = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    expect(row.total).toBe('10.000');
    expect(row.cash_rounding_adjustment).toBe('0.003');
    expect(row.cash_rounding_denomination).toBe('0.050');
    expect(row.tolerance_shortfall).toBeNull();
  });

  it('nets change against the ROUNDED due, not the exact total', async () => {
    const db = makeMockDb();
    // Exact 9.973 rounds DOWN to 9.950; a 10.000 tender owes 0.050 change,
    // not the 0.027 the exact total would imply. What the cashier is told must
    // be what was signed.
    const roundedSnapshot = snapshotFor({
      exactTotal: '9.973',
      tenderedAmount: '10.000',
      fiscalSchemaVersion: 3,
    });
    expect(roundedSnapshot.roundedTotal).toBe('9.950');

    const result = await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({
        id: 'i1',
        unit_price: '9.973',
        line_total: '9.973',
        tax_rate: '0',
        tax_amount: '0.000',
      })],
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: roundedSnapshot,
    }));

    expect(result.changeDue).toBe('0.050');
    expect(vi.mocked(insertOfflineReceipt).mock.calls[0]![1].change_due).toBe('0.050');
    expect((await appendedPayload()).cash_rounding_adjustment).toBe('-0.023');
  });

  it('signs canonical zeros and the EXACT total when the gate is closed', async () => {
    const db = makeMockDb();
    // fiscal_schema_version 2 => the cutover arm of the gate refuses.
    const unroundedSnapshot = snapshotFor({
      exactTotal: '9.997',
      tenderedAmount: '10.000',
      fiscalSchemaVersion: 2,
    });
    expect(unroundedSnapshot.roundingApplied).toBe(false);

    const result = await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({
        id: 'i1',
        unit_price: '9.997',
        line_total: '9.997',
        tax_rate: '0',
        tax_amount: '0.000',
      })],
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: unroundedSnapshot,
    }));

    expect(result.total).toBe('9.997');
    const payload = await appendedPayload();
    expect(payload.total).toBe('9.997');
    expect(payload.cash_rounding_adjustment).toBe('0.000');
    expect(payload.cash_rounding_denomination).toBe('0.000');

    const row = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    expect(row.cash_rounding_adjustment).toBeNull();
    expect(row.cash_rounding_denomination).toBeNull();
    expect(row.tolerance_shortfall).toBeNull();
  });

  it('persists the tolerance shortfall when the sale auto-accepted', async () => {
    const db = makeMockDb();
    // Exact 9.973 -> rounded 9.950; a 9.900 tender is 0.050 short, exactly the
    // denomination floor of the tolerance ceiling, so the accept lands.
    const toleranceSnapshot = snapshotFor({
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      fiscalSchemaVersion: 3,
    });
    expect(toleranceSnapshot.toleranceDecision.applied).toBe(true);
    expect(toleranceSnapshot.toleranceDecision.shortfall).toBe('0.050');

    await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({
        id: 'i1',
        unit_price: '9.973',
        line_total: '9.973',
        tax_rate: '0',
        tax_amount: '0.000',
      })],
      tenderedAmount: '9.900',
      payments: [{ methodCode: 'CASH', amount: '9.900' }],
      policySnapshot: toleranceSnapshot,
    }));

    const row = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    expect(row.tolerance_shortfall).toBe('0.050');
    // A short tender never hands change back.
    expect(row.change_due).toBe('0.000');
  });

  it('blocks checkout when the line-derived total disagrees with the snapshot', async () => {
    const db = makeMockDb();
    const roundedSnapshot = snapshotFor({
      exactTotal: '9.997',
      tenderedAmount: '10.000',
      fiscalSchemaVersion: 3,
    });

    await expect(createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({
        id: 'i1',
        unit_price: '9.997',
        line_total: '9.997',
        tax_rate: '0',
        tax_amount: '0.000',
      })],
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: { ...roundedSnapshot, exactTotal: '8.000' },
    }))).rejects.toBeInstanceOf(CartTotalIntegrityError);

    // Nothing signed, nothing persisted: the assert fires ahead of the engine
    // being resolved at all, so there is not even an append to inspect.
    expect(vi.mocked(getFiscalEventEngine)).not.toHaveBeenCalled();
    expect(insertOfflineReceipt).not.toHaveBeenCalled();
  });
});
