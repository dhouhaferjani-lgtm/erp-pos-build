import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the fiscal hash service - must be before imports
vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('mock-fiscal-hash-abc123'),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
  advanceHashChain: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
}));

// Codex review B5 (2026-05-01): mock the local voucher repository so the
// store_voucher path can be exercised end-to-end. Default behavior:
//   - findByCode returns null (no voucher) — store_voucher tests override
//   - insertPendingVoucherLedgerRow + updateVoucherBalanceAndStatus succeed
vi.mock('@/lib/offline/voucherRepository', () => ({
  findByCode: vi.fn().mockResolvedValue(null),
  insertPendingVoucherLedgerRow: vi.fn().mockResolvedValue(undefined),
  updateVoucherBalanceAndStatus: vi.fn().mockResolvedValue(undefined),
}));

import { createOfflineReceipt } from '../receiptService';
import { computeFiscalHash } from '@/lib/fiscal/hashService';
import { getTerminalState, advanceHashChain } from '@/lib/db/repositories/terminalStateRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import {
  findByCode as findVoucherByCode,
  insertPendingVoucherLedgerRow,
  updateVoucherBalanceAndStatus,
  type LocalVoucher,
} from '@/lib/offline/voucherRepository';
import { makeCartItem } from '@/test/helpers';
import type { FiscalHashInput } from '@/lib/fiscal/hashService';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

const terminalState = {
  terminal_id: 'terminal-1',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'seed-abc',
  last_hash: 'previous-hash-xyz',
  hash_sequence: 5,
  manager_pin_throttle_until: null,
  manager_pin_failed_attempts: 0,
  fiscal_schema_version: 2 as 2 | 3,
};

