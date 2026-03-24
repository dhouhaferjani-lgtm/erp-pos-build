import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptsForSync: vi.fn(),
  updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
  incrementRetryCount: vi.fn().mockResolvedValue(undefined),
  cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
  cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
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
} from '@/lib/db/repositories/offlineReceiptRepository';
import { upsertProducts } from '@/lib/db/repositories/productRepository';
import { upsertTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { computeGenesisHash } from '@/lib/fiscal/hashService';
import { makeOfflineReceipt } from '@/test/helpers';

function makeMockDb() {
  return {} as import('@tauri-apps/plugin-sql').default;
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
        makeOfflineReceipt({ id: 'r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', hash_sequence: 2 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);
      vi.mocked(apiPost).mockResolvedValue({ success: true });

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(2);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
    });

    it('marks receipt as syncing before pushing', async () => {
      const receipt = makeOfflineReceipt({ id: 'r1' });
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue([receipt]);
      vi.mocked(apiPost).mockResolvedValue({ success: true });

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
        makeOfflineReceipt({ id: 'r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', hash_sequence: 2 }),
        makeOfflineReceipt({ id: 'r3', hash_sequence: 3 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);

      vi.mocked(apiPost)
        .mockResolvedValueOnce({ success: true })
        .mockRejectedValueOnce(new Error('Hash chain mismatch'));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(true);
      expect(apiPost).toHaveBeenCalledTimes(2);
      expect(result.errors).toContain('CHAIN_BREAK: Sync halted. Operator must resolve hash chain conflict.');
    });

    it('continues pushing after non-chain-break error', async () => {
      const receipts = [
        makeOfflineReceipt({ id: 'r1', hash_sequence: 1 }),
        makeOfflineReceipt({ id: 'r2', hash_sequence: 2 }),
      ];
      vi.mocked(getPendingReceiptsForSync).mockResolvedValue(receipts);

      vi.mocked(apiPost)
        .mockRejectedValueOnce(new Error('Timeout'))
        .mockResolvedValueOnce({ success: true });

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
