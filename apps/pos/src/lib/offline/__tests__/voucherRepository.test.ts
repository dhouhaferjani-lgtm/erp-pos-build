import { describe, it, expect, vi, beforeEach } from 'vitest';

// CRITICAL — the no-fetch contract for §0.2: cashier-facing voucher and
// receipt lookups must read from local SQLite ONLY. Mock all three network
// layers and assert none is touched by the lookup paths.
vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

import { queryOne, queryAll, execute } from '@/lib/db';
import { apiGet, apiPost } from '@/lib/api';
import { fetch as httpFetch } from '@tauri-apps/plugin-http';
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

const db = {} as import('@tauri-apps/plugin-sql').default;

const VOUCHER_ROW: LocalVoucher = {
  id: 'voucher-uuid-1',
  code: 'V-ABC123',
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
  redeemable_at_terminal_id: 'term-1',
  notes: null,
  synced_at: '2026-04-28T10:00:01+00:00',
};

const RECEIPT_INDEX_ROW: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe('voucherRepository — no-fetch contract (cashier-facing lookups)', () => {
  it('findByCode never invokes any network layer (apiGet, apiPost, plugin-http fetch, globalThis.fetch)', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from cashier-facing lookup');
    });
    vi.mocked(queryOne).mockResolvedValueOnce(VOUCHER_ROW);

    await findByCode(db, 'V-ABC123');

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });

  it('findReceiptByQrToken never invokes any network layer', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from cashier-facing lookup');
    });
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    await findReceiptByQrToken(
      db,
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });

  it('findReceiptByNumber never invokes any network layer', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from cashier-facing lookup');
    });
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    await findReceiptByNumber(db, 'R-0001');

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });

  it('lookup misses (null SQLite result) still never touch the network', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from cashier-facing lookup');
    });
    vi.mocked(queryOne).mockResolvedValue(null);

    expect(await findByCode(db, 'NO-SUCH')).toBeNull();
    expect(await findReceiptByNumber(db, 'NO-SUCH')).toBeNull();

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });
});

describe('findByCode', () => {
  it('returns the voucher when one matches', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(VOUCHER_ROW);

    const result = await findByCode(db, 'V-ABC123');

    expect(result).toEqual(VOUCHER_ROW);
    expect(queryOne).toHaveBeenCalledWith(
      db,
      expect.stringContaining('FROM vouchers'),
      ['V-ABC123'],
    );
  });

  it('returns null when no row matches', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(null);

    const result = await findByCode(db, 'NO-SUCH');

    expect(result).toBeNull();
  });
});

describe('findReceiptByQrToken', () => {
  it('parses v:kid:receipt_uuid:mac and queries by receipt_uuid', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    const result = await findReceiptByQrToken(
      db,
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );

    expect(result).toEqual(RECEIPT_INDEX_ROW);
    expect(queryOne).toHaveBeenCalledWith(
      db,
      expect.stringContaining('FROM receipt_qr_index'),
      ['550e8400-e29b-41d4-a716-446655440000'],
    );
  });

  it('returns null on malformed token (wrong field count)', async () => {
    expect(await findReceiptByQrToken(db, 'not-a-token')).toBeNull();
    expect(await findReceiptByQrToken(db, '1:kid:only-three')).toBeNull();
    expect(await findReceiptByQrToken(db, '')).toBeNull();
    expect(queryOne).not.toHaveBeenCalled();
  });

  it('returns null when the embedded receipt field is not a UUID', async () => {
    expect(
      await findReceiptByQrToken(db, '1:keyabc:not-a-uuid:macblob'),
    ).toBeNull();
    expect(queryOne).not.toHaveBeenCalled();
  });

  it('returns null when the parsed receipt_uuid is not in receipt_qr_index', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(null);

    const result = await findReceiptByQrToken(
      db,
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );

    expect(result).toBeNull();
  });
});

