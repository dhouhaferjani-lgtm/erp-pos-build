import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/offlineReceiptRepository')>('@/lib/db/repositories/offlineReceiptRepository');
  return {
    ...actual,
    getPendingReceiptsForSync: vi.fn(),
    updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
    incrementRetryCount: vi.fn().mockResolvedValue(undefined),
    cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
    cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
    setServerReceiptId: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  getPendingCashDrawerOps: vi.fn().mockResolvedValue([]),
  updateCashDrawerOpStatus: vi.fn().mockResolvedValue(undefined),
  cleanupSyncedCashDrawerOps: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn().mockResolvedValue(undefined),
  deleteProducts: vi.fn().mockResolvedValue(undefined),
  // C2 Day 1 — pullActiveMenu now calls reconcileMenuProducts to flatten
  // the just-pulled menu into the products table; mock returns void so
  // the existing sync tests stay green.
  reconcileMenuProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  upsertPaymentMethods: vi.fn().mockResolvedValue(undefined),
  upsertPaymentRepositories: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  upsertOperators: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/queuedPinUpdateRepository', () => ({
  getPendingPinUpdates: vi.fn().mockResolvedValue([]),
  markPinUpdateSynced: vi.fn().mockResolvedValue(undefined),
  markPinUpdateFailed: vi.fn().mockResolvedValue(undefined),
  enqueuePinUpdate: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/tableRepository', () => ({
  upsertFloors: vi.fn().mockResolvedValue(undefined),
  upsertTables: vi.fn().mockResolvedValue(undefined),
  deleteFloors: vi.fn().mockResolvedValue(undefined),
  deleteTables: vi.fn().mockResolvedValue(undefined),
  getAllFloorsWithTables: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/db/repositories/menuRepository', () => ({
  upsertMenuCategories: vi.fn().mockResolvedValue(undefined),
  upsertMenuCategoryItems: vi.fn().mockResolvedValue(undefined),
  deleteMenuCategories: vi.fn().mockResolvedValue(undefined),
  deleteMenuCategoryItems: vi.fn().mockResolvedValue(undefined),
  getActiveMenu: vi.fn().mockResolvedValue({ categories: [] }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    }),
  },
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  upsertTerminalState: vi.fn().mockResolvedValue(undefined),
  upsertZChainState: vi.fn().mockResolvedValue(undefined),
  advanceHashChain: vi.fn().mockResolvedValue(undefined),
  FiscalRegressionError: class FiscalRegressionError extends Error {
    constructor(
      readonly terminalId: string,
      readonly op: string,
      readonly before: number,
      readonly after: number,
    ) {
      super(`${op} regression: ${before} -> ${after}`);
      this.name = 'FiscalRegressionError';
    }
  },
}));

vi.mock('@/lib/db/repositories/zReportRepository', () => ({
  getUnsyncedZReports: vi.fn().mockResolvedValue([]),
  markZReportSynced: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeGenesisHash: vi.fn().mockResolvedValue('genesis-hash-abc123'),
}));

vi.mock('@/lib/offline/voucherRepository', () => ({
  upsertVouchers: vi.fn().mockResolvedValue(undefined),
  upsertVoucherLedgerEntries: vi.fn().mockResolvedValue(undefined),
  upsertReceiptQrIndexEntries: vi.fn().mockResolvedValue(undefined),
  getPendingVoucherLedgerEntries: vi.fn().mockResolvedValue([]),
  markVoucherLedgerEntrySynced: vi.fn().mockResolvedValue(undefined),
  markVoucherLedgerEntryFailed: vi.fn().mockResolvedValue(undefined),
}));

