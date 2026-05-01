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
  partner_id: null,
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

  it('persists partner_id when the server response includes a non-null partner_id (M2)', async () => {
    // Codex review M2: partner_id must flow from wire payload → local SQLite row.
    const partnerId = '123e4567-e89b-12d3-a456-426614174000';
    const entryWithPartner: LocalReceiptQrIndexEntry = {
      ...RECEIPT_INDEX,
      receipt_uuid: 'aaaaaaaa-0000-0000-0000-000000000001',
      partner_id: partnerId,
    };

    vi.mocked(apiGet).mockResolvedValueOnce({ entries: [entryWithPartner] });

    const count = await pullReceiptQrIndex(db, TERMINAL_ID);

    expect(count).toBe(1);
    expect(upsertReceiptQrIndexEntries).toHaveBeenCalledWith(
      db,
      expect.arrayContaining([
        expect.objectContaining({ partner_id: partnerId }),
      ]),
    );
  });

  it('persists partner_id as null when the server response has partner_id null (M2)', async () => {
    // Codex review M2: null partner_id must round-trip correctly.
    const entryNoPartner: LocalReceiptQrIndexEntry = {
      ...RECEIPT_INDEX,
      receipt_uuid: 'aaaaaaaa-0000-0000-0000-000000000002',
      partner_id: null,
    };

    vi.mocked(apiGet).mockResolvedValueOnce({ entries: [entryNoPartner] });

    const count = await pullReceiptQrIndex(db, TERMINAL_ID);

    expect(count).toBe(1);
    expect(upsertReceiptQrIndexEntries).toHaveBeenCalledWith(
      db,
      expect.arrayContaining([
        expect.objectContaining({ partner_id: null }),
      ]),
    );
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

  it('posts each pending entry and marks it synced on per-entry success', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    // Server returns the controller's structured 200 shape with per-entry
    // status. apiPost unwraps response.data.data, so the test sees the
    // inner object directly.
    vi.mocked(apiPost).mockResolvedValueOnce({
      results: [{ id: LEDGER.id, status: 'synced', error: null }],
      synced: 1,
      duplicates: 0,
      failed: 0,
    });

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(apiPost).toHaveBeenCalledWith('/pos/voucher-ledger/sync', { entries: [LEDGER] });
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, LEDGER.id);
  });

  it('treats per-entry status="duplicate" as a benign success and marks synced', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      results: [{ id: LEDGER.id, status: 'duplicate', error: null }],
      synced: 0,
      duplicates: 1,
      failed: 0,
    });

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, LEDGER.id);
    expect(markVoucherLedgerEntryFailed).not.toHaveBeenCalled();
  });

  it('marks the entry failed when the push throws (HTTP-level error)', async () => {
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

  // B5-fix audit Minor 2 (2026-05-01): the controller returns 200 with
  // per-entry `{status: 'failed', error: '<reason>'}` for entries the server
  // could not ingest (e.g. `receipt_id_required_for_redemption`). The
  // previous client implementation only inspected HTTP-level errors and
  // unconditionally marked the row synced — silent-drop bug. This test locks
  // the new contract: per-entry failures keep the row pending and surface
  // the per-entry error reason.
  it('marks the entry failed when the response carries per-entry status="failed" (Minor 2 silent-drop fix)', async () => {
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      results: [{
        id: LEDGER.id,
        status: 'failed',
        error: 'receipt_id_required_for_redemption',
      }],
      synced: 0,
      duplicates: 0,
      failed: 1,
    });

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(0);
    expect(result.failed).toBe(1);
    expect(result.errors[0]).toContain('receipt_id_required_for_redemption');
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalledWith(
      db,
      LEDGER.id,
      'receipt_id_required_for_redemption',
    );
    // The entry MUST NOT have been marked synced — that was the silent-drop bug.
    expect(markVoucherLedgerEntrySynced).not.toHaveBeenCalled();
  });

  it('handles a mixed-status response: success + failure + duplicate per entry', async () => {
    const second: LocalVoucherLedgerEntry = { ...LEDGER, id: 'ledger-uuid-2' };
    const third: LocalVoucherLedgerEntry = { ...LEDGER, id: 'ledger-uuid-3' };
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER, second, third]);
    // Each entry is posted in its own request (one entry per call) per the
    // current loop shape. We mock three separate responses.
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        results: [{ id: LEDGER.id, status: 'synced', error: null }],
        synced: 1, duplicates: 0, failed: 0,
      })
      .mockResolvedValueOnce({
        results: [{ id: 'ledger-uuid-2', status: 'failed', error: 'voucher_not_found' }],
        synced: 0, duplicates: 0, failed: 1,
      })
      .mockResolvedValueOnce({
        results: [{ id: 'ledger-uuid-3', status: 'duplicate', error: null }],
        synced: 0, duplicates: 1, failed: 0,
      });

    const result = await pushVoucherLedgerEntries(db);

    // synced + duplicate count toward pushed; failed alone counts toward failed.
    expect(result.pushed).toBe(2);
    expect(result.failed).toBe(1);
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, LEDGER.id);
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalledWith(
      db,
      'ledger-uuid-2',
      'voucher_not_found',
    );
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, 'ledger-uuid-3');
  });

  it('continues processing remaining entries after one HTTP-throws', async () => {
    const second: LocalVoucherLedgerEntry = { ...LEDGER, id: 'ledger-uuid-2' };
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER, second]);
    vi.mocked(apiPost)
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce({
        results: [{ id: 'ledger-uuid-2', status: 'synced', error: null }],
        synced: 1, duplicates: 0, failed: 0,
      });

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(1);
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalledWith(db, 'ledger-uuid-1', 'boom');
    expect(markVoucherLedgerEntrySynced).toHaveBeenCalledWith(db, 'ledger-uuid-2');
  });

  it('treats a malformed response (missing results array) as a per-entry failure', async () => {
    // Defense-in-depth: if a stale server returns the old shape `{}` (no
    // results field), we MUST NOT mark the row synced. The previous code
    // unconditionally marked it synced, so any stale server would silently
    // drop the row. Defense fails the row safely.
    vi.mocked(getPendingVoucherLedgerEntries).mockResolvedValueOnce([LEDGER]);
    vi.mocked(apiPost).mockResolvedValueOnce({});

    const result = await pushVoucherLedgerEntries(db);

    expect(result.pushed).toBe(0);
    expect(result.failed).toBe(1);
    expect(markVoucherLedgerEntrySynced).not.toHaveBeenCalled();
    expect(markVoucherLedgerEntryFailed).toHaveBeenCalled();
  });
});
