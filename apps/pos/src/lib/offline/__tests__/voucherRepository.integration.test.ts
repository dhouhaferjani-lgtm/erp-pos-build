/**
 * Real-SQLite integration tests for the voucher + receipt mirror.
 *
 * Boots an in-memory SQLite instance via Node 22.5+ `node:sqlite`, runs the
 * actual migrations from migrations.ts (through v25), and asserts the
 * end-to-end round-trip:
 *   - upsertVouchers + findByCode
 *   - upsertVoucherLedgerEntries + getPendingVoucherLedgerEntries lifecycle
 *   - upsertReceiptQrIndexEntries + findReceiptByQrToken / findReceiptByNumber
 *
 * Mirrors the structure of migrations.integration.test.ts. Conditional
 * `describe.skip` if `node:sqlite` isn't available.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  findByCode,
  findReceiptByQrToken,
  findReceiptByNumber,
  findRecentReceiptsByPartner,
  upsertVouchers,
  upsertVoucherLedgerEntries,
  upsertReceiptQrIndexEntries,
  getPendingVoucherLedgerEntries,
  markVoucherLedgerEntrySynced,
  markVoucherLedgerEntryFailed,
  type LocalVoucher,
  type LocalVoucherLedgerEntry,
  type LocalReceiptQrIndexEntry,
} from '../voucherRepository';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

const RECEIPT_UUID = '550e8400-e29b-41d4-a716-446655440000';
const TERMINAL_ID = '11111111-1111-1111-1111-111111111111';

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

function makeVoucher(overrides: Partial<LocalVoucher> = {}): LocalVoucher {
  return {
    id: crypto.randomUUID(),
    code: 'V-TEST-1',
    initial_balance: '5000',
    current_balance: '5000',
    currency: 'EUR',
    status: 'Issued',
    redemption_mode: 'Bearer',
    voucher_kind: 'MPV',
    source: 'Refund',
    issued_at: '2026-04-28T10:00:00+00:00',
    expires_at: null,
    partner_id: null,
    issued_to_partner_id: null,
    redeemable_at_terminal_id: TERMINAL_ID,
    notes: null,
    synced_at: '2026-04-28T10:00:01+00:00',
    ...overrides,
  };
}

function makeLedgerEntry(
  overrides: Partial<LocalVoucherLedgerEntry> = {},
): LocalVoucherLedgerEntry {
  return {
    id: crypto.randomUUID(),
    voucher_id: 'voucher-uuid-1',
    event: 'Issued',
    amount: '5000',
    currency: 'EUR',
    receipt_id: 'receipt-uuid-1',
    terminal_id: TERMINAL_ID,
    user_id: 'user-uuid-1',
    occurred_at: '2026-04-28T10:00:00+00:00',
    sync_status: 'synced',
    sync_error: null,
    synced_at: '2026-04-28T10:00:01+00:00',
    ...overrides,
  };
}

function makeReceiptIndex(
  overrides: Partial<LocalReceiptQrIndexEntry> = {},
): LocalReceiptQrIndexEntry {
  return {
    receipt_uuid: RECEIPT_UUID,
    qr_token: `1:k1:${RECEIPT_UUID}:macblob`,
    receipt_number: 'R-0001',
    terminal_id: TERMINAL_ID,
    posted_at: '2026-04-28T09:00:00+00:00',
    total: '12500',
    currency: 'EUR',
    partner_id: null,
    synced_at: '2026-04-28T09:00:05+00:00',
    ...overrides,
  };
}

d('Schema integration — migrations 23/24/25 create the voucher mirror tables', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates the vouchers table with the expected columns', async () => {
    const cols = await adapter.select<{ name: string }[]>('PRAGMA table_info(vouchers)');
    const names = cols.map((c) => c.name);
    for (const expected of [
      'id', 'code', 'initial_balance', 'current_balance', 'currency', 'status',
      'redemption_mode', 'voucher_kind', 'source', 'issued_at', 'expires_at',
      'partner_id', 'issued_to_partner_id', 'redeemable_at_terminal_id', 'notes',
      'synced_at',
    ]) {
      expect(names, `missing column ${expected}`).toContain(expected);
    }
  });

  it('creates the voucher_ledger table with the expected columns', async () => {
    const cols = await adapter.select<{ name: string }[]>('PRAGMA table_info(voucher_ledger)');
    const names = cols.map((c) => c.name);
    for (const expected of [
      'id', 'voucher_id', 'event', 'amount', 'currency', 'receipt_id',
      'terminal_id', 'user_id', 'occurred_at', 'sync_status', 'sync_error',
      'synced_at',
    ]) {
      expect(names, `missing column ${expected}`).toContain(expected);
    }
  });

  it('creates the receipt_qr_index table with the expected columns', async () => {
    const cols = await adapter.select<{ name: string }[]>('PRAGMA table_info(receipt_qr_index)');
    const names = cols.map((c) => c.name);
    for (const expected of [
      'receipt_uuid', 'qr_token', 'receipt_number', 'terminal_id',
      'posted_at', 'total', 'currency', 'synced_at',
    ]) {
      expect(names, `missing column ${expected}`).toContain(expected);
    }
  });
});

d('Voucher repository — round-trip against real SQLite', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('upsertVouchers + findByCode round-trips a voucher', async () => {
    const voucher = makeVoucher({ code: 'V-RT-1', initial_balance: '7500', current_balance: '7500' });
    await upsertVouchers(adapter.asDatabase(), [voucher]);

    const found = await findByCode(adapter.asDatabase(), 'V-RT-1');
    expect(found).not.toBeNull();
    expect(found!.id).toBe(voucher.id);
    expect(found!.initial_balance).toBe('7500');
    expect(found!.current_balance).toBe('7500');
    expect(found!.redeemable_at_terminal_id).toBe(TERMINAL_ID);
  });

  it('upsertVouchers updates an existing row by id (idempotent)', async () => {
    const v1 = makeVoucher({ code: 'V-RT-2', current_balance: '5000' });
    await upsertVouchers(adapter.asDatabase(), [v1]);

    const v2: LocalVoucher = { ...v1, current_balance: '2500', status: 'PartiallyRedeemed' };
    await upsertVouchers(adapter.asDatabase(), [v2]);

    const found = await findByCode(adapter.asDatabase(), 'V-RT-2');
    expect(found!.current_balance).toBe('2500');
    expect(found!.status).toBe('PartiallyRedeemed');
  });

  it('findByCode returns null when no voucher exists', async () => {
    const found = await findByCode(adapter.asDatabase(), 'NONE');
    expect(found).toBeNull();
  });

  it('pending ledger entries are returned by getPendingVoucherLedgerEntries; synced entries are not', async () => {
    const pending = makeLedgerEntry({
      sync_status: 'pending',
      synced_at: null,
      occurred_at: '2026-04-28T11:00:00+00:00',
    });
    const alreadySynced = makeLedgerEntry({
      sync_status: 'synced',
      occurred_at: '2026-04-28T10:00:00+00:00',
    });
    await upsertVoucherLedgerEntries(adapter.asDatabase(), [pending, alreadySynced]);

    const rows = await getPendingVoucherLedgerEntries(adapter.asDatabase());
    expect(rows.map((r) => r.id)).toEqual([pending.id]);

    await markVoucherLedgerEntrySynced(adapter.asDatabase(), pending.id);

    const after = await getPendingVoucherLedgerEntries(adapter.asDatabase());
    expect(after).toHaveLength(0);
  });

  it('markVoucherLedgerEntryFailed flags a row and stores the reason', async () => {
    const pending = makeLedgerEntry({ sync_status: 'pending', synced_at: null });
    await upsertVoucherLedgerEntries(adapter.asDatabase(), [pending]);

    await markVoucherLedgerEntryFailed(adapter.asDatabase(), pending.id, 'network down');

    // The row is no longer 'pending' — it's 'failed' with a reason. We can verify
    // by re-querying via raw select since we don't expose a "find ledger by id" helper.
    const rows = await adapter.select<{ sync_status: string; sync_error: string }[]>(
      'SELECT sync_status, sync_error FROM voucher_ledger WHERE id = $1',
      [pending.id],
    );
    expect(rows[0]!.sync_status).toBe('failed');
    expect(rows[0]!.sync_error).toBe('network down');

    // Failed entries are not returned by getPendingVoucherLedgerEntries.
    const pendingRows = await getPendingVoucherLedgerEntries(adapter.asDatabase());
    expect(pendingRows).toHaveLength(0);
  });

  it('upsertReceiptQrIndexEntries + findReceiptByQrToken parses v:kid:uuid:mac and returns the row', async () => {
    const entry = makeReceiptIndex();
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [entry]);

    const found = await findReceiptByQrToken(
      adapter.asDatabase(),
      `1:k1:${RECEIPT_UUID}:macblob`,
    );
    expect(found).not.toBeNull();
    expect(found!.receipt_uuid).toBe(RECEIPT_UUID);
    expect(found!.receipt_number).toBe('R-0001');
  });

  it('findReceiptByQrToken returns null for malformed token', async () => {
    const entry = makeReceiptIndex();
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [entry]);

    expect(await findReceiptByQrToken(adapter.asDatabase(), 'garbage')).toBeNull();
    expect(await findReceiptByQrToken(adapter.asDatabase(), '1:kid:not-a-uuid:mac')).toBeNull();
  });

  it('findReceiptByNumber returns the indexed row when the receipt number matches', async () => {
    const entry = makeReceiptIndex({ receipt_number: 'R-0042' });
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [entry]);

    const found = await findReceiptByNumber(adapter.asDatabase(), 'R-0042');
    expect(found).not.toBeNull();
    expect(found!.receipt_uuid).toBe(RECEIPT_UUID);
  });

  it('upsertReceiptQrIndexEntries updates an existing receipt_uuid (idempotent — late QR token arrival)', async () => {
    // Offline-issued receipt: starts with qr_token = null until the server signs it.
    const offlineFirst = makeReceiptIndex({ qr_token: null });
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [offlineFirst]);

    let row = await findReceiptByNumber(adapter.asDatabase(), 'R-0001');
    expect(row!.qr_token).toBeNull();

    // Sync arrives later with the signed token.
    const updated = makeReceiptIndex({ qr_token: `1:k2:${RECEIPT_UUID}:newmac` });
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [updated]);

    row = await findReceiptByNumber(adapter.asDatabase(), 'R-0001');
    expect(row!.qr_token).toBe(`1:k2:${RECEIPT_UUID}:newmac`);
  });
});

d('findRecentReceiptsByPartner — permission-bound search window (Phase H Block 2.5b)', () => {
  let adapter: SqliteTestAdapter;
  const PARTNER_ID = 'aaaa1111-2222-3333-4444-555566667777';

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  /**
   * Helper: insert a receipt-qr-index entry for the given partner with an
   * explicit `posted_at` (ISO 8601). Uses unique receipt_uuid + receipt_number
   * to avoid PK / unique-constraint collisions across the test fixtures.
   */
  async function seedReceipt(
    receiptUuid: string,
    receiptNumber: string,
    postedAtIso: string,
    partnerId: string | null = PARTNER_ID,
  ): Promise<void> {
    await upsertReceiptQrIndexEntries(adapter.asDatabase(), [
      {
        receipt_uuid: receiptUuid,
        qr_token: null,
        receipt_number: receiptNumber,
        terminal_id: TERMINAL_ID,
        posted_at: postedAtIso,
        total: '12500',
        currency: 'EUR',
        partner_id: partnerId,
        synced_at: postedAtIso,
      },
    ]);
  }

  it('returns all receipts regardless of date when windowDays is null (full fiscal year)', async () => {
    const todayIso = new Date().toISOString();
    const twoYearsAgoIso = new Date(Date.now() - 2 * 365 * 86_400_000).toISOString();

    await seedReceipt('11111111-1111-1111-1111-111111111111', 'R-A', todayIso);
    await seedReceipt('22222222-2222-2222-2222-222222222222', 'R-B', twoYearsAgoIso);

    const rows = await findRecentReceiptsByPartner(
      adapter.asDatabase(),
      PARTNER_ID,
      20,
      null, // explicit "no window" — full fiscal year
    );

    expect(rows.map((r) => r.receipt_number).sort()).toEqual(['R-A', 'R-B']);
  });

  it('returns only receipts within the window when windowDays = 30', async () => {
    const recent = new Date(Date.now() - 5 * 86_400_000).toISOString();
    const old = new Date(Date.now() - 60 * 86_400_000).toISOString();

    await seedReceipt('33333333-3333-3333-3333-333333333333', 'R-RECENT', recent);
    await seedReceipt('44444444-4444-4444-4444-444444444444', 'R-OLD', old);

    const rows = await findRecentReceiptsByPartner(
      adapter.asDatabase(),
      PARTNER_ID,
      20,
      30,
    );

    expect(rows.map((r) => r.receipt_number)).toEqual(['R-RECENT']);
  });

  it('returns an empty array when no receipts fall inside the 30-day window', async () => {
    const old = new Date(Date.now() - 60 * 86_400_000).toISOString();
    await seedReceipt('55555555-5555-5555-5555-555555555555', 'R-OLD-1', old);

    const rows = await findRecentReceiptsByPartner(
      adapter.asDatabase(),
      PARTNER_ID,
      20,
      30,
    );

    expect(rows).toEqual([]);
  });

  it('default call (no windowDays argument) behaves like full fiscal year (windowDays = null)', async () => {
    const old = new Date(Date.now() - 60 * 86_400_000).toISOString();
    await seedReceipt('66666666-6666-6666-6666-666666666666', 'R-DEFAULT-OLD', old);

    // Three-arg call — backwards-compatible with existing callers from before
    // Block 2.5. Should NOT apply a date window.
    const rows = await findRecentReceiptsByPartner(adapter.asDatabase(), PARTNER_ID, 20);

    expect(rows.map((r) => r.receipt_number)).toEqual(['R-DEFAULT-OLD']);
  });

  it('windowDays = Number.MAX_VALUE falls through to full-window (no date clause applied)', async () => {
    // Number.MAX_VALUE passes Number.isFinite but exceeds the 3650-day upper bound.
    // SQLite's datetime modifier returns NULL for such extreme integers, which
    // would silently filter out all rows. The guard treats values > 3650 as null
    // (full window) to prevent silent empty results.
    const twoYearsAgo = new Date(Date.now() - 2 * 365 * 86_400_000).toISOString();
    await seedReceipt('77777777-7777-7777-7777-777777777777', 'R-MAX-1', twoYearsAgo);
    await seedReceipt('88888888-8888-8888-8888-888888888888', 'R-MAX-2', new Date().toISOString());

    const rows = await findRecentReceiptsByPartner(
      adapter.asDatabase(),
      PARTNER_ID,
      20,
      Number.MAX_VALUE,
    );

    // Both receipts are returned because Number.MAX_VALUE exceeds the 3650-day
    // cap and falls through to the full-window path (no WHERE clause on posted_at).
    expect(rows.map((r) => r.receipt_number).sort()).toEqual(['R-MAX-1', 'R-MAX-2']);
  });

  it('windowDays = 3651 falls through to full-window (exceeds 3650-day upper bound)', async () => {
    const twoYearsAgo = new Date(Date.now() - 2 * 365 * 86_400_000).toISOString();
    await seedReceipt('99999999-9999-9999-9999-999999999999', 'R-3651', twoYearsAgo);

    const rows = await findRecentReceiptsByPartner(
      adapter.asDatabase(),
      PARTNER_ID,
      20,
      3651,
    );

    // 3651 > 3650 → no date clause → the receipt posted two years ago is included.
    expect(rows.map((r) => r.receipt_number)).toEqual(['R-3651']);
  });
});