import {
  pushOfflineReceipts,
  pullProducts,
  pullPaymentConfig,
  pullOperatorPins,
  pullTerminalState,
  runFullSync,
} from '../syncService';
import { apiGet, apiPost } from '@/lib/api';
import {
  getPendingReceiptsForSync,
  updateReceiptStatus,
  incrementRetryCount,
  setServerReceiptId,
} from '@/lib/db/repositories/offlineReceiptRepository';
import { upsertProducts, deleteProducts } from '@/lib/db/repositories/productRepository';
import { upsertTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { computeGenesisHash } from '@/lib/fiscal/hashService';
import { makeOfflineReceipt } from '@/test/helpers';

function makeMockDb() {
  return {} as import('@tauri-apps/plugin-sql').default;
}

/**
 * Helper: build a well-formed batch sync response for one or more results.
 */
function syncBatchResponse(items: Array<{
  idempotency_key: string;
  status: 'synced' | 'duplicate' | 'failed' | 'chain_broken';
  receipt_id?: string | null;
  error?: string | null;
  terminal_last_hash?: string | null;
  terminal_hash_sequence?: number | null;
}>) {
  const synced = items.filter((i) => i.status === 'synced').length;
  const duplicates = items.filter((i) => i.status === 'duplicate').length;
  const failed = items.filter((i) => i.status === 'failed' || i.status === 'chain_broken').length;
  return {
    results: items.map((i) => ({
      idempotency_key: i.idempotency_key,
      status: i.status,
      receipt_id: i.receipt_id ?? 'srv-' + i.idempotency_key,
      server_fiscal_hash: 'hash-' + i.idempotency_key,
      error: i.error ?? null,
      terminal_last_hash: i.terminal_last_hash ?? null,
      terminal_hash_sequence: i.terminal_hash_sequence ?? null,
    })),
    total: items.length,
    synced,
    duplicates,
    failed,
  };
}

describe('syncService', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
  });

  describe('pushOfflineReceipts', () => {
    it('pushes all pending receipts successfully', async () => {
      const receipts = [
        makeOfflineReceipt({ id: 'r1', idempotency_key: 'idem-r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', idempotency_key: 'idem-r2', hash_sequence: 2 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);
      vi.mocked(apiPost)
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r1', status: 'synced' }]))
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r2', status: 'synced' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(2);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
    });

    it('marks receipt as syncing before pushing', async () => {
      const receipt = makeOfflineReceipt({ id: 'r1', idempotency_key: 'idem-r1' });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r1', status: 'synced' }]));

      await pushOfflineReceipts(db);

      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'r1', 'syncing');
      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'r1', 'synced');
    });

    it('increments retry count on failure', async () => {
      const receipt = makeOfflineReceipt({ id: 'r1' });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([receipt]);
      vi.mocked(apiPost).mockRejectedValue(new Error('Network error'));

      const result = await pushOfflineReceipts(db);

      expect(result.failed).toBe(1);
      expect(incrementRetryCount).toHaveBeenCalledWith(db, 'r1');
      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'r1', 'failed', 'Network error');
    });

    it('halts sync on hash chain break error', async () => {
      const receipts = [
        makeOfflineReceipt({ id: 'r1', idempotency_key: 'idem-r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', idempotency_key: 'idem-r2', hash_sequence: 2 }),
        makeOfflineReceipt({ id: 'r3', idempotency_key: 'idem-r3', hash_sequence: 3 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);

      vi.mocked(apiPost)
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r1', status: 'synced' }]))
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r2', status: 'chain_broken', error: 'hash mismatch' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(true);
      expect(apiPost).toHaveBeenCalledTimes(2);
      expect(result.errors).toContain('CHAIN_BREAK at receipt ' + receipts[1]!.receipt_number);
    });

    it('continues pushing after non-chain-break error', async () => {
      const receipts = [
        makeOfflineReceipt({ id: 'r1', idempotency_key: 'idem-r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', idempotency_key: 'idem-r2', hash_sequence: 2 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);

      vi.mocked(apiPost)
        .mockRejectedValueOnce(new Error('Timeout'))
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-r2', status: 'synced' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(false);
      expect(apiPost).toHaveBeenCalledTimes(2);
    });

    it('returns empty results when no pending receipts', async () => {
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([]);

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
    });

    it('T0.3: FetchTimeoutError reverts the receipt to pending without incrementing retry_count', async () => {
      // T0.3 round-1 Codex BLOCKER fix: a read-timeout means "unknown sync
      // state" (server may have committed). The push loop must NOT mark
      // the receipt as failed or bump retry_count — those bumps drive the
      // dead-letter path at retry_count >= MAX_SYNC_RETRIES (5), which
      // would saturate even when the server has been accepting the POSTs
      // and only the responses are being dropped on the wire. The next
      // sync tick re-pushes with T0.2's stable idempotency key; the
      // server-side dedup-on-disk returns 'duplicate' (treated as success
      // upstream) or accepts fresh.
      const { FetchTimeoutError } = await import('@/lib/fetchWithTimeout');

      const receipt = makeOfflineReceipt({
        id: 'r-timeout',
        idempotency_key: 'idem-timeout',
        hash_sequence: 1,
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockRejectedValueOnce(
        new FetchTimeoutError('https://x.test/pos/receipts/sync', 30_000, 'POST'),
      );

      const result = await pushOfflineReceipts(db);

      // Loop result: NOT counted as failed (no banner / error to user).
      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);

      // Receipt status: 'pending' (not 'failed'). Last call to
      // updateReceiptStatus for this receipt should be 'pending'.
      const statusCalls = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-timeout',
      );
      expect(statusCalls.length).toBeGreaterThan(0);
      const lastStatus = statusCalls[statusCalls.length - 1]![2];
      expect(lastStatus).toBe('pending');

      // Critical invariant: retry_count NOT bumped (this is what protects
      // the dead-letter cap from saturating on transient timeouts).
      const incrementCallsForReceipt = vi.mocked(incrementRetryCount).mock.calls.filter(
        (c) => c[1] === 'r-timeout',
      );
      expect(incrementCallsForReceipt).toHaveLength(0);
    });

    it('Codex review B3: receiptToPayload preserves method_code, instrument_type, and instrument_serial from payments_json', async () => {
      const voucherReceipt = makeOfflineReceipt({
        id: 'r-voucher',
        idempotency_key: 'idem-voucher',
        hash_sequence: 1,
        fiscal_schema_version: 3,
        payments_json: JSON.stringify([
          {
            payment_method_id: 'pm-cash',
            repository_id: 'repo-cash',
            amount: '10.00',
            card_last_four: null,
            transaction_reference: null,
            method_code: 'cash',
            instrument_type: null,
            instrument_serial: null,
          },
          {
            payment_method_id: 'pm-voucher',
            repository_id: 'repo-voucher',
            amount: '15.00',
            card_last_four: null,
            transaction_reference: null,
            method_code: 'store_voucher',
            instrument_type: 'store_voucher',
            instrument_serial: 'SV-2026-0042',
          },
        ]),
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([voucherReceipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        syncBatchResponse([{ idempotency_key: 'idem-voucher', status: 'synced' }]),
      );

      await pushOfflineReceipts(db);

      const calls = vi.mocked(apiPost).mock.calls;
      expect(calls).toHaveLength(1);
      type PostBody = {
        receipts: Array<{
          payments: Array<{
            method_code: string;
            instrument_type: string | null;
            instrument_serial: string | null;
          }>;
        }>;
      };
      const body = calls[0]![1] as PostBody;
      const payments = body.receipts[0]!.payments;
      expect(payments).toHaveLength(2);
      expect(payments[0]!.method_code).toBe('cash');
      expect(payments[0]!.instrument_type).toBeNull();
      expect(payments[0]!.instrument_serial).toBeNull();
      expect(payments[1]!.method_code).toBe('store_voucher');
      expect(payments[1]!.instrument_type).toBe('store_voucher');
      expect(payments[1]!.instrument_serial).toBe('SV-2026-0042');
    });

    it('Codex review B1: stamps fiscal_schema_version on every push payload', async () => {
      const v2Receipt = makeOfflineReceipt({
        id: 'r-v2',
        idempotency_key: 'idem-v2',
        hash_sequence: 1,
        fiscal_schema_version: 2,
      });
      const v3Receipt = makeOfflineReceipt({
        id: 'r-v3',
        idempotency_key: 'idem-v3',
        hash_sequence: 2,
        fiscal_schema_version: 3,
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([v2Receipt, v3Receipt]);
      vi.mocked(apiPost)
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-v2', status: 'synced' }]))
        .mockResolvedValueOnce(syncBatchResponse([{ idempotency_key: 'idem-v3', status: 'synced' }]));

      await pushOfflineReceipts(db);

      // Inspect the bodies passed to apiPost; both must carry the field.
      const calls = vi.mocked(apiPost).mock.calls;
      expect(calls).toHaveLength(2);
      const firstBody = calls[0]![1] as { receipts: Array<{ fiscal_schema_version: number }> };
      const secondBody = calls[1]![1] as { receipts: Array<{ fiscal_schema_version: number }> };
      expect(firstBody.receipts[0]!.fiscal_schema_version).toBe(2);
      expect(secondBody.receipts[0]!.fiscal_schema_version).toBe(3);
    });

    it('captures server_receipt_id from sync response and writes it to SQLite', async () => {
      const receipt = makeOfflineReceipt({
        id: 'client-uuid-1',
        idempotency_key: 'idem-1',
        status: 'pending',
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);

      vi.mocked(apiPost).mockResolvedValueOnce({
        results: [{
          idempotency_key: 'idem-1',
          status: 'synced',
          receipt_id: 'server-uuid-99',
          server_fiscal_hash: 'hash-99',
          error: null,
        }],
        total: 1,
        synced: 1,
        duplicates: 0,
        failed: 0,
      });

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(setServerReceiptId).toHaveBeenCalledWith(expect.anything(), 'idem-1', 'server-uuid-99');
    });

    it('setServerReceiptId failure does not clobber synced status or decrement pushed count', async () => {
      const receipt = makeOfflineReceipt({
        id: 'r-writeback',
        idempotency_key: 'idem-writeback',
        status: 'pending',
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce({
        results: [{
          idempotency_key: 'idem-writeback',
          status: 'synced',
          receipt_id: 'server-uuid-wb',
          server_fiscal_hash: 'hash-wb',
          error: null,
        }],
        total: 1, synced: 1, duplicates: 0, failed: 0,
      });
      vi.mocked(setServerReceiptId).mockRejectedValueOnce(new Error('SQLite write error'));

      const result = await pushOfflineReceipts(db);

      // Receipt must be counted as pushed, not failed
      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(0);
      // Status must remain 'synced' — the last call to updateReceiptStatus for this receipt should be 'synced'
      const statusCalls = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-writeback',
      );
      const finalStatus = statusCalls[statusCalls.length - 1]![2];
      expect(finalStatus).toBe('synced');
    });

    it('malformed payments_json falls back to synthesized entry and warns', async () => {
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const receipt = makeOfflineReceipt({
        idempotency_key: 'idem-corrupt',
        payments_json: 'not-json',
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce({
        results: [{ idempotency_key: 'idem-corrupt', status: 'synced', receipt_id: 'srv-c', server_fiscal_hash: 'h', error: null }],
        total: 1, synced: 1, duplicates: 0, failed: 0,
      });

      await pushOfflineReceipts(db);

      const callArgs = vi.mocked(apiPost).mock.calls[0]![1] as { receipts: Record<string, unknown>[] };
      const payload = callArgs['receipts'][0]!;
      expect(Array.isArray(payload['payments'])).toBe(true);
      expect((payload['payments'] as unknown[]).length).toBe(1);
      expect((payload['payments'] as Record<string, unknown>[])[0]!['payment_method_id']).toBe(receipt.payment_method_id);
      expect(warnSpy).toHaveBeenCalledWith(
        expect.stringContaining('malformed payments_json'),
        expect.anything(),
      );

      warnSpy.mockRestore();
    });

    it('sends payments[], consumption_mode, table_id in sync payload', async () => {
      const receipt = makeOfflineReceipt({
        idempotency_key: 'idem-fnb',
        payments_json: '[{"payment_method_id":"pm-1","repository_id":"repo-1","amount":"30.00"}]',
        consumption_mode: 'SUR_PLACE',
        table_id: 'table-5',
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce({
        results: [{ idempotency_key: 'idem-fnb', status: 'synced', receipt_id: 'srv-1', server_fiscal_hash: 'h', error: null }],
        total: 1, synced: 1, duplicates: 0, failed: 0,
      });

      await pushOfflineReceipts(db);

      const callArgs = vi.mocked(apiPost).mock.calls[0]![1] as { receipts: Record<string, unknown>[] };
      const payload = callArgs['receipts'][0]!;
      // Codex review B3 (2026-04-30): the legacy `makeOfflineReceipt` helper
      // emits a payments_json row without method_code / instrument fields
      // (mimicking pre-B3 queued receipts). The sync layer's defensive
      // parser coerces method_code to '' and instrument fields to null —
      // this is the expected shape on the wire.
      expect(payload['payments']).toEqual([
        {
          payment_method_id: 'pm-1',
          repository_id: 'repo-1',
          amount: '30.00',
          card_last_four: null,
          transaction_reference: null,
          method_code: '',
          instrument_type: null,
          instrument_serial: null,
        },
      ]);
      expect(payload['consumption_mode']).toBe('SUR_PLACE');
      expect(payload['table_id']).toBe('table-5');
    });

    it('reconciles local hash_sequence from server terminal_last_hash after each sync', async () => {
      const receipt = makeOfflineReceipt({
        idempotency_key: 'key-1',
        hash_sequence: 7,
        fiscal_hash: 'client-hash-7',
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([receipt]);
      vi.mocked(apiPost).mockResolvedValue(
        syncBatchResponse([
          {
            idempotency_key: 'key-1',
            status: 'synced',
            receipt_id: 'server-uuid-1',
            // New fields — the POS must consume them.
            terminal_last_hash: 'server-hash-7',
            terminal_hash_sequence: 7,
          },
        ]),
      );

      // Spy on advanceHashChain — used to rewrite local last_hash to match server.
      const { advanceHashChain } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );

      await pushOfflineReceipts(db);

      expect(advanceHashChain).toHaveBeenCalledWith(
        expect.anything(),
        receipt.terminal_id,
        'server-hash-7',
        7,
      );
    });

    it('T0.4: server-duplicate-with-advance reconciles local chain WITHOUT chain-break alert', async () => {
      // The post-T0.3 timeout-revert path lands here: a receipt that returned
      // FetchTimeoutError on the first POST gets reverted to 'pending' (no
      // retry-count bump), the next sync tick re-pushes it with the same
      // T0.2-stable idempotency key, and the server's dedup-on-disk responds
      // status='duplicate' carrying the existing receipt's chain anchor
      // (`terminal_last_hash` + `terminal_hash_sequence`). The client MUST
      // (1) advance the local hash chain to match the server's anchor,
      // (2) flip the local receipt to 'synced',
      // (3) writeback the server-canonical receipt_id,
      // (4) NOT raise a chain-break alert and NOT append to errors[].
      //
      // The positive equality on `advanceHashChain` arguments is the
      // load-bearing assertion (Codex Section 6.a/e/f preempt): without it,
      // a regression where the duplicate branch silently skips the reconcile
      // would still show `chainBreak === false` and pass vacuously.
      const receipt = makeOfflineReceipt({
        id: 'r-dup-advance',
        idempotency_key: 'idem-dup-advance',
        terminal_id: 'terminal-1',
        hash_sequence: 10,
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        syncBatchResponse([
          {
            idempotency_key: 'idem-dup-advance',
            status: 'duplicate',
            receipt_id: 'srv-uuid-N+1',
            terminal_last_hash: 'h-N+1',
            terminal_hash_sequence: 11,
          },
        ]),
      );

      const { advanceHashChain } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );

      const result = await pushOfflineReceipts(db);

      // Loop result: success, no chain-break, no errors.
      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);

      // Local receipt: 'synced' (not 'failed', not still 'syncing').
      // The receipt receives 'syncing' first then 'synced'; assert the LAST
      // status update is 'synced' so a regression that flipped the order
      // (e.g. set 'failed' after 'synced') would fail the test.
      const statusCalls = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-dup-advance',
      );
      expect(statusCalls.length).toBeGreaterThan(0);
      expect(statusCalls[statusCalls.length - 1]![2]).toBe('synced');

      // Server-canonical receipt id is written back so HomePage can switch
      // from local SQLite print to the richer API receipt.
      expect(setServerReceiptId).toHaveBeenCalledWith(
        expect.anything(),
        'idem-dup-advance',
        'srv-uuid-N+1',
      );

      // The load-bearing positive assertion: local hash chain advanced to
      // server's anchor. Without this, the cashier's next sale would chain
      // off a stale local last_hash and break the chain server-side on the
      // next push.
      expect(advanceHashChain).toHaveBeenCalledWith(
        expect.anything(),
        'terminal-1',
        'h-N+1',
        11,
      );

      // Defense-in-depth: retry_count must NOT bump on a successful
      // reconcile (analog to T0.3's no-retry-bump-on-timeout discipline).
      const incrementCalls = vi.mocked(incrementRetryCount).mock.calls.filter(
        (c) => c[1] === 'r-dup-advance',
      );
      expect(incrementCalls).toHaveLength(0);
    });

    it('T0.4: server-chain-broken with no reconciliation data still raises chain-break (negative control)', async () => {
      // The backend's `SyncReceiptResult::chainBroken(...)` factory always
      // emits `terminal_last_hash = null` and `terminal_hash_sequence = null`.
      // In this shape the client has no anchor to reconcile against — the
      // existing alarm path MUST fire so the operator can resolve manually.
      // T0.4 must NOT regress this defensive behavior while widening the
      // duplicate-with-advance happy path.
      const receipt = makeOfflineReceipt({
        id: 'r-chain-broken',
        idempotency_key: 'idem-chain-broken',
        terminal_id: 'terminal-1',
        hash_sequence: 10,
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        syncBatchResponse([
          {
            idempotency_key: 'idem-chain-broken',
            status: 'chain_broken',
            error: 'genuine divergence',
            terminal_last_hash: null,
            terminal_hash_sequence: null,
          },
        ]),
      );

      const { advanceHashChain } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );

      const result = await pushOfflineReceipts(db);

      // Alarm path: chain-break flag flipped, error in result.errors,
      // receipt counted as failed, retry_count bumped.
      expect(result.chainBreak).toBe(true);
      expect(result.failed).toBe(1);
      expect(result.pushed).toBe(0);
      expect(result.errors).toContain(`CHAIN_BREAK at receipt ${receipt.receipt_number}`);

      // Receipt status is 'failed' (the loop halted before any 'synced'
      // could land).
      const statusCalls = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-chain-broken',
      );
      expect(statusCalls.length).toBeGreaterThan(0);
      expect(statusCalls[statusCalls.length - 1]![2]).toBe('failed');
      expect(incrementRetryCount).toHaveBeenCalledWith(expect.anything(), 'r-chain-broken');

      // No reconcile attempt — without an anchor the helper cannot run.
      expect(advanceHashChain).not.toHaveBeenCalled();
    });

    it('T0.4: server returns duplicate with sequence LOWER than local — local is authoritative, do NOT retreat', async () => {
      // The offline-first invariant: once a terminal's hash_sequence has
      // been seeded locally, the client never accepts a server-driven
      // regression (server BEHIND local). The duplicate branch passes the
      // server's lower anchor to advanceHashChain, which throws
      // FiscalRegressionError; the catch swallows it with a "reconcile
      // skipped (local ahead)" log and the loop continues treating the
      // receipt as success (the duplicate IS a successful sync — local just
      // doesn't retreat its chain pointer).
      const { FiscalRegressionError } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );
      const { advanceHashChain } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );
      vi.mocked(advanceHashChain).mockRejectedValueOnce(
        new FiscalRegressionError('terminal-1', 'advanceHashChain', 10, 5),
      );

      const receipt = makeOfflineReceipt({
        id: 'r-dup-behind',
        idempotency_key: 'idem-dup-behind',
        terminal_id: 'terminal-1',
        hash_sequence: 10,
      });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        syncBatchResponse([
          {
            idempotency_key: 'idem-dup-behind',
            status: 'duplicate',
            receipt_id: 'srv-uuid-old',
            terminal_last_hash: 'h-stale',
            terminal_hash_sequence: 5,
          },
        ]),
      );

      const result = await pushOfflineReceipts(db);

      // Loop result: success — duplicate is a valid sync outcome.
      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);

      // Production code DID call advanceHashChain with the server's lower
      // anchor — the helper's regression guard is what protects us.
      expect(advanceHashChain).toHaveBeenCalledWith(
        expect.anything(),
        'terminal-1',
        'h-stale',
        5,
      );

      // Receipt is still flipped to 'synced' — the duplicate path treats
      // the receipt as successfully reconciled even when local is ahead.
      const statusCalls = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-dup-behind',
      );
      expect(statusCalls[statusCalls.length - 1]![2]).toBe('synced');
    });

    it('T0.4 integration: post-T0.3 timeout-revert → next-tick re-sync → server-duplicate → reconcile', async () => {
      // The end-to-end chain that motivates T0.4: a transient network drop
      // on a successful server commit. The first POST commits server-side
      // but the response is dropped on the wire; FetchTimeoutError fires;
      // T0.3 reverts the receipt to 'pending' (no retry-count bump). The
      // next sync tick re-pushes with the SAME idempotency key (T0.2-stable);
      // the server's dedup-on-disk returns 'duplicate' carrying the existing
      // receipt's terminal_last_hash + terminal_hash_sequence; T0.4's
      // reconcile path advances the local chain to match server WITHOUT a
      // chain-break alert.
      //
      // This integration test exercises the REAL syncService composition
      // across two ticks — not a single mocked branch. It is the canary
      // that detects regressions where T0.3's pending-revert and T0.4's
      // duplicate-with-advance ever drift apart.
      const { FetchTimeoutError } = await import('@/lib/fetchWithTimeout');
      const { advanceHashChain } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );

      const receipt = makeOfflineReceipt({
        id: 'r-e2e',
        idempotency_key: 'idem-e2e',
        terminal_id: 'terminal-1',
        hash_sequence: 10,
      });

      // ── Tick 1 ──────────────────────────────────────────────────────────
      // Receipt is pending; server times out (real-world: server committed,
      // response dropped on wire).
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockRejectedValueOnce(
        new FetchTimeoutError('https://api.test/pos/receipts/sync', 30_000, 'POST'),
      );

      const tick1 = await pushOfflineReceipts(db);

      // T0.3 contract: tick1 is a no-op to the cashier — no banner, no
      // chain-break alert.
      expect(tick1.pushed).toBe(0);
      expect(tick1.failed).toBe(0);
      expect(tick1.errors).toHaveLength(0);
      expect(tick1.chainBreak).toBe(false);

      // Receipt was reverted to 'pending' (NOT 'failed') so the next tick
      // picks it up.
      const statusCallsAfterTick1 = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-e2e',
      );
      expect(statusCallsAfterTick1.length).toBeGreaterThan(0);
      expect(statusCallsAfterTick1[statusCallsAfterTick1.length - 1]![2]).toBe('pending');

      // T0.3 invariant: retry_count NOT bumped on a transient timeout (this
      // is what protects the dead-letter cap from saturating).
      const incrementCallsAfterTick1 = vi.mocked(incrementRetryCount).mock.calls.filter(
        (c) => c[1] === 'r-e2e',
      );
      expect(incrementCallsAfterTick1).toHaveLength(0);

      // No reconcile yet — server delivered no anchor (response was dropped).
      expect(advanceHashChain).not.toHaveBeenCalled();

      // ── Tick 2 ──────────────────────────────────────────────────────────
      // Scheduler re-runs; the same 'pending' receipt re-pushes with the
      // same idempotency key. Server dedups on the key and returns
      // 'duplicate' with the existing receipt's chain anchor.
      vi.mocked(getPendingReceiptsForSync).mockResolvedValueOnce([receipt]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        syncBatchResponse([
          {
            idempotency_key: 'idem-e2e',
            status: 'duplicate',
            receipt_id: 'srv-uuid-from-first-commit',
            terminal_last_hash: 'h-N+1',
            terminal_hash_sequence: 11,
          },
        ]),
      );

      const tick2 = await pushOfflineReceipts(db);

      // T0.4 reconcile contract: tick2 is the recovery — receipt flips to
      // 'synced', local chain advances to server's anchor, no alert.
      expect(tick2.pushed).toBe(1);
      expect(tick2.failed).toBe(0);
      expect(tick2.errors).toHaveLength(0);
      expect(tick2.chainBreak).toBe(false);

      // Receipt's last status is 'synced' (the late-arriving success).
      const statusCallsAfterTick2 = vi.mocked(updateReceiptStatus).mock.calls.filter(
        (c) => c[1] === 'r-e2e',
      );
      expect(statusCallsAfterTick2[statusCallsAfterTick2.length - 1]![2]).toBe('synced');

      // server_receipt_id from the first commit is written back so HomePage
      // can switch from local SQLite print to the richer API receipt.
      expect(setServerReceiptId).toHaveBeenCalledWith(
        expect.anything(),
        'idem-e2e',
        'srv-uuid-from-first-commit',
      );

      // The load-bearing positive assertion: local chain advanced to N+1
      // (10 → 11). Without this, the cashier's next sale would chain off a
      // stale local last_hash and break the chain server-side on the next
      // push.
      expect(advanceHashChain).toHaveBeenCalledWith(
        expect.anything(),
        'terminal-1',
        'h-N+1',
        11,
      );

      // Defense-in-depth: retry_count NEVER bumped across both ticks. The
      // dead-letter cap stays clean — a transient timeout that recovered
      // via duplicate-dedup must not consume a retry slot.
      const incrementCallsTotal = vi.mocked(incrementRetryCount).mock.calls.filter(
        (c) => c[1] === 'r-e2e',
      );
      expect(incrementCallsTotal).toHaveLength(0);
    });
  });

  describe('pullProducts', () => {
    it('pulls products and upserts them', async () => {
      const products = [
        { id: 'p1', name: 'Widget', sku: 'W-001', sale_price: '10.00', stock_quantity: 50 },
      ];
      vi.mocked(apiGet).mockResolvedValue(products);

      const count = await pullProducts(db);

      expect(count).toBe(1);
      expect(upsertProducts).toHaveBeenCalledWith(db, products);
    });

    it('handles paginated response format', async () => {
      const products = [
        { id: 'p1', name: 'Widget', sku: 'W-001', sale_price: '10.00', stock_quantity: 50 },
      ];
      vi.mocked(apiGet).mockResolvedValue({ data: products });

      const count = await pullProducts(db);

      expect(count).toBe(1);
    });

    it('returns 0 when no products returned', async () => {
      vi.mocked(apiGet).mockResolvedValue([]);

      const count = await pullProducts(db);

      expect(count).toBe(0);
      expect(upsertProducts).not.toHaveBeenCalled();
    });

    it('processes deleted_ids from the tombstone response', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        data: [{ id: 'p1', name: 'Widget', sku: 'W-001', sale_price: '10.00', stock_quantity: 50 }],
        deleted_ids: ['gone-1', 'gone-2'],
      });

      const count = await pullProducts(db);

      expect(count).toBe(1);
      expect(upsertProducts).toHaveBeenCalledWith(db, expect.any(Array));
      expect(deleteProducts).toHaveBeenCalledWith(db, ['gone-1', 'gone-2']);
    });

    it('does not call deleteProducts when deleted_ids is absent', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        data: [{ id: 'p1', name: 'Widget', sku: 'W-001', sale_price: '10.00', stock_quantity: 50 }],
      });

      await pullProducts(db);

      expect(deleteProducts).not.toHaveBeenCalled();
    });
  });

  describe('pullPaymentConfig', () => {
    it('pulls payment methods and repositories', async () => {
      vi.mocked(apiGet)
        .mockResolvedValueOnce([{ id: 'pm-1', code: 'CASH' }])
        .mockResolvedValueOnce([{ id: 'repo-1', code: 'CR-001' }]);

      const result = await pullPaymentConfig(db);

      expect(result).toBe(true);
    });

    it('returns false on error', async () => {
      vi.mocked(apiGet).mockRejectedValue(new Error('Network error'));

      const result = await pullPaymentConfig(db);

      expect(result).toBe(false);
    });

    it('T0.5: pullPaymentConfig hits canonical /payment-methods + /payment-repositories endpoints, NOT /treasury/* paths', async () => {
      // The pre-T0.5 implementation called `/treasury/payment-methods` and
      // `/treasury/payment-repositories` — paths that DO NOT EXIST in the
      // backend (Treasury routes are registered under `Route::prefix('api/v1')`
      // with no `treasury/` prefix at
      // `apps/api/app/Modules/Treasury/Presentation/routes.php:25-66`). The
      // outer try/catch silently swallowed the 404s, so payment-config
      // sync was a no-op for the duration of the bug. T0.5 aligns the call
      // sites on the canonical `/payment-methods` + `/payment-repositories`
      // pair (already used correctly by paymentApi.ts for the SQLite-first
      // hydrate at startup).
      vi.mocked(apiGet)
        .mockResolvedValueOnce([{ id: 'pm-1', code: 'CASH' }])
        .mockResolvedValueOnce([{ id: 'repo-1', code: 'CR-001' }]);

      const result = await pullPaymentConfig(db);

      expect(result).toBe(true);

      // Pin the canonical endpoint pair. A regression that re-introduced the
      // broken `/treasury/...` paths would fail these assertions.
      expect(apiGet).toHaveBeenCalledWith('/payment-methods');
      expect(apiGet).toHaveBeenCalledWith('/payment-repositories');

      // Defense-in-depth: verify the broken paths are NOT in the call list.
      // (`toHaveBeenCalledWith` only asserts at least one matching call, so
      // a buggy implementation that called BOTH endpoint pairs would pass
      // the positive assertions above. This negative assertion catches that.)
      const allCalls = vi.mocked(apiGet).mock.calls.flat();
      expect(allCalls).not.toContain('/treasury/payment-methods');
      expect(allCalls).not.toContain('/treasury/payment-repositories');
    });
  });

  describe('pullOperatorPins', () => {
    it('pulls operator data', async () => {
      vi.mocked(apiGet).mockResolvedValue([
        { id: 'op-1', name: 'Jane', pin_hash: 'hash123' },
      ]);

      const count = await pullOperatorPins(db);

      expect(count).toBe(1);
    });

    it('returns 0 on error', async () => {
      vi.mocked(apiGet).mockRejectedValue(new Error('Unauthorized'));

      const count = await pullOperatorPins(db);

      expect(count).toBe(0);
    });
  });

  describe('pullTerminalState', () => {
    it('returns true and upserts state when terminal has genesis seed and last_hash', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        id: 'term-1',
        code: 'T001',
        location: { code: null },
        genesis_seed: 'abcd1234',
        last_hash: 'hash-xyz',
        hash_sequence: 10,
        fiscal_schema_version: 2,
      });

      const result = await pullTerminalState(db, 'term-1');

      expect(result).toBe(true);
      expect(upsertTerminalState).toHaveBeenCalledWith(db, {
        terminal_id: 'term-1',
        terminal_code: 'T001',
        location_code: 'MAIN',
        genesis_seed: 'abcd1234',
        last_hash: 'hash-xyz',
        hash_sequence: 10,
        manager_pin_throttle_until: null,
        manager_pin_failed_attempts: 0,
        fiscal_schema_version: 2,
      });
      expect(computeGenesisHash).not.toHaveBeenCalled();
    });

    it('computes genesis hash when last_hash is null (new terminal)', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        id: 'term-1',
        code: 'T001',
        location: { code: 'SHOP1' },
        genesis_seed: 'abcd1234',
        last_hash: null,
        hash_sequence: 0,
        fiscal_schema_version: 2,
      });

      const result = await pullTerminalState(db, 'term-1');

      expect(result).toBe(true);
      expect(computeGenesisHash).toHaveBeenCalledWith('abcd1234');
      expect(upsertTerminalState).toHaveBeenCalledWith(db, {
        terminal_id: 'term-1',
        terminal_code: 'T001',
        location_code: 'SHOP1',
        genesis_seed: 'abcd1234',
        last_hash: 'genesis-hash-abc123',
        hash_sequence: 0,
        manager_pin_throttle_until: null,
        manager_pin_failed_attempts: 0,
        fiscal_schema_version: 2,
      });
    });

    it('Codex review B1: projects v3 fiscal_schema_version when the server declares it', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        id: 'term-1',
        code: 'T001',
        location: { code: 'SHOP1' },
        genesis_seed: 'abcd1234',
        last_hash: 'hash-xyz',
        hash_sequence: 12,
        fiscal_schema_version: 3,
      });

      const result = await pullTerminalState(db, 'term-1');

      expect(result).toBe(true);
      expect(upsertTerminalState).toHaveBeenCalledWith(
        db,
        expect.objectContaining({ fiscal_schema_version: 3 }),
      );
    });

    it('returns false when no genesis seed', async () => {
      vi.mocked(apiGet).mockResolvedValue({
        id: 'term-1',
        code: 'T001',
        genesis_seed: '',
        last_hash: '',
        hash_sequence: 0,
      });

      const result = await pullTerminalState(db, 'term-1');

      expect(result).toBe(false);
      expect(upsertTerminalState).not.toHaveBeenCalled();
    });

    it('returns false on error', async () => {
      vi.mocked(apiGet).mockRejectedValue(new Error('Not found'));

      const result = await pullTerminalState(db, 'term-1');

      expect(result).toBe(false);
    });

    it('does NOT reset local hash_sequence when server returns a lower value', async () => {
      // Client has advanced locally to sequence 12. Server thinks it is at 0
      // (new-terminal genesis). The guard must preserve 12.
      vi.mocked(apiGet).mockResolvedValue({
        id: 'terminal-1',
        code: 'T001',
        location: { code: 'MAIN' },
        genesis_seed: 'seed-abc',
        last_hash: null,
        hash_sequence: 0,
      });

      // Simulate the guard inside upsertTerminalState: make the mock throw
      const { FiscalRegressionError } = await import(
        '@/lib/db/repositories/terminalStateRepository'
      );
      vi.mocked(upsertTerminalState).mockRejectedValueOnce(
        new FiscalRegressionError('terminal-1', 'upsertTerminalState', 12, 0),
      );

      const db = makeMockDb();
      const ok = await pullTerminalState(db, 'terminal-1');

      // pullTerminalState must swallow the regression (log + return true)
      // and NEVER propagate — a partial pull must not kill the scheduler.
      expect(ok).toBe(true);
      expect(upsertTerminalState).toHaveBeenCalledOnce();
    });
  });

  describe('runFullSync', () => {
    it('runs push then pull and returns combined results', async () => {
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([]);
      vi.mocked(apiGet)
        .mockResolvedValueOnce([]) // products
        .mockResolvedValueOnce([]) // payment methods
        .mockResolvedValueOnce([]) // payment repos
        .mockResolvedValueOnce([]) // operator pins
        .mockResolvedValueOnce({ id: 't-1', code: 'T001', genesis_seed: 'seed', last_hash: 'h', hash_sequence: 0 }) // terminal state
        .mockResolvedValueOnce({ z_last_hash: null, z_hash_sequence: 0, z_number: 0, grand_totals: null }) // z-chain state
        .mockResolvedValueOnce({ data: [] }) // pullTables (floors)
        .mockResolvedValueOnce({ categories: [] }) // pullActiveMenu
        .mockResolvedValueOnce({ vouchers: [] }) // pullVouchers
        .mockResolvedValueOnce({ entries: [] }) // pullVoucherLedger
        .mockResolvedValueOnce({ entries: [] }); // pullReceiptQrIndex

      const result = await runFullSync(db, 't-1');

      expect(result.receiptsPushed).toBe(0);
      expect(result.receiptsFailed).toBe(0);
      expect(result.productsPulled).toBe(0);
      expect(result.terminalStatePulled).toBe(true);
      expect(result.chainBreak).toBe(false);
      expect(result.errors).toHaveLength(0);
    });
  });
});

