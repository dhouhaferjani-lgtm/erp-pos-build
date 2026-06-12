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

const incrementPendingCountSpy = vi.fn();
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({
      incrementPendingCount: incrementPendingCountSpy,
    }),
  },
}));

import { createOfflineReceipt } from '../receiptService';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { setWriter, __resetWriteGateForTesting } from '@/lib/db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';
import { makeCartItem } from '@/test/helpers';
import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';

function makeMockDb() {
  const db = {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
  // Single-writer architecture: the receipt tx runs on the write gate's
  // writer, not the pooled handle. Registering the same mock as the writer
  // keeps the per-test assertion surface unified (BEGIN/COMMIT and tx
  // statements all land on this mock).
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

function evidence(overrides: Partial<PosOverrideEvidence> = {}): PosOverrideEvidence {
  return {
    approval_id: '77777777-7777-4777-8777-777777777777',
    approval_event_id: '88888888-8888-4888-8888-888888888888',
    approval_scope: 'discount_limit_override',
    override_event_id: '99999999-9999-4999-8999-999999999999',
    policy_version: 'pos-phase-4-v1',
    supervisor_user_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    target_reference_id: 'cart-item-1',
    ...overrides,
  };
}

describe('receiptService — fiscal-event engine wiring', () => {
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
        event_version: 1,
        signature_version: 'hash-chain-integrity-v1',
        sequence_number: 6,
        event_time_device: '2026-05-20T10:00:00Z',
        business_date: '2026-05-20',
        reference_event_id: null,
        reference_document_id: null,
        source_event_class: 'offline_receipts',
        source_event_id: '44444444-4444-4444-8444-444444444444',
        canonical_bytes: '{"business_date":"2026-05-20"}',
        previous_hash: 'a'.repeat(64),
        current_hash: 'c'.repeat(64),
        sync_status: 'pending',
        signature_status: 'not_required',
        created_at: '2026-05-20T10:00:00Z',
      }),
    } as never);
  });

  it('opens the receipt write with BEGIN IMMEDIATE (avoids SQLITE_BUSY_SNAPSHOT 517 under concurrent sync writes)', async () => {
    const db = makeMockDb();

    await createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [makeCartItem({ tax_rate: '0.00' })],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    const beginStmt = vi
      .mocked(db.execute)
      .mock.calls.map((c) => String(c[0]))
      .find((s) => /^BEGIN/i.test(s));
    // Deferred BEGIN reads a snapshot then upgrades to write; a concurrent sync
    // write invalidates that snapshot → SQLite 517 BUSY_SNAPSHOT → "Échec du
    // paiement". IMMEDIATE takes the write lock up front (also serialising the
    // fiscal-chain read-modify-write).
    expect(beginStmt).toBe('BEGIN IMMEDIATE TRANSACTION');
  });

  it('ROLLBACKs (never COMMITs) and rethrows when a tx-body write fails with a Tauri STRING error', async () => {
    const db = makeMockDb();
    vi.mocked(insertOfflineReceipt).mockRejectedValueOnce(
      'error returned from database: (code: 5) database is locked',
    );

    await expect(createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [makeCartItem({ tax_rate: '0.00' })],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    })).rejects.toMatch('database is locked');

    const statements = vi.mocked(db.execute).mock.calls.map((c) => String(c[0]));
    expect(statements).toContain('ROLLBACK');
    expect(statements).not.toContain('COMMIT');
    expect(incrementPendingCountSpy).not.toHaveBeenCalled();
  });

  it('appends SALE_RECEIPT through FiscalEventEngine and mirrors canonical bytes', async () => {
    const db = makeMockDb();

    const result = await createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [makeCartItem({ tax_rate: '0.00' })],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;
    expect(engine.append).toHaveBeenCalledWith(
      db,
      expect.objectContaining({
        event_type: 'SALE_RECEIPT',
        tenant_id: '11111111-1111-4111-8111-111111111111',
        company_id: '22222222-2222-4222-8222-222222222222',
        terminal_id: terminalState.terminal_id,
      }),
    );
    expect(vi.mocked(engine.append).mock.calls[0]![1].payload).toEqual(
      expect.objectContaining({
        seller: expect.objectContaining({
          tax_number: '1234567AM000',
        }),
      }),
    );

    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    expect(inserted.fiscal_hash).toBe('c'.repeat(64));
    expect(inserted.previous_hash).toBe('a'.repeat(64));
    expect(inserted.hash_sequence).toBe(6);
    expect(inserted.canonical_bytes).toBe('{"business_date":"2026-05-20"}');
    expect(result.fiscalHash).toBe('c'.repeat(64));
  });

  it('carries every discount and tender tolerance approval reference in SALE_RECEIPT payload', async () => {
    const db = makeMockDb();
    const transactionEvidence = evidence({
      approval_id: '10000000-0000-4000-8000-000000000001',
      approval_event_id: '10000000-0000-4000-8000-000000000002',
      override_event_id: '10000000-0000-4000-8000-000000000003',
      target_reference_id: 'cart-transaction-discount',
    });
    const lineEvidence = evidence({
      approval_id: '20000000-0000-4000-8000-000000000001',
      approval_event_id: '20000000-0000-4000-8000-000000000002',
      override_event_id: '20000000-0000-4000-8000-000000000003',
      target_reference_id: 'cart-item-1',
    });
    const tenderToleranceEvidence = evidence({
      approval_id: '30000000-0000-4000-8000-000000000001',
      approval_event_id: '30000000-0000-4000-8000-000000000002',
      approval_scope: 'tender_tolerance_override',
      override_event_id: '30000000-0000-4000-8000-000000000003',
      target_reference_id: '66666666-6666-4666-8666-666666666666',
    });

    await createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [
        makeCartItem({
          tax_rate: '0.00',
          line_total: '9.00',
          discount_type: 'fixed',
          discount_amount: '1.00',
          discount_reason: 'Line approval',
          discount_approval_evidence: lineEvidence,
        }),
      ],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 8,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      transactionDiscount: {
        type: 'fixed',
        value: '1.00',
        reason: 'Transaction approval',
        approvalEvidence: transactionEvidence,
      },
      tenderToleranceEvidence,
      payments: [{ methodCode: 'CASH', amount: '8.00' }],
    });

    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;
    const appendRequest = vi.mocked(engine.append).mock.calls[0]![1];
    expect(appendRequest.reference_event_id).toBe(transactionEvidence.override_event_id);
    expect(appendRequest.payload).toEqual(expect.objectContaining({
      approval_references: [
        {
          approval_event_id: transactionEvidence.approval_event_id,
          approval_id: transactionEvidence.approval_id,
          approval_scope: 'discount_limit_override',
          override_event_id: transactionEvidence.override_event_id,
          policy_version: transactionEvidence.policy_version,
          supervisor_user_id: transactionEvidence.supervisor_user_id,
          target_reference_id: transactionEvidence.target_reference_id,
        },
        {
          approval_event_id: lineEvidence.approval_event_id,
          approval_id: lineEvidence.approval_id,
          approval_scope: 'discount_limit_override',
          override_event_id: lineEvidence.override_event_id,
          policy_version: lineEvidence.policy_version,
          supervisor_user_id: lineEvidence.supervisor_user_id,
          target_reference_id: lineEvidence.target_reference_id,
        },
        {
          approval_event_id: tenderToleranceEvidence.approval_event_id,
          approval_id: tenderToleranceEvidence.approval_id,
          approval_scope: 'tender_tolerance_override',
          override_event_id: tenderToleranceEvidence.override_event_id,
          policy_version: tenderToleranceEvidence.policy_version,
          supervisor_user_id: tenderToleranceEvidence.supervisor_user_id,
          target_reference_id: tenderToleranceEvidence.target_reference_id,
        },
      ],
    }));
  });

  it('persists the variant identity on the stored line without altering fiscal SKU bytes', async () => {
    const db = makeMockDb();

    await createOfflineReceipt(db, {
      tenantId: '11111111-1111-4111-8111-111111111111',
      companyId: '22222222-2222-4222-8222-222222222222',
      terminalId: terminalState.terminal_id,
      operatorId: '33333333-3333-4333-8333-333333333333',
      operatorName: 'Cashier',
      shiftId: '55555555-5555-4555-8555-555555555555',
      cartItems: [
        makeCartItem({
          tax_rate: '0.00',
          product: {
            id: 'prod-1',
            name: 'Shoe',
            // The fiscal payload reads `product.sku` — it stays the PRODUCT
            // sku even for a variant line so fiscal bytes are untouched.
            sku: 'SKU-PRODUCT',
            price: '10.00',
            variant_id: 'var-1',
            variant_name: 'Shoe — 39 / Black',
          },
        }),
      ],
      currency: 'EUR',
      seller,
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      idempotencyKey: '66666666-6666-4666-8666-666666666666',
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    // The stored offline line carries the variant identity.
    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];
    const lines = JSON.parse(inserted.lines) as Array<{ variant_id?: string; sku: string }>;
    expect(lines[0]!.variant_id).toBe('var-1');
    expect(lines[0]!.sku).toBe('SKU-PRODUCT');

    // The fiscal payload's line SKU is the PRODUCT sku — variant selection
    // never reaches the fiscal canonical bytes.
    const engine = await vi.mocked(getFiscalEventEngine).mock.results[0]!.value;
    const payload = vi.mocked(engine.append).mock.calls[0]![1].payload as {
      line_items: Array<{ sku: string }>;
    };
    expect(payload.line_items[0]!.sku).toBe('SKU-PRODUCT');
  });
});