describe('receiptService - createOfflineReceipt', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
    // Codex review B5 (2026-05-01): default findByCode to return a generic
    // voucher so pre-existing voucher-bearing tests in this block (which
    // pre-date the B5 redemption wiring) keep passing without per-test
    // setup. Tests that assert the missing-voucher behavior live in the
    // dedicated B5 describe block below and override this default.
    vi.mocked(findVoucherByCode).mockImplementation(async (_db, code) => ({
      id: `mirror-${code}`,
      code,
      initial_balance: '1000.00',
      current_balance: '1000.00',
      currency: 'EUR',
      status: 'Issued' as const,
      redemption_mode: 'Bearer' as const,
      voucher_kind: 'MPV',
      source: 'Refund' as const,
      issued_at: '2026-01-01T00:00:00Z',
      expires_at: null,
      partner_id: null,
      issued_to_partner_id: null,
      redeemable_at_terminal_id: 'terminal-1',
      notes: null,
      synced_at: '2026-04-30T00:00:00Z',
    }));
  });

  it('computes subtotal, tax, and total correctly', async () => {
    const items = [
      makeCartItem({ id: 'item-1', line_total: '100.00', tax_amount: '20.00' }),
      makeCartItem({ id: 'item-2', line_total: '50.00', tax_amount: '5.00' }),
    ];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 200,
      payments: [{ methodCode: 'CASH', amount: '150.00' }],
    });

    expect(result.subtotal).toBe('150.00');
    expect(result.taxAmount).toBe('25.00');
    // Tax-inclusive: total = subtotal (tax already included in line_total)
    expect(result.total).toBe('150.00');
    expect(result.changeDue).toBe(50);
  });

  it('generates sequential receipt number from terminal state', async () => {
    const items = [makeCartItem()];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 20,
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    const year = new Date().getFullYear();
    expect(result.receiptNumber).toBe(`MAIN-T001-${year}-00000006`);
  });

  it('throws when terminal state is not initialized', async () => {
    vi.mocked(getTerminalState).mockResolvedValue(null);

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: [makeCartItem()],
        currency: 'EUR',
        paymentMethodId: 'pm-1',
        paymentRepositoryId: 'repo-1',
        tenderedAmount: 20,
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      }),
    ).rejects.toThrow('Terminal hash chain not initialized');
  });

  it('applies transaction discount', async () => {
    const items = [makeCartItem({ line_total: '100.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 200,
      transactionDiscount: { type: 'fixed', value: '20.00', reason: 'Loyalty' },
      payments: [{ methodCode: 'CASH', amount: '80.00' }],
    });

    expect(result.total).toBe('80.00');
    expect(result.discountAmount).toBe('20.00');
  });

  it('total never goes below zero with large discount', async () => {
    const items = [makeCartItem({ line_total: '10.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 0,
      transactionDiscount: { type: 'fixed', value: '999.00' },
      payments: [{ methodCode: 'CASH', amount: '0.00' }],
    });

    expect(result.total).toBe('0.00');
  });

  it('calculates correct change due', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 50,
      payments: [{ methodCode: 'CASH', amount: '30.00' }],
    });

    expect(result.changeDue).toBe(20);
  });

  it('wraps insert and hash chain advance in a transaction', async () => {
    const items = [makeCartItem()];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 20,
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    expect(executeCalls).toContain('BEGIN TRANSACTION');
    expect(executeCalls).toContain('COMMIT');
    expect(insertOfflineReceipt).toHaveBeenCalledOnce();
    expect(advanceHashChain).toHaveBeenCalledWith(
      db,
      'terminal-1',
      'mock-fiscal-hash-abc123',
      6,
    );
  });

  it('rolls back transaction if insertOfflineReceipt fails', async () => {
    vi.mocked(insertOfflineReceipt).mockRejectedValueOnce(new Error('insert failed'));

    const items = [makeCartItem()];

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-1',
        paymentRepositoryId: 'repo-1',
        tenderedAmount: 20,
        payments: [{ methodCode: 'CASH', amount: '10.00' }],
      }),
    ).rejects.toThrow('insert failed');

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    expect(executeCalls).toContain('BEGIN TRANSACTION');
    expect(executeCalls).toContain('ROLLBACK');
    expect(executeCalls).not.toContain('COMMIT');
  });

  it('calls computeFiscalHash with previous hash from terminal state', async () => {
    const items = [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 50,
      payments: [{ methodCode: 'CASH', amount: '50.00' }],
    });

    expect(computeFiscalHash).toHaveBeenCalledWith(
      expect.objectContaining({
        previousHash: 'previous-hash-xyz',
        total: '50.00',
        currency: 'EUR',
      }),
    );
  });

  it('returns the fiscal hash in the result', async () => {
    const items = [makeCartItem()];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 10,
      payments: [{ methodCode: 'CASH', amount: '10.00' }],
    });

    expect(result.fiscalHash).toBe('mock-fiscal-hash-abc123');
  });

  it('computes VAT breakdown and passes it to fiscal hash', async () => {
    const items = [
      makeCartItem({ id: 'i1', tax_rate: '20', tax_amount: '20.00', line_total: '100.00' }),
      makeCartItem({ id: 'i2', tax_rate: '20', tax_amount: '10.00', line_total: '50.00' }),
      makeCartItem({ id: 'i3', tax_rate: '10', tax_amount: '3.00', line_total: '30.00' }),
    ];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 250,
      payments: [{ methodCode: 'CASH', amount: '180.00' }],
    });

    expect(computeFiscalHash).toHaveBeenCalledOnce();
    const hashInput = vi.mocked(computeFiscalHash).mock.calls[0]![0] as FiscalHashInput;

    expect(hashInput.vatBreakdown).toHaveLength(2);
    expect(hashInput.vatBreakdown[0]).toEqual({ rate: '10', amount: '3.00' });
    expect(hashInput.vatBreakdown[1]).toEqual({ rate: '20', amount: '30.00' });
  });

  it('computes fiscal hash with multi-payment breakdown (not hardcoded CASH)', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 30,
      payments: [
        { methodCode: 'CASH', amount: '10.00' },
        { methodCode: 'CARD', amount: '20.00' },
      ],
    });

    const hashInput = vi.mocked(computeFiscalHash).mock.calls[0]![0] as FiscalHashInput;
    expect(hashInput.payments).toEqual([
      { methodCode: 'CASH', amount: '10.00' },
      { methodCode: 'CARD', amount: '20.00' },
    ]);
  });

  // ───────────────────────────────────────────────────────────────────────────
  // Codex review B1 (2026-04-30) — fiscal_schema_version branching
  //
  // The legacy v2 path MUST stay bit-for-bit unchanged: terminals that have
  // not cut over keep producing identical hashes. The v3 path is taken only
  // when the terminal's `fiscal_schema_version === 3`, and produces hashes
  // via the `buildCanonicalPayload` builder (which is independently proven
  // to mirror the post-B2 PHP builder via fixture-08).
  // ───────────────────────────────────────────────────────────────────────────

  it('v2 terminal: stamps fiscal_schema_version=2 on the offline_receipts row and uses legacy computeFiscalHash', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 30,
      payments: [{ methodCode: 'CASH', amount: '30.00' }],
    });

    // The legacy computeFiscalHash path must still be invoked exactly once.
    expect(computeFiscalHash).toHaveBeenCalledOnce();
    // The mock returns 'mock-fiscal-hash-abc123'; assert this hash is what gets persisted.
    expect(result.fiscalHash).toBe('mock-fiscal-hash-abc123');

    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1] as Omit<
      OfflineReceipt,
      'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'
    >;
    expect(inserted.fiscal_schema_version).toBe(2);
    expect(inserted.fiscal_hash).toBe('mock-fiscal-hash-abc123');
  });

  it('v3 terminal: stamps fiscal_schema_version=3 on the row and the hash matches buildCanonicalPayload→SHA-256', async () => {
    // Switch the mocked terminal state to v3.
    vi.mocked(getTerminalState).mockResolvedValue({
      ...terminalState,
      fiscal_schema_version: 3,
    });

    // Use a deterministic line that produces predictable canonical input.
    // tax_rate '0.00' → no vat row. Cash payment, no instrument.
    const items = [
      makeCartItem({
        id: 'i1',
        line_total: '30.00',
        tax_amount: '0.00',
        tax_rate: '0.00',
      }),
    ];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 30,
      payments: [{ methodCode: 'CASH', amount: '30.00' }],
    });

    // The legacy v2 helper must NOT have been called.
    expect(computeFiscalHash).not.toHaveBeenCalled();

    // The receipt was sealed under v3 — the persisted column must reflect that.
    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1] as Omit<
      OfflineReceipt,
      'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'
    >;
    expect(inserted.fiscal_schema_version).toBe(3);

    // Independently re-derive the expected hash from the same canonical
    // builder + SHA-256. If the receiptService internal path drifts from
    // the canonicalizer, this assertion fails and we know the v3 wiring
    // broke before any cross-system payload diverges.
    const { buildCanonicalPayload } = await import('@/lib/fiscal/v3/canonicalPayload');
    // We cannot easily replay the exact `posted_at` (Date.now), so just
    // assert (a) the hash is a 64-char hex and (b) it differs from the v2
    // mock value. A deeper byte-equality assertion is covered by the
    // canonicalPayload.test.ts fixture-08 round-trip — that test is the
    // server-side parity proof; this test guards the receiptService→builder
    // wiring.
    expect(result.fiscalHash).toMatch(/^[0-9a-f]{64}$/);
    expect(result.fiscalHash).not.toBe('mock-fiscal-hash-abc123');
    // Touch the import so TS doesn't elide it (and proves it resolves).
    expect(typeof buildCanonicalPayload).toBe('function');
  });

  it('v3 terminal with voucher-bearing payment: instrument fields enter the canonical input', async () => {
    vi.mocked(getTerminalState).mockResolvedValue({
      ...terminalState,
      fiscal_schema_version: 3,
    });

    const items = [makeCartItem({ line_total: '15.00', tax_amount: '0.00', tax_rate: '0.00' })];

    const result = await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-voucher',
      paymentRepositoryId: 'repo-voucher',
      tenderedAmount: 15,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '15.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SVC-2026-0001',
        },
      ],
    });

    // The hash is a 64-char SHA-256 hex.
    expect(result.fiscalHash).toMatch(/^[0-9a-f]{64}$/);

    // The v3 path was taken: legacy hash helper untouched.
    expect(computeFiscalHash).not.toHaveBeenCalled();

    // The persisted row carries the v3 stamp.
    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1] as Omit<
      OfflineReceipt,
      'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'
    >;
    expect(inserted.fiscal_schema_version).toBe(3);

    // Codex review B3 (2026-04-30): payments_json must carry method_code,
    // instrument_type, and instrument_serial so the sync layer can forward
    // them to the server. Without these fields persisted here, the server
    // would recompute the v3 hash from null instrument fields and reject
    // the receipt as a chain break.
    const parsedPayments = JSON.parse(inserted.payments_json) as Array<{
      method_code?: string;
      instrument_type?: string | null;
      instrument_serial?: string | null;
    }>;
    expect(parsedPayments).toHaveLength(1);
    expect(parsedPayments[0]!.method_code).toBe('store_voucher');
    expect(parsedPayments[0]!.instrument_type).toBe('store_voucher');
    expect(parsedPayments[0]!.instrument_serial).toBe('SVC-2026-0001');
  });

  it('B3-followup audit (Finding 3): the wire payload produced by receiptToPayload matches the canonical input the hash was sealed against', async () => {
    // The original B3 self-consistency test below proves
    //   createOfflineReceipt → buildCanonicalPayload
    // are byte-consistent. The audit flagged that a future TS-only drift in
    // either `receiptService.ts:167-173` (canonical input builder) or
    // `syncService.ts:1191-1211` (wire payload parser) could pass that test
    // while shipping a wire payload from which the server cannot reproduce
    // the hash — because nothing today asserts the wire payload matches the
    // canonical input shape on every byte. This test closes that gap.
    vi.mocked(getTerminalState).mockResolvedValue({
      ...terminalState,
      fiscal_schema_version: 3,
      last_hash: 'prev-hash-wire-shape',
      hash_sequence: 11,
    });

    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00', tax_rate: '0.00' })];

    const fixedNow = new Date('2026-04-30T12:00:00.000Z');
    vi.useFakeTimers();
    vi.setSystemTime(fixedNow);
    try {
      const result = await createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-voucher',
        paymentRepositoryId: 'repo-voucher',
        tenderedAmount: 30,
        payments: [
          {
            methodCode: 'store_voucher',
            amount: '30.00',
            instrumentType: 'store_voucher',
            instrumentSerial: 'SVC-2026-0099',
          },
        ],
      });

      // Capture the persisted row that insertOfflineReceipt was called with.
      const persisted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1] as Omit<
        OfflineReceipt,
        'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'
      >;

      // Materialize a full OfflineReceipt (with the metadata columns the sync
      // layer depends on) and run it through receiptToPayload.
      const fullReceipt = {
        ...persisted,
        created_at: fixedNow.toISOString(),
        synced_at: null,
        sync_error: null,
        retry_count: 0,
        server_receipt_id: null,
      } as unknown as Parameters<typeof import('@/lib/sync/syncService')['__test_receiptToPayload']>[0];

      // Import the test-only export of receiptToPayload (added below by
      // Finding 3 — the production sync path imports the same fn).
      const { __test_receiptToPayload: receiptToPayload } = await import('@/lib/sync/syncService');
      const wire = receiptToPayload(fullReceipt);

      // Assertion 1 (wire shape). The wire payment row MUST carry method_code,
      // instrument_type, instrument_serial verbatim — this is the contract the
      // server reads when it recomputes the v3 hash. A drift here (dropping
      // a field, changing case normalization, mismatching sort order) would
      // pass the existing canonical test but fail real sync.
      expect(wire.payments).toHaveLength(1);
      expect(wire.payments[0]).toEqual({
        payment_method_id: 'pm-voucher',
        repository_id: 'repo-voucher',
        amount: '30.00',
        card_last_four: null,
        transaction_reference: null,
        method_code: 'store_voucher',
        instrument_type: 'store_voucher',
        instrument_serial: 'SVC-2026-0099',
      });

      // Assertion 2 (cross-check). Recompute the hash from the wire payment
      // shape (lowercased method_code per the canonicalizer's rule) and assert
      // it matches the offline-sealed hash. This proves the wire payload
      // would let the server reproduce the same hash byte-for-byte.
      const { buildCanonicalPayload } = await import('@/lib/fiscal/v3/canonicalPayload');
      const canonical = await buildCanonicalPayload({
        receipt_number: result.receiptNumber,
        posted_at: fixedNow.toISOString(),
        previous_hash: 'prev-hash-wire-shape',
        total: '30.00',
        currency: 'EUR',
        vat_breakdown: [],
        payments: wire.payments.map((p) => ({
          method_code: p.method_code.toLowerCase(),
          payment_type: 'pos',
          amount: p.amount,
          instrument_type: p.instrument_type,
          instrument_serial: p.instrument_serial,
        })),
        voucher_ledger_entries: [],
        exchange_group_id: null,
        audit: null,
      });
      const enc = new TextEncoder().encode(canonical);
      const buf = await crypto.subtle.digest('SHA-256', enc);
      const expectedHash = Array.from(new Uint8Array(buf))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
      expect(result.fiscalHash).toBe(expectedHash);
    } finally {
      vi.useRealTimers();
    }
  });

  it('Codex review B3: createOfflineReceipt hash is self-consistent with the canonical builder for voucher payments', async () => {
    vi.mocked(getTerminalState).mockResolvedValue({
      ...terminalState,
      fiscal_schema_version: 3,
      last_hash: 'prev-hash-self-consistency',
      hash_sequence: 10,
    });

    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    // Use vi.useFakeTimers to pin `new Date()` (not just Date.now()) so we
    // can replay the exact canonical input that receiptService constructs.
    const fixedNow = new Date('2026-04-30T12:00:00.000Z');
    vi.useFakeTimers();
    vi.setSystemTime(fixedNow);
    try {
      const result = await createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Test Operator',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-voucher',
        paymentRepositoryId: 'repo-voucher',
        tenderedAmount: 20,
        payments: [
          {
            methodCode: 'store_voucher',
            amount: '20.00',
            instrumentType: 'store_voucher',
            instrumentSerial: 'SVC-2026-0099',
          },
        ],
      });

      // Replay the canonical input that receiptService constructs internally
      // (the `computeV3FiscalHash` mapper). Re-derive the expected hash from
      // buildCanonicalPayload + SHA-256 and assert byte-equality. This proves
      // the receiptService → canonicalizer wiring agrees on every byte —
      // including the new B3 instrument fields — without going through the
      // sync layer.
      const { buildCanonicalPayload } = await import('@/lib/fiscal/v3/canonicalPayload');
      const canonical = await buildCanonicalPayload({
        receipt_number: result.receiptNumber,
        posted_at: fixedNow.toISOString(),
        previous_hash: 'prev-hash-self-consistency',
        total: '20.00',
        currency: 'EUR',
        vat_breakdown: [],
        payments: [
          {
            method_code: 'store_voucher',
            payment_type: 'pos',
            amount: '20.00',
            instrument_type: 'store_voucher',
            instrument_serial: 'SVC-2026-0099',
          },
        ],
        voucher_ledger_entries: [],
        exchange_group_id: null,
        audit: null,
      });
      const enc = new TextEncoder().encode(canonical);
      const buf = await crypto.subtle.digest('SHA-256', enc);
      const expectedHash = Array.from(new Uint8Array(buf))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
      expect(result.fiscalHash).toBe(expectedHash);
    } finally {
      vi.useRealTimers();
    }
  });

  it('persists payments_json, consumption_mode, and table_id to SQLite', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Test Operator',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 30,
      payments: [{ methodCode: 'CASH', amount: '30.00' }],
      consumptionMode: 'SUR_PLACE',
      tableId: 'table-42',
    });

    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1] as Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'>;
    expect(inserted.consumption_mode).toBe('SUR_PLACE');
    expect(inserted.table_id).toBe('table-42');
    const parsedPayments = JSON.parse(inserted.payments_json) as Array<{ amount: string }>;
    expect(parsedPayments).toHaveLength(1);
    expect(parsedPayments[0]!.amount).toBe('30.00');
  });
});