describe('pushQueuedPinUpdates', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('no-ops when queue is empty', async () => {
    const { pushQueuedPinUpdates } = await import('../syncService');
    const { getPendingPinUpdates } = await import('@/lib/db/repositories/queuedPinUpdateRepository');
    vi.mocked(getPendingPinUpdates).mockResolvedValueOnce([]);

    const result = await pushQueuedPinUpdates(db);

    expect(result).toBe(0);
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('batches pending updates into /pos/auth/sync-pins and marks synced', async () => {
    const { pushQueuedPinUpdates } = await import('../syncService');
    const { getPendingPinUpdates, markPinUpdateSynced } = await import('@/lib/db/repositories/queuedPinUpdateRepository');
    vi.mocked(getPendingPinUpdates).mockResolvedValueOnce([
      { id: 1, userId: 'u1', pinHash: 'h1', status: 'pending', retryCount: 0, createdAt: 'now', syncedAt: null, syncError: null },
      { id: 2, userId: 'u2', pinHash: 'h2', status: 'pending', retryCount: 0, createdAt: 'now', syncedAt: null, syncError: null },
    ]);
    vi.mocked(apiPost).mockResolvedValueOnce({ synced: 2, skipped: 0 });

    const count = await pushQueuedPinUpdates(db);

    expect(count).toBe(2);
    expect(apiPost).toHaveBeenCalledWith('/pos/auth/sync-pins', {
      updates: [
        { user_id: 'u1', pin_hash: 'h1' },
        { user_id: 'u2', pin_hash: 'h2' },
      ],
    });
    expect(markPinUpdateSynced).toHaveBeenCalledWith(db, 1);
    expect(markPinUpdateSynced).toHaveBeenCalledWith(db, 2);
  });
});

describe('pullReceiptQrIndex', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('normalises missing partner_id to null and upserts without crashing', async () => {
    const { pullReceiptQrIndex } = await import('../syncService');
    const { upsertReceiptQrIndexEntries } = await import('@/lib/offline/voucherRepository');

    // Server response omits partner_id entirely (simulates pre-migration backend)
    vi.mocked(apiGet).mockResolvedValueOnce({
      entries: [{
        receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
        qr_token: '1:kid:token:mac',
        receipt_number: 'R-001',
        terminal_id: 'term-1',
        posted_at: '2026-04-28T09:00:00+00:00',
        total: '12500',
        currency: 'EUR',
        synced_at: '2026-04-28T09:00:05+00:00',
        // partner_id deliberately absent
      }],
    });

    const count = await pullReceiptQrIndex(db, 'term-1');

    expect(count).toBe(1);
    expect(upsertReceiptQrIndexEntries).toHaveBeenCalledWith(
      expect.anything(),
      expect.arrayContaining([
        expect.objectContaining({ partner_id: null }),
      ]),
    );
  });
});

