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
      expect(payload['payments']).toEqual([
        { payment_method_id: 'pm-1', repository_id: 'repo-1', amount: '30.00', card_last_four: null, transaction_reference: null },
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