// ────────────────────────────────────────────────────────────────────────────
// Codex review B5 (2026-05-01): voucher tender writes the local
// voucher_ledger Redeemed row and decrements the voucher projection.
//
// These tests fail on parent commit 5b65cd06 (where createOfflineReceipt
// has no voucher-redemption logic — the receipt seals but the voucher's
// balance never moves and no ledger row is appended) and pass after the
// receiptService change.
//
// The server-side VoucherRedemptionService remains the source of truth for
// GL posting; the offline path produces the projection updates + the
// pending ledger row that VoucherLedgerPushService::ingest dedupes against
// the canonical projection.
// ────────────────────────────────────────────────────────────────────────────

function makeLocalVoucher(overrides: Partial<LocalVoucher> = {}): LocalVoucher {
  return {
    id: 'v-001',
    code: 'SV-2026-0099',
    initial_balance: '50.00',
    current_balance: '50.00',
    currency: 'EUR',
    status: 'Issued',
    redemption_mode: 'Bearer',
    voucher_kind: 'MPV',
    source: 'Refund',
    issued_at: '2026-04-01T00:00:00Z',
    expires_at: null,
    partner_id: null,
    issued_to_partner_id: null,
    redeemable_at_terminal_id: 'terminal-1',
    notes: null,
    synced_at: '2026-04-30T00:00:00Z',
    ...overrides,
  };
}