describe('pullTables', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('pulls floors + tables from the server and upserts them', async () => {
    const { pullTables } = await import('../syncService');
    const { upsertFloors, upsertTables } = await import('@/lib/db/repositories/tableRepository');

    vi.mocked(apiGet).mockResolvedValueOnce({
      data: [{
        id: 'f1', name: 'Main', position: 0, is_active: true,
        tables: [{
          id: 't1', floor_id: 'f1', table_number: '1', label: null, seats: 4,
          status: 'available', shape: null, position_x: null, position_y: null,
          width: null, height: null, current_order_id: null,
          created_at: 'x', updated_at: 'x',
        }],
        created_at: 'x', updated_at: 'x',
      }],
    });

    const ok = await pullTables(db);

    expect(ok).toBe(true);
    expect(upsertFloors).toHaveBeenCalledWith(db, expect.arrayContaining([expect.objectContaining({ id: 'f1' })]));
    expect(upsertTables).toHaveBeenCalledWith(db, expect.arrayContaining([expect.objectContaining({ id: 't1' })]));
  });

  it('returns false and logs error when the API is unreachable', async () => {
    const { pullTables } = await import('../syncService');
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('offline'));

    const ok = await pullTables(db);

    expect(ok).toBe(false);
  });
});

describe('pullActiveMenu', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('upserts categories and items from /active-menu', async () => {
    const { pullActiveMenu } = await import('../syncService');
    const { upsertMenuCategories, upsertMenuCategoryItems } = await import('@/lib/db/repositories/menuRepository');

    vi.mocked(apiGet).mockResolvedValueOnce({
      categories: [{
        id: 'c1', name: 'Drinks', position: 0,
        items: [{
          id: 'i1', sellable_id: 'p1', sellable_type: 'product',
          name: 'Latte', code: 'LAT', barcode: null,
          base_price: '3.50', effective_price: '3.50', image_url: null,
          tax_rate: '7.00', display_order: 0, is_available: true,
        }],
      }],
      deleted_category_ids: ['c-gone'],
      deleted_item_ids: ['i-gone'],
    });

    const ok = await pullActiveMenu(db);

    expect(ok).toBe(true);
    expect(upsertMenuCategories).toHaveBeenCalled();
    expect(upsertMenuCategoryItems).toHaveBeenCalled();
  });

  it('returns false on API error', async () => {
    const { pullActiveMenu } = await import('../syncService');
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('offline'));
    expect(await pullActiveMenu(db)).toBe(false);
  });

  it('Codex r6 P1 (Menu tenant): flattens the just-pulled menu and calls reconcileMenuProducts so background sync ticks update the cashier grid', async () => {
    const { pullActiveMenu } = await import('../syncService');
    const { reconcileMenuProducts } = await import('@/lib/db/repositories/productRepository');
    const { useProductStore } = await import('@/stores/productStore');

    // Codex r8 P1: the reconcile is now gated on the Menu module.
    // Set companyConfig to a Menu tenant so the gate fires.
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['Menu'],
      } as never,
    });

    vi.mocked(apiGet).mockResolvedValueOnce({
      categories: [{
        id: 'cat-drinks-uuid', name: 'Drinks', position: 0,
        items: [{
          id: 'item-coca-drinks', sellable_id: 'sellable-coca', sellable_type: 'product',
          name: 'Coca', code: 'COCA', barcode: null,
          base_price: '3.00', effective_price: '3.00', image_url: null,
          tax_rate: '7.00', display_order: 0, is_available: true,
        }],
      }],
    });

    await pullActiveMenu(db);

    expect(reconcileMenuProducts).toHaveBeenCalledTimes(1);
    const [, freshProducts] = vi.mocked(reconcileMenuProducts).mock.calls[0]!;
    expect(freshProducts).toHaveLength(1);
    // Flatten emits composite ids per (sellable, category) pair.
    expect((freshProducts as Array<{ id: string; sellable_id?: string; menu_category_id?: string }>)[0]!.id).toBe('sellable-coca_cat-drinks-uuid');
    expect((freshProducts as Array<{ id: string; sellable_id?: string; menu_category_id?: string }>)[0]!.sellable_id).toBe('sellable-coca');
    expect((freshProducts as Array<{ id: string; sellable_id?: string; menu_category_id?: string }>)[0]!.menu_category_id).toBe('cat-drinks-uuid');
  });

  it('Codex r8 P1: standard-retail tenant — empty /active-menu does NOT wipe the products table', async () => {
    // Regression guard: a standard-retail tenant's /active-menu can
    // succeed with categories: []. The reconcile MUST be skipped or
    // it would call `wipeAllProductRows` and delete the entire
    // products catalog populated by `pullProducts`.
    const { pullActiveMenu } = await import('../syncService');
    const { reconcileMenuProducts } = await import('@/lib/db/repositories/productRepository');
    const { useProductStore } = await import('@/stores/productStore');

    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS'],
      } as never,
    });

    vi.mocked(apiGet).mockResolvedValueOnce({ categories: [] });

    await pullActiveMenu(db);

    expect(reconcileMenuProducts).not.toHaveBeenCalled();
  });

  it('Codex r8 P1: companyConfig null — defers the reconcile (does NOT call reconcileMenuProducts)', async () => {
    // Defer-on-unknown-config matches the pullProducts gate's posture:
    // skipping is safer than risking a standard-retail catalog wipe.
    const { pullActiveMenu } = await import('../syncService');
    const { reconcileMenuProducts } = await import('@/lib/db/repositories/productRepository');
    const { useProductStore } = await import('@/stores/productStore');

    useProductStore.setState({ companyConfig: null });

    vi.mocked(apiGet).mockResolvedValueOnce({ categories: [] });

    await pullActiveMenu(db);

    expect(reconcileMenuProducts).not.toHaveBeenCalled();
  });
});

