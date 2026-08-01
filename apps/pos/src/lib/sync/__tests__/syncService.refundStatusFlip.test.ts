import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * v3-refund-chain-integration spec §7.2a errata T2 — proves a refund's
 * `offline_receipts` row reaches `'synced'` (not stuck `'pending'`
 * forever) after its fiscal event syncs. Before this fix,
 * `pushOfflineReceipts()`'s sync-completion-flip block only matched
 * `event.source_event_class === 'offline_receipts'` — a refund's fiscal
 * event carries `source_event_class: 'refund_intents'` (§4.3), so that
 * condition NEVER matched for a refund, leaving its `offline_receipts`
 * row's `status` at `'pending'` forever even after a fully successful
 * sync.
 *
 * A minimal, self-contained mock harness (NOT the full syncService.test.ts
 * suite) scoped to exactly what `pushOfflineReceipts()` touches.
 */

vi.mock('@/lib/api', () => {
  class ApiRequestError extends Error {
    constructor(
      public status: number,
      message: string,
      public code: string,
      public details?: unknown,
    ) {
      super(message);
      this.name = 'ApiRequestError';
    }
  }
  return {
    apiPostRaw: vi.fn(),
    ApiRequestError,
  };
});

vi.mock('@/lib/db/repositories/offlineReceiptRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/offlineReceiptRepository')>(
    '@/lib/db/repositories/offlineReceiptRepository',
  );
  return {
    ...actual,
    updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
    updateReceiptStatusByIdempotencyKey: vi.fn().mockResolvedValue(undefined),
    cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
    cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/fiscalEventRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/fiscalEventRepository')>(
    '@/lib/db/repositories/fiscalEventRepository',
  );
  return {
    ...actual,
    getPendingFiscalEventsForSync: vi.fn(),
    recoverStrandedSyncingFiscalEvents: vi.fn().mockResolvedValue(0),
    updateFiscalEventSyncStatus: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

import { pushOfflineReceipts } from '../syncService';
import { apiPostRaw } from '@/lib/api';
import {
  updateReceiptStatus,
  updateReceiptStatusByIdempotencyKey,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  getPendingFiscalEventsForSync,
  type LocalFiscalEvent,
} from '@/lib/db/repositories/fiscalEventRepository';

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
    event_version: 4,
    signature_version: 'v1',
    sequence_number: 1,
    event_time_device: '2026-05-20T12:00:00Z',
    business_date: '2026-05-20',
    chain_context: 'operational',
    last_server_time_seen: null,
    reference_event_id: null,
    reference_document_id: null,
    source_event_class: 'refund_intents',
    source_event_id: 'refund-intent-1',
    canonical_bytes: '{"event_type":"SALE_RECEIPT","invoice_type_code":"REFUND"}',
    previous_hash: 'previous-hash',
    current_hash: 'current-hash',
    sync_status: 'pending',
    sync_error: null,
    created_at: '2026-05-20T12:00:00Z',
    synced_at: null,
    ...overrides,
  };
}

function successResponse(fiscalEventId: string) {
  return {
    results: [
      { stored: true, fiscal_event_id: fiscalEventId, sequence_conflict: false, exception_class: null },
    ],
  };
}

describe('pushOfflineReceipts — refund offline_receipts sync-completion flip (spec §7.2a errata T2)', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
  });

  it('flips the refund offline_receipts row to synced via idempotency-key lookup, NOT the own-id lookup', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-1', source_event_id: 'refund-intent-1' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-1'));

    const result = await pushOfflineReceipts(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(updateReceiptStatusByIdempotencyKey).toHaveBeenCalledWith(db, 'refund-intent-1', 'synced');
    // The own-id lookup (the SALE branch) must NEVER fire for a refund --
    // 'refund-intent-1' is refund_intents.id, not an offline_receipts
    // primary key; calling updateReceiptStatus with it would silently
    // touch zero rows (or, worse, collide with an unrelated receipt id).
    expect(updateReceiptStatus).not.toHaveBeenCalled();
  });

  it('a SALE fiscal event (source_event_class=offline_receipts) still uses the own-id lookup, unchanged', async () => {
    const event = makeFiscalEvent({
      id: 'fe-sale-1',
      source_event_class: 'offline_receipts',
      source_event_id: 'receipt-1',
      event_version: 3,
    });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-sale-1'));

    await pushOfflineReceipts(db);

    expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'receipt-1', 'synced');
    expect(updateReceiptStatusByIdempotencyKey).not.toHaveBeenCalled();
  });

  it('neither status-flip branch fires for an unrelated source_event_class', async () => {
    const event = makeFiscalEvent({
      id: 'fe-other-1',
      source_event_class: 'cash_drawer_ops',
      source_event_id: 'op-1',
    });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-other-1'));

    await pushOfflineReceipts(db);

    expect(updateReceiptStatus).not.toHaveBeenCalled();
    expect(updateReceiptStatusByIdempotencyKey).not.toHaveBeenCalled();
  });

  it('does not flip status when the sync itself failed (chain break / rejection)', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-2', source_event_id: 'refund-intent-2' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue({
      results: [{ stored: false, fiscal_event_id: null, sequence_conflict: true, exception_class: null }],
    });

    await pushOfflineReceipts(db);

    expect(updateReceiptStatusByIdempotencyKey).not.toHaveBeenCalled();
  });
});