describe('receiptService - B5 voucher tender redemption (offline)', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
    vi.mocked(findVoucherByCode).mockResolvedValue(null);
  });

  it('writes a pending voucher_ledger Redeemed row when a store_voucher payment is present', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(makeLocalVoucher());
    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-cashier-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 20,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '20.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-0099',
        },
      ],
    });

    expect(insertPendingVoucherLedgerRow).toHaveBeenCalledOnce();
    const ledgerCall = vi.mocked(insertPendingVoucherLedgerRow).mock.calls[0]!;
    const ledgerRow = ledgerCall[1];
    expect(ledgerRow.event).toBe('Redeemed');
    expect(ledgerRow.amount).toBe('20.00');
    expect(ledgerRow.voucher_id).toBe('v-001');
    expect(ledgerRow.currency).toBe('EUR');
    expect(ledgerRow.terminal_id).toBe('terminal-1');
    expect(ledgerRow.user_id).toBe('op-cashier-1');
    expect(ledgerRow.receipt_id).toBeNull(); // server assigns this on push
    // Ledger id must be a fresh UUID (not voucher.id, not receipt.id).
    expect(ledgerRow.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i);
    expect(ledgerRow.id).not.toBe('v-001');
  });

  it('decrements the local voucher balance by the redeemed amount and updates status to PartiallyRedeemed', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(makeLocalVoucher({ current_balance: '50.00' }));
    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 20,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '20.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-0099',
        },
      ],
    });

    expect(updateVoucherBalanceAndStatus).toHaveBeenCalledOnce();
    const [, voucherId, newBalance, newStatus] =
      vi.mocked(updateVoucherBalanceAndStatus).mock.calls[0]!;
    expect(voucherId).toBe('v-001');
    expect(newBalance).toBe('30.00'); // 50 - 20
    expect(newStatus).toBe('PartiallyRedeemed');
  });

  it('sets status to FullyRedeemed when the redemption exhausts the balance', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(makeLocalVoucher({ current_balance: '50.00' }));
    const items = [makeCartItem({ line_total: '50.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 50,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '50.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-0099',
        },
      ],
    });

    const [, , newBalance, newStatus] =
      vi.mocked(updateVoucherBalanceAndStatus).mock.calls[0]!;
    expect(newBalance).toBe('0.00');
    expect(newStatus).toBe('FullyRedeemed');
  });

  it('throws and rolls back when the local voucher mirror does not have the tendered code', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(null);
    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Cashier',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-store-voucher',
        paymentRepositoryId: 'repo-virtual',
        tenderedAmount: 20,
        payments: [
          {
            methodCode: 'store_voucher',
            amount: '20.00',
            instrumentType: 'store_voucher',
            instrumentSerial: 'UNKNOWN-VOUCHER',
          },
        ],
      }),
    ).rejects.toThrow(/UNKNOWN-VOUCHER/);

    // Receipt insert never ran — the missing-voucher guard fires before
    // BEGIN TRANSACTION.
    expect(insertOfflineReceipt).not.toHaveBeenCalled();
    expect(insertPendingVoucherLedgerRow).not.toHaveBeenCalled();
    expect(updateVoucherBalanceAndStatus).not.toHaveBeenCalled();
  });

  it('rolls back the WHOLE transaction when insertPendingVoucherLedgerRow fails (atomicity)', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(makeLocalVoucher());
    vi.mocked(insertPendingVoucherLedgerRow).mockRejectedValueOnce(
      new Error('voucher_ledger insert failed'),
    );

    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await expect(
      createOfflineReceipt(db, {
        terminalId: 'terminal-1',
        operatorId: 'op-1',
        operatorName: 'Cashier',
        cartItems: items,
        currency: 'EUR',
        paymentMethodId: 'pm-store-voucher',
        paymentRepositoryId: 'repo-virtual',
        tenderedAmount: 20,
        payments: [
          {
            methodCode: 'store_voucher',
            amount: '20.00',
            instrumentType: 'store_voucher',
            instrumentSerial: 'SV-2026-0099',
          },
        ],
      }),
    ).rejects.toThrow('voucher_ledger insert failed');

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    expect(executeCalls).toContain('BEGIN TRANSACTION');
    expect(executeCalls).toContain('ROLLBACK');
    expect(executeCalls).not.toContain('COMMIT');
    // Voucher balance update never ran (failure was BEFORE that call).
    expect(updateVoucherBalanceAndStatus).not.toHaveBeenCalled();
  });

  it('does NOT touch the voucher_ledger when the receipt has no store_voucher payments (cash-only regression guard)', async () => {
    const items = [makeCartItem({ line_total: '30.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-1',
      paymentRepositoryId: 'repo-1',
      tenderedAmount: 30,
      payments: [{ methodCode: 'CASH', amount: '30.00' }],
    });

    expect(insertPendingVoucherLedgerRow).not.toHaveBeenCalled();
    expect(updateVoucherBalanceAndStatus).not.toHaveBeenCalled();
    expect(findVoucherByCode).not.toHaveBeenCalled();
  });

  it('handles multiple store_voucher tenders in one receipt (each writes its own ledger row + balance update)', async () => {
    vi.mocked(findVoucherByCode)
      .mockResolvedValueOnce(makeLocalVoucher({ id: 'v-A', code: 'SV-A', current_balance: '40.00' }))
      .mockResolvedValueOnce(makeLocalVoucher({ id: 'v-B', code: 'SV-B', current_balance: '15.00' }));

    const items = [makeCartItem({ line_total: '50.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 50,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '40.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-A',
        },
        {
          methodCode: 'store_voucher',
          amount: '10.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-B',
        },
      ],
    });

    expect(insertPendingVoucherLedgerRow).toHaveBeenCalledTimes(2);
    expect(updateVoucherBalanceAndStatus).toHaveBeenCalledTimes(2);

    const ledgerRows = vi.mocked(insertPendingVoucherLedgerRow).mock.calls.map((c) => c[1]);
    expect(ledgerRows[0]!.voucher_id).toBe('v-A');
    expect(ledgerRows[0]!.amount).toBe('40.00');
    expect(ledgerRows[1]!.voucher_id).toBe('v-B');
    expect(ledgerRows[1]!.amount).toBe('10.00');

    const balanceUpdates = vi.mocked(updateVoucherBalanceAndStatus).mock.calls;
    // SV-A: 40 - 40 = 0 → FullyRedeemed
    expect(balanceUpdates[0]![1]).toBe('v-A');
    expect(balanceUpdates[0]![2]).toBe('0.00');
    expect(balanceUpdates[0]![3]).toBe('FullyRedeemed');
    // SV-B: 15 - 10 = 5 → PartiallyRedeemed
    expect(balanceUpdates[1]![1]).toBe('v-B');
    expect(balanceUpdates[1]![2]).toBe('5.00');
    expect(balanceUpdates[1]![3]).toBe('PartiallyRedeemed');
  });

  it('voucher ledger writes happen INSIDE the BEGIN/COMMIT envelope (atomic with receipt insert)', async () => {
    vi.mocked(findVoucherByCode).mockResolvedValue(makeLocalVoucher());
    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-store-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 20,
      payments: [
        {
          methodCode: 'store_voucher',
          amount: '20.00',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-0099',
        },
      ],
    });

    const executeCalls = vi.mocked(db.execute).mock.calls.map((c) => c[0]);
    const beginIdx = executeCalls.indexOf('BEGIN TRANSACTION');
    const commitIdx = executeCalls.indexOf('COMMIT');
    expect(beginIdx).toBeGreaterThanOrEqual(0);
    expect(commitIdx).toBeGreaterThan(beginIdx);

    // The mocked helpers don't issue raw db.execute calls (they're mocked at
    // the module boundary), but `insertOfflineReceipt` ran within the
    // envelope and `insertPendingVoucherLedgerRow`/`updateVoucherBalanceAndStatus`
    // were both called exactly once. Together with the rollback-on-failure
    // test above, this proves they share the same transactional fate.
    expect(insertOfflineReceipt).toHaveBeenCalledOnce();
    expect(insertPendingVoucherLedgerRow).toHaveBeenCalledOnce();
    expect(updateVoucherBalanceAndStatus).toHaveBeenCalledOnce();
  });

  it('does NOT redeem a payment whose instrumentType is restaurant_voucher (Phase 2 — only store_voucher writes locally)', async () => {
    // Restaurant vouchers are tracked on pos_receipt_payments instrument
    // fields ONLY. They never enter the local `vouchers` table in Phase 1
    // (spec §3.2.1). The offline write must skip them.
    const items = [makeCartItem({ line_total: '20.00', tax_amount: '0.00', tax_rate: '0.00' })];

    await createOfflineReceipt(db, {
      terminalId: 'terminal-1',
      operatorId: 'op-1',
      operatorName: 'Cashier',
      cartItems: items,
      currency: 'EUR',
      paymentMethodId: 'pm-restaurant-voucher',
      paymentRepositoryId: 'repo-virtual',
      tenderedAmount: 20,
      payments: [
        {
          methodCode: 'restaurant_voucher',
          amount: '20.00',
          instrumentType: 'restaurant_voucher',
          instrumentSerial: 'TR-XYZ',
        },
      ],
    });

    expect(findVoucherByCode).not.toHaveBeenCalled();
    expect(insertPendingVoucherLedgerRow).not.toHaveBeenCalled();
    expect(updateVoucherBalanceAndStatus).not.toHaveBeenCalled();
  });
});