// ─── zReportToSyncPayload ─────────────────────────────────────────────────────

describe('zReportToSyncPayload', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('includes cash_counts array in sync payload when LocalZReport has cash_counts', async () => {
    const { zReportToSyncPayload } = await import('../syncService');

    const cashCounts = [
      {
        payment_method_id: 'pm-cash',
        currency_code: 'EUR',
        expected_amount: '150.00',
        actual_amount: '148.00',
        variance_amount: '-2.00',
        variance_direction: 'under' as const,
        transaction_count: 1,
      },
    ];

    const report: import('@/lib/offline/types').LocalZReport = {
      id: 'zr-1',
      terminal_id: 'term-1',
      shift_id: 'shift-1',
      z_number: 1,
      formatted_z_number: 'Z0001',
      generated_at: '2026-04-23T10:00:00+00:00',
      fiscal_hash: 'fiscal-hash-1',
      previous_hash: 'prev-hash',
      hash_sequence: 3,
      report_data: {
        sales_count: 1,
        gross_sales: '50.00',
        net_sales: '42.00',
        tax_amount: '8.00',
        refunds_count: 0,
        refunds_amount: '0.00',
        voided_count: 0,
        vat_breakdown: [],
        payment_methods: [],
        schema_version: 2,
        cash_counts: cashCounts,
        tolerance_summary: { totalAmount: '0.000', currencyCode: 'EUR', writeoffCount: 0 },
      } as import('@/lib/offline/types').ZReportData,
      opening_cash: '100.00',
      expected_cash: '150.00',
      receipt_snapshots: [],
      grand_totals: {
        cumulative_sales: '550.00',
        cumulative_tax: '88.00',
        cumulative_refunds: '0.00',
        perpetual_grand_total: '550.00',
        receipt_count_lifetime: 11,
      },
      synced: false,
      synced_at: null,
      cash_counts: cashCounts,
      shift_fields: {
        blind_count_used: true,
        variance_severity: 'warning',
        variance_reason: 'Operator error',
        manager_override_by: 'mgr-uuid-1',
      },
      manager_user_id: 'mgr-uuid-1',
      tolerance_summary: { totalAmount: '0.000', currencyCode: 'EUR', writeoffCount: 0 },
      currency_code: 'EUR',
    };

    const payload = zReportToSyncPayload(report);

    expect(Array.isArray(payload['cash_counts'])).toBe(true);
    expect((payload['cash_counts'] as unknown[]).length).toBe(1);
    expect(payload['cash_counts']).toEqual([
      {
        payment_method_id: 'pm-cash',
        currency_code: 'EUR',
        expected_amount: '150.00',
        actual_amount: '148.00',
        variance_amount: '-2.00',
        variance_direction: 'under',
        transaction_count: 1,
      },
    ]);
    expect(payload['shift_fields']).toEqual({
      blind_count_used: true,
      variance_severity: 'warning',
      variance_reason: 'Operator error',
      manager_override_by: 'mgr-uuid-1',
    });
    expect(payload['manager_user_id']).toBe('mgr-uuid-1');
    expect(payload['tolerance_summary']).toEqual({
      totalAmount: '0.000',
      currencyCode: 'EUR',
      writeoffCount: 0,
    });
  });

  it('fills zero-shape tolerance_summary when LocalZReport has none', async () => {
    const { zReportToSyncPayload } = await import('../syncService');

    const report: import('@/lib/offline/types').LocalZReport = {
      id: 'zr-2',
      terminal_id: 'term-1',
      shift_id: 'shift-2',
      z_number: 2,
      formatted_z_number: 'Z0002',
      generated_at: '2026-04-23T11:00:00+00:00',
      fiscal_hash: 'fiscal-hash-2',
      previous_hash: 'prev-hash-2',
      hash_sequence: 4,
      report_data: {
        sales_count: 0,
        gross_sales: '0.00',
        net_sales: '0.00',
        tax_amount: '0.00',
        refunds_count: 0,
        refunds_amount: '0.00',
        voided_count: 0,
        vat_breakdown: [],
        payment_methods: [],
      },
      opening_cash: '0.00',
      expected_cash: '0.00',
      receipt_snapshots: [],
      grand_totals: {
        cumulative_sales: '0.00',
        cumulative_tax: '0.00',
        cumulative_refunds: '0.00',
        perpetual_grand_total: '0.00',
        receipt_count_lifetime: 0,
      },
      synced: false,
      synced_at: null,
      // No cash_counts, shift_fields, manager_user_id, tolerance_summary, currency_code
    };

    const payload = zReportToSyncPayload(report);

    expect(payload['cash_counts']).toEqual([]);
    expect(payload['shift_fields']).toBeNull();
    expect(payload['manager_user_id']).toBeNull();
    // When no currency_code, tolerance_summary should fall back to EUR with 0.000
    const ts = payload['tolerance_summary'] as Record<string, unknown>;
    expect(ts['totalAmount']).toBe('0.000');
    expect(typeof ts['currencyCode']).toBe('string');
    expect(ts['writeoffCount']).toBe(0);
  });
});

