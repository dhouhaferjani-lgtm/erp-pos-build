import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/offline/voucherRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/offline/voucherRepository')>(
    '@/lib/offline/voucherRepository',
  );
  return {
    ...actual,
    upsertVouchers: vi.fn().mockResolvedValue(undefined),
    upsertVoucherLedgerEntries: vi.fn().mockResolvedValue(undefined),
    upsertReceiptQrIndexEntries: vi.fn().mockResolvedValue(undefined),
    getPendingVoucherLedgerEntries: vi.fn().mockResolvedValue([]),
    markVoucherLedgerEntrySynced: vi.fn().mockResolvedValue(undefined),
    markVoucherLedgerEntryFailed: vi.fn().mockResolvedValue(undefined),
  };
});

import {
  pullVouchers,
  pullVoucherLedger,
  pullReceiptQrIndex,
  pushVoucherLedgerEntries,
} from '../syncService';
import { apiGet, apiPost } from '@/lib/api';
import {
  upsertVouchers,
  upsertVoucherLedgerEntries,
  upsertReceiptQrIndexEntries,
  getPendingVoucherLedgerEntries,
  markVoucherLedgerEntrySynced,
  markVoucherLedgerEntryFailed,
  type LocalVoucher,
  type LocalVoucherLedgerEntry,
  type LocalReceiptQrIndexEntry,
} from '@/lib/offline/voucherRepository';
import { logSyncOperation, setSyncMetadata } from '@/lib/db/repositories/syncLogRepository';

const db = {} as import('@tauri-apps/plugin-sql').default;
const TERMINAL_ID = '11111111-1111-1111-1111-111111111111';

const VOUCHER: LocalVoucher = {
  id: 'voucher-uuid-1',
  code: 'V-A',
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
};

const LEDGER: LocalVoucherLedgerEntry = {
  id: 'ledger-uuid-1',
  voucher_id: 'voucher-uuid-1',
  event: 'Issued',
  amount: '5000',
  currency: 'EUR',
  receipt_id: 'receipt-uuid-1',
  terminal_id: TERMINAL_ID,
  user_id: 'user-uuid-1',
  occurred_at: '2026-04-28T10:00:00+00:00',
  sync_status: 'pending',
  sync_error: null,
  synced_at: null,
};

const RECEIPT_INDEX: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:k1:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: TERMINAL_ID,
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  synced_at: '2026-04-28T09:00:05+00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe('pullVouchers', () => {
  it('calls /pos/vouchers/sync and forwards the response to upsertVouchers', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({ vouchers: [VOUCHER] });

    const count = await pullVouchers(db, TERMINAL_ID);

    expect(count).toBe(1);
    expect(apiGet).toHaveBeenCalledWith(
      '/pos/vouchers/sync',
      expect.objectContaining({ terminal_id: TERMINAL_ID }),
    );
    expect(upsertVouchers).toHaveBeenCalledWith(db, [VOUCHER]);
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      'vouchers_last_sync',
      expect.any(String),
    );
  });

  it('does not throw when the backend returns an error (logs instead)', async () => {
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('endpoint not found'));

    const count = await pullVouchers(db, TERMINAL_ID);

    expect(count).toBe(0);
    expect(upsertVouchers).not.toHaveBeenCalled();
    expect(logSyncOperation).toHaveBeenCalledWith(
      db,
      'pull',
      'vouchers',
      null,
      'error',
      'endpoint not found',
    );
  });

  it('handles an empty server response without crashing', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({ vouchers: [] });

    const count = await pullVouchers(db, TERMINAL_ID);

    expect(count).toBe(0);
    // upsertVouchers should be skipped when there's nothing to write
    expect(upsertVouchers).not.toHaveBeenCalled();
  });
});

describe('pullVoucherLedger', () => {
  it('calls /pos/voucher-ledger/sync and upserts entries', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({ entries: [LEDGER] });

    const count = await pullVoucherLedger(db, TERMINAL_ID);

    expect(count).toBe(1);
    expect(apiGet).toHaveBeenCalledWith(
      '/pos/voucher-ledger/sync',
      expect.objectContaining({ terminal_id: TERMINAL_ID }),
    );
    expect(upsertVoucherLedgerEntries).toHaveBeenCalledWith(db, [LEDGER]);
  });

  it('returns 0 on backend error and logs', async () => {
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('boom'));

    const count = await pullVoucherLedger(db, TERMINAL_ID);

    expect(count).toBe(0);
    expect(upsertVoucherLedgerEntries).not.toHaveBeenCalled();
  });
});

describe('pullReceiptQrIndex', () => {
  it('calls /pos/receipts/qr-index and upserts entries', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({ entries: [RECEIPT_INDEX] });

    const count = await pullReceiptQrIndex(db, TERMINAL_ID);

    expect(count).toBe(1);
    expect(apiGet).toHaveBeenCalledWith(
      '/pos/receipts/qr-index',
      expect.objectContaining({ terminal_id: TERMINAL_ID }),
    );
    expect(upsertReceiptQrIndexEntries).toHaveBeenCalledWith(db, [RECEIPT_INDEX]);
  });

  it('returns 0 on backend error and logs', async () => {
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('boom'));

    const count = await pullReceiptQrIndex(db, TERMINAL_ID);

    expect(count).toBe(0);
    expect(upsertReceiptQrIndexEntries).not.toHaveBeenCalled();
  });
});

describe('pushVoucherLedgerEntries', () => {
  it('returns zero counts when there are no pending entries', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([]);

    const result = await pushVoucherLedgerEntries(db);

    expect(result).toEqual({ pushed: 0, failed: 0, errors: [] });
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('posts each pending entry and marks it synced on success', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    vi.mocked(apiPost).mockResolvedValueOnce({});

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(apiPost).toHaveBeenCalledWith('/pos/voucher-ledger/sync', { entries: [LEDGER] });
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, LEDGER.id);
  });

  it('marks the entry failed when the push throws', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('network down'));

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(0);
    expect(result.failed).toBe(1);
    expect(result.errors[0]).toContain('network down');
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalledWith(
      db,
      LEDGER.id,
      'network down',
    );
  });

  it('continues processing remaining entries after one fails', async () => {
    const second: LocalVoucherLedgerEntry = { ...LEDGER, id: 'ledger-uuid-2' };
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER, second]);
    vi.mocked(apiPost)
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce({});

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(1);
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalledWith(db, 'ledger-uuid-1', 'boom');
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, 'ledger-uuid-2');
  });
});
