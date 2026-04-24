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
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  upsertPaymentMethods: vi.fn().mockResolvedValue(undefined),
  upsertPaymentRepositories: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  upsertOperators: vi.fn().mockResolvedValue(undefined),
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
import { upsertProducts } from '@/lib/db/repositories/productRepository';
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
      });
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
        .mockResolvedValueOnce({ z_last_hash: null, z_hash_sequence: 0, z_number: 0, grand_totals: null }); // z-chain state

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
