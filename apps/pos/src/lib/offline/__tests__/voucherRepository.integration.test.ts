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