describe('B3-followup audit (Finding 3): receiptToPayload wire-shape parity', () => {
  // Pure unit-level test: feed a fully-built voucher offline receipt to the
  // production sync parser and assert the wire payment block is byte-for-byte
  // what the canonical builder would expect. This is complementary to the
  // hash assertion in receiptService.test.ts — that test proves the canonical
  // input matches the offline hash; this test proves the wire payload matches
  // the canonical input. Together they bracket every TS surface the server
  // depends on for v3 hash recomputation.

  it('preserves method_code, instrument_type, and instrument_serial verbatim for a voucher tender row', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      id: 'wire-r1',
      idempotency_key: 'wire-idem-1',
      hash_sequence: 1,
      fiscal_schema_version: 3,
      payments_json: JSON.stringify([
        {
          payment_method_id: 'pm-store-voucher',
          repository_id: 'repo-virtual',
          amount: '20.00',
          card_last_four: null,
          transaction_reference: null,
          method_code: 'store_voucher',
          instrument_type: 'store_voucher',
          instrument_serial: 'SV-2026-WIRE-01',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);

    // The wire shape MUST be exactly this — not a superset, not coerced.
    // A future drift (e.g. accidentally lowercasing method_code, dropping a
    // field, or sorting payments) would fail loudly here.
    expect(wire.payments).toEqual([
      {
        payment_method_id: 'pm-store-voucher',
        repository_id: 'repo-virtual',
        amount: '20.00',
        card_last_four: null,
        transaction_reference: null,
        method_code: 'store_voucher',
        instrument_type: 'store_voucher',
        instrument_serial: 'SV-2026-WIRE-01',
      },
    ]);
  });

  it('preserves cash + voucher split-payment shape (order, fields, nulls) verbatim', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      id: 'wire-r2',
      idempotency_key: 'wire-idem-2',
      hash_sequence: 2,
      fiscal_schema_version: 3,
      payments_json: JSON.stringify([
        {
          payment_method_id: 'pm-cash',
          repository_id: 'repo-cash',
          amount: '15.00',
          card_last_four: null,
          transaction_reference: null,
          method_code: 'cash',
          instrument_type: null,
          instrument_serial: null,
        },
        {
          payment_method_id: 'pm-store-voucher',
          repository_id: 'repo-virtual',
          amount: '10.00',
          card_last_four: null,
          transaction_reference: null,
          method_code: 'store_voucher',
          instrument_type: 'store_voucher',
          instrument_serial: 'SV-2026-WIRE-02',
        },
      ]),
    });

    const wire = __test_receiptToPayload(receipt);

    expect(wire.payments).toHaveLength(2);
    expect(wire.payments[0]!.method_code).toBe('cash');
    expect(wire.payments[0]!.instrument_type).toBeNull();
    expect(wire.payments[0]!.instrument_serial).toBeNull();
    expect(wire.payments[1]!.method_code).toBe('store_voucher');
    expect(wire.payments[1]!.instrument_type).toBe('store_voucher');
    expect(wire.payments[1]!.instrument_serial).toBe('SV-2026-WIRE-02');
  });
});

