import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/offlineReceiptRepository')>('@/lib/db/repositories/offlineReceiptRepository');
  return {
    ...actual,
    updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
    cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
    cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/fiscalEventRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/fiscalEventRepository')>('@/lib/db/repositories/fiscalEventRepository');
  return {
    ...actual,
    getPendingFiscalEventsForSync: vi.fn(),
    recoverStrandedSyncingFiscalEvents: vi.fn().mockResolvedValue(0),
    updateFiscalEventSyncStatus: vi.fn().mockResolvedValue(undefined),
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
import { updateReceiptStatus } from '@/lib/db/repositories/offlineReceiptRepository';
import {
  getPendingFiscalEventsForSync,
  recoverStrandedSyncingFiscalEvents,
  updateFiscalEventSyncStatus,
  type LocalFiscalEvent,
} from '@/lib/db/repositories/fiscalEventRepository';
import { upsertProducts, deleteProducts } from '@/lib/db/repositories/productRepository';
import { upsertTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { computeGenesisHash } from '@/lib/fiscal/hashService';

function makeMockDb() {
  return {} as import('@tauri-apps/plugin-sql').default;
}

function makeFiscalEvent(overrides: Partial<LocalFiscalEvent> = {}): LocalFiscalEvent {
  return {
    id: 'fe-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    terminal_id: 'terminal-1',
    operator_id: 'operator-1',
    event_type: 'SALE_RECEIPT',
    event_version: 1,
    signature_version: 'v1',
    sequence_number: 1,
    event_time_device: '2026-05-20T12:00:00Z',
    business_date: '2026-05-20',
    last_server_time_seen: null,
    reference_event_id: null,
    reference_document_id: null,
    source_event_class: 'offline_receipts',
    source_event_id: 'receipt-1',
    canonical_bytes: '{"event_type":"SALE_RECEIPT"}',
    previous_hash: 'previous-hash',
    current_hash: 'current-hash',
    sync_status: 'pending',
    sync_error: null,
    created_at: '2026-05-20T12:00:00Z',
    synced_at: null,
    ...overrides,
  };
}

function fiscalEventBatchResponse(items: Array<{
  fiscal_event_id: string | null;
  stored?: boolean;
  sequence_conflict?: boolean;
  exception_class?: string | null;
}>) {
  return {
    results: items.map((i) => ({
      stored: i.stored ?? true,
      fiscal_event_id: i.fiscal_event_id,
      sequence_conflict: i.sequence_conflict ?? false,
      exception_class: i.exception_class ?? null,
    })),
  };
}

describe('syncService', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
  });

  describe('pushOfflineReceipts', () => {
    it('pushes pending fiscal events through the fiscal-event sync endpoint', async () => {
      const events = [
        makeFiscalEvent({ id: 'fe-1', source_event_id: 'receipt-1', sequence_number: 1 }),
        makeFiscalEvent({
          id: 'fe-2',
          source_event_id: 'receipt-2',
          current_hash: 'current-hash-2',
          previous_hash: 'current-hash',
          sequence_number: 2,
        }),
      ];
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue(events);
      vi.mocked(apiPost)
        .mockResolvedValueOnce(fiscalEventBatchResponse([{ fiscal_event_id: 'fe-1' }]))
        .mockResolvedValueOnce(fiscalEventBatchResponse([{ fiscal_event_id: 'fe-2' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(2);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);

      expect(apiPost).toHaveBeenCalledTimes(2);
      expect(apiPost).toHaveBeenNthCalledWith(
        1,
        '/pos/sync/fiscal-events',
        {
          envelopes: [
            expect.objectContaining({
              envelope_id: 'fe-1',
              idempotency_key: 'terminal-1:1',
              payload: expect.objectContaining({
                canonical_bytes: '{"event_type":"SALE_RECEIPT"}',
                current_hash: 'current-hash',
                source_event_class: 'offline_receipts',
                source_event_id: 'receipt-1',
              }),
            }),
          ],
        },
        { timeoutMs: 30_000 },
      );
      expect(apiPost).not.toHaveBeenCalledWith(
        '/pos/receipts/sync',
        expect.anything(),
        expect.anything(),
      );
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-1', 'syncing');
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-1', 'synced');
      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'receipt-1', 'synced');
      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'receipt-2', 'synced');
    });

    it('returns empty results when no pending receipts', async () => {
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([]);

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
    });

    it('FetchTimeoutError reverts the fiscal event to pending without marking the source receipt failed', async () => {
      const { FetchTimeoutError } = await import('@/lib/fetchWithTimeout');

      const event = makeFiscalEvent({ id: 'fe-timeout', source_event_id: 'receipt-timeout' });
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([event]);
      vi.mocked(apiPost).mockRejectedValueOnce(
        new FetchTimeoutError('https://x.test/pos/sync/fiscal-events', 30_000, 'POST'),
      );

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-timeout', 'syncing');
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-timeout', 'pending');
      expect(updateReceiptStatus).not.toHaveBeenCalledWith(
        db,
        'receipt-timeout',
        'failed',
        expect.anything(),
      );
    });

    it('treats server idempotent re-delivery as synced after a lost response retry', async () => {
      const event = makeFiscalEvent({ id: 'fe-idempotent', source_event_id: 'receipt-idempotent' });
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([event]);
      vi.mocked(apiPost).mockResolvedValueOnce(
        fiscalEventBatchResponse([
          { fiscal_event_id: 'fe-idempotent', stored: false },
        ]),
      );

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(0);
      expect(result.errors).toHaveLength(0);
      expect(result.chainBreak).toBe(false);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-idempotent', 'synced');
      expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'receipt-idempotent', 'synced');
    });

    it('continues after a non-chain fiscal-event rejection', async () => {
      const events = [
        makeFiscalEvent({ id: 'fe-invalid', sequence_number: 1, source_event_id: 'receipt-invalid' }),
        makeFiscalEvent({ id: 'fe-ok', sequence_number: 2, source_event_id: 'receipt-ok' }),
      ];
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue(events);
      vi.mocked(apiPost)
        .mockResolvedValueOnce(fiscalEventBatchResponse([
          { fiscal_event_id: 'fe-invalid', stored: false, exception_class: 'ValidationException' },
        ]))
        .mockResolvedValueOnce(fiscalEventBatchResponse([{ fiscal_event_id: 'fe-ok' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(false);
      expect(result.errors).toEqual(['Fiscal event fe-invalid: ValidationException']);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-invalid', 'failed', 'ValidationException');
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-ok', 'synced');
    });

    it('halts on sequence conflict without posting later events', async () => {
      const events = [
        makeFiscalEvent({ id: 'fe-conflict', sequence_number: 7 }),
        makeFiscalEvent({ id: 'fe-later', sequence_number: 8 }),
      ];
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue(events);
      vi.mocked(apiPost).mockResolvedValueOnce(
        fiscalEventBatchResponse([
          { fiscal_event_id: 'fe-conflict', stored: false, sequence_conflict: true },
        ]),
      );

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(true);
      expect(result.errors).toEqual(['CHAIN_BREAK at fiscal event fe-conflict']);
      expect(apiPost).toHaveBeenCalledTimes(1);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-conflict', 'failed', 'sequence_conflict');
      expect(updateFiscalEventSyncStatus).not.toHaveBeenCalledWith(db, 'fe-later', 'syncing');
    });

    it('halts on hash-chain exception class without posting later events', async () => {
      const events = [
        makeFiscalEvent({ id: 'fe-hash-break', sequence_number: 9 }),
        makeFiscalEvent({ id: 'fe-later', sequence_number: 10 }),
      ];
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue(events);
      vi.mocked(apiPost).mockResolvedValueOnce(
        fiscalEventBatchResponse([
          { fiscal_event_id: 'fe-hash-break', stored: false, exception_class: 'hash mismatch' },
        ]),
      );

      const result = await pushOfflineReceipts(db);

      expect(result.chainBreak).toBe(true);
      expect(result.failed).toBe(1);
      expect(result.pushed).toBe(0);
      expect(result.errors).toEqual(['CHAIN_BREAK at fiscal event fe-hash-break']);
      expect(apiPost).toHaveBeenCalledTimes(1);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-hash-break', 'failed', 'hash mismatch');
      expect(updateFiscalEventSyncStatus).not.toHaveBeenCalledWith(db, 'fe-later', 'syncing');
    });

    it('marks a fiscal event failed when the server omits its result item', async () => {
      const event = makeFiscalEvent({ id: 'fe-missing-result', source_event_id: 'receipt-missing' });
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([event]);
      vi.mocked(apiPost).mockResolvedValueOnce(fiscalEventBatchResponse([]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(0);
      expect(result.failed).toBe(1);
      expect(result.chainBreak).toBe(false);
      expect(result.errors[0]).toContain('Sync response missing result for fiscal event fe-missing-result');
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(
        db,
        'fe-missing-result',
        'failed',
        'Sync response missing result for fiscal event fe-missing-result',
      );
      expect(updateReceiptStatus).not.toHaveBeenCalledWith(db, 'receipt-missing', 'synced');
    });

    it('does not touch offline_receipts for fiscal events without an offline receipt source', async () => {
      const event = makeFiscalEvent({
        id: 'fe-non-receipt',
        source_event_class: 'cash_drawer_ops',
        source_event_id: 'cash-op-1',
      });
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([event]);
      vi.mocked(apiPost).mockResolvedValueOnce(fiscalEventBatchResponse([{ fiscal_event_id: 'fe-non-receipt' }]));

      const result = await pushOfflineReceipts(db);

      expect(result.pushed).toBe(1);
      expect(result.failed).toBe(0);
      expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fe-non-receipt', 'synced');
      expect(updateReceiptStatus).not.toHaveBeenCalled();
    });
  });

  describe('runFullSync fiscal-event recovery', () => {
    it('recovers stranded syncing fiscal events before selecting pending fiscal events', async () => {
      vi.mocked(recoverStrandedSyncingFiscalEvents).mockResolvedValueOnce(2);
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([]);

      await runFullSync(db, 'terminal-1');

      expect(recoverStrandedSyncingFiscalEvents).toHaveBeenCalledWith(db);
      expect(recoverStrandedSyncingFiscalEvents).toHaveBeenCalledBefore(
        vi.mocked(getPendingFiscalEventsForSync),
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
      vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([]);
      const { useProductStore } = await import('@/stores/productStore');
      useProductStore.setState({
        companyConfig: {
          company_id: 'company-1',
          all_enabled_modules: ['POS'],
        } as never,
      });
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
  // backwards-compatible with pre-T2.7 server payloads.

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