describe('findReceiptByNumber', () => {
  it('returns the indexed row when found', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    const result = await findReceiptByNumber(db, 'R-0001');

    expect(result).toEqual(RECEIPT_INDEX_ROW);
    expect(queryOne).toHaveBeenCalledWith(
      db,
      expect.stringContaining('FROM receipt_qr_index'),
      ['R-0001'],
    );
  });

  it('returns null when not found', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(null);

    const result = await findReceiptByNumber(db, 'NO-SUCH');

    expect(result).toBeNull();
  });
});

describe('upsertVouchers', () => {
  it('is a no-op for an empty array', async () => {
    await upsertVouchers(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('issues a single batched INSERT … ON CONFLICT for a small batch', async () => {
    await upsertVouchers(db, [VOUCHER_ROW]);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('INSERT INTO vouchers');
    expect(sql).toContain('ON CONFLICT(id) DO UPDATE');
    expect(params).toContain('voucher-uuid-1');
    expect(params).toContain('V-ABC123');
  });
});

describe('upsertVoucherLedgerEntries', () => {
  const ENTRY: LocalVoucherLedgerEntry = {
    id: 'ledger-uuid-1',
    voucher_id: 'voucher-uuid-1',
    event: 'Issued',
    amount: '5000',
    currency: 'EUR',
    receipt_id: 'receipt-uuid-1',
    terminal_id: 'term-1',
    user_id: 'user-uuid-1',
    occurred_at: '2026-04-28T10:00:00+00:00',
    sync_status: 'synced',
    sync_error: null,
    synced_at: '2026-04-28T10:00:01+00:00',
  };

  it('is a no-op for an empty array', async () => {
    await upsertVoucherLedgerEntries(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('issues a batched INSERT for a small batch', async () => {
    await upsertVoucherLedgerEntries(db, [ENTRY]);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('INSERT INTO voucher_ledger');
    expect(sql).toContain('ON CONFLICT(id) DO UPDATE');
    expect(params).toContain('ledger-uuid-1');
  });
});

describe('upsertReceiptQrIndexEntries', () => {
  it('is a no-op for an empty array', async () => {
    await upsertReceiptQrIndexEntries(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('issues a batched INSERT for a small batch', async () => {
    await upsertReceiptQrIndexEntries(db, [RECEIPT_INDEX_ROW]);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('INSERT INTO receipt_qr_index');
    expect(sql).toContain('ON CONFLICT(receipt_uuid) DO UPDATE');
    expect(params).toContain('550e8400-e29b-41d4-a716-446655440000');
  });
});

describe('getPendingVoucherLedgerEntries', () => {
  it('returns rows with sync_status = pending', async () => {
    const pendingEntry = {
      id: 'ledger-uuid-2',
      voucher_id: 'voucher-uuid-1',
      event: 'Redeemed',
      amount: '-2500',
      currency: 'EUR',
      receipt_id: 'receipt-uuid-2',
      terminal_id: 'term-1',
      user_id: 'user-uuid-1',
      occurred_at: '2026-04-28T11:00:00+00:00',
      sync_status: 'pending' as const,
      sync_error: null,
      synced_at: null,
    };
    vi.mocked(queryAll).mockResolvedValueOnce([pendingEntry]);

    const rows = await getPendingVoucherLedgerEntries(db);

    expect(rows).toEqual([pendingEntry]);
    expect(queryAll).toHaveBeenCalledWith(
      db,
      expect.stringContaining("sync_status = 'pending'"),
    );
  });
});

describe('markVoucherLedgerEntrySynced / markVoucherLedgerEntryFailed', () => {
  it('marks a ledger entry as synced', async () => {
    await markVoucherLedgerEntrySynced(db, 'ledger-uuid-2');

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('UPDATE voucher_ledger');
    expect(sql).toContain("sync_status = 'synced'");
    expect(params).toEqual(['ledger-uuid-2']);
  });

  it('marks a ledger entry as failed with a reason', async () => {
    await markVoucherLedgerEntryFailed(db, 'ledger-uuid-2', 'network down');

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toContain('UPDATE voucher_ledger');
    expect(sql).toContain("sync_status = 'failed'");
    expect(params).toEqual(['network down', 'ledger-uuid-2']);
  });
});