describe('T2.7: is_training wire payload', () => {
  // The offline-first compliance gap from PR #99 round-8 closes only when the
  // SQLite is_training column is forwarded on the wire. Default false stays
  // backwards-compatible with pre-T2.7 server payloads (the field is
  // `nullable, boolean` per SyncReceiptsRequest::rules in PR #103).

  it('emits is_training=true when SQLite row has is_training=1', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      receipt_number: 'TRN-MAIN-T001-2026-deadbeef',
      idempotency_key: 'idem-train-1',
      fiscal_schema_version: 2,
      is_training: 1,
    });

    const wire = __test_receiptToPayload(receipt);
    expect(wire.is_training).toBe(true);
  });

  it('emits is_training=false when SQLite row has is_training=0 (default backfill)', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      receipt_number: 'MAIN-T001-2026-00000001',
      idempotency_key: 'idem-prod-1',
      fiscal_schema_version: 2,
      is_training: 0,
    });

    const wire = __test_receiptToPayload(receipt);
    expect(wire.is_training).toBe(false);
  });
});

describe('B3-followup audit (Finding 4): receiptToPayload fails loudly on invalid fiscal_schema_version', () => {
  // Pre-existing comment in syncService.ts:1222-1225 said "any unexpected
  // value is a schema bug and should fail loudly via the server's hard-reject
  // path", but the code coerced anything that wasn't 3 to 2 silently. The
  // audit flagged the comment-vs-code drift. This test locks the fix:
  // unexpected values throw at the wire-parser layer, with a message that
  // names the receipt and the bad value so on-call can find the row.

  it('throws on fiscal_schema_version = 4 with a message naming the receipt and the bad value', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      receipt_number: 'POS01-2026-00099999',
      idempotency_key: 'idem-bad-version',
      fiscal_schema_version: 4 as unknown as 2 | 3,
    });

    expect(() => __test_receiptToPayload(receipt)).toThrowError(/POS01-2026-00099999/);
    expect(() => __test_receiptToPayload(receipt)).toThrowError(/fiscal_schema_version/);
    expect(() => __test_receiptToPayload(receipt)).toThrowError(/4/);
  });

  it('throws when fiscal_schema_version is undefined / null', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      receipt_number: 'POS01-2026-00099998',
      idempotency_key: 'idem-null-version',
      fiscal_schema_version: undefined as unknown as 2 | 3,
    });

    expect(() => __test_receiptToPayload(receipt)).toThrowError(/fiscal_schema_version/);
  });

  it('accepts fiscal_schema_version = 2', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      idempotency_key: 'idem-v2',
      fiscal_schema_version: 2,
    });

    const wire = __test_receiptToPayload(receipt);
    expect(wire.fiscal_schema_version).toBe(2);
  });

  it('accepts fiscal_schema_version = 3', async () => {
    const { __test_receiptToPayload } = await import('@/lib/sync/syncService');
    const { makeOfflineReceipt } = await import('@/test/helpers');

    const receipt = makeOfflineReceipt({
      idempotency_key: 'idem-v3',
      fiscal_schema_version: 3,
    });

    const wire = __test_receiptToPayload(receipt);
    expect(wire.fiscal_schema_version).toBe(3);
  });
});
