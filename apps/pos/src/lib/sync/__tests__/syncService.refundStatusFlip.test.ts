import { describe, it, expect, vi, beforeEach } from 'vitest';

/** The transaction handle the mocked write gate hands the callback. */
const h = vi.hoisted(() => ({ db: {} as unknown }));

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
    // Wave-2 fix-wave finding 12 — now returns the affected-row count so
    // the ACK flip can REQUIRE exactly one matching receipt.
    updateReceiptStatusByIdempotencyKey: vi.fn().mockResolvedValue(1),
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

vi.mock('@/lib/db/repositories/refundIntentRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/refundIntentRepository')>(
    '@/lib/db/repositories/refundIntentRepository',
  );
  return {
    ...actual,
    markSynced: vi.fn().mockResolvedValue(undefined),
    // Finding 12: the benign already-synced case is suppressed ONLY after
    // a RE-READ confirms the row really is at 'synced'.
    getRefundIntentById: vi.fn().mockResolvedValue({ id: 'refund-intent-1', state: 'synced' }),
  };
});

// The three post-ACK flips run inside ONE write-gate transaction
// (finding 12); the gate itself is exercised by its own suite.
vi.mock('@/lib/db/writeGate', () => ({
  withWriteTransaction: vi.fn(async (_lane: string, cb: (tx: unknown) => unknown) => cb(h.db)),
}));

import { pushOfflineReceipts } from '../syncService';
import { withWriteTransaction } from '@/lib/db/writeGate';
import { apiPostRaw } from '@/lib/api';
import {
  updateReceiptStatus,
  updateReceiptStatusByIdempotencyKey,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  markSynced,
  getRefundIntentById,
  InvalidRefundIntentTransitionError,
} from '@/lib/db/repositories/refundIntentRepository';
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
    expect(updateReceiptStatusByIdempotencyKey).toHaveBeenCalledWith(h.db, 'refund-intent-1', 'synced');
    // The own-id lookup (the SALE branch) must NEVER fire for a refund --
    // 'refund-intent-1' is refund_intents.id, not an offline_receipts
    // primary key; calling updateReceiptStatus with it would silently
    // touch zero rows (or, worse, collide with an unrelated receipt id).
    expect(updateReceiptStatus).not.toHaveBeenCalled();
  });

  // v3-refund-chain-integration spec §4.4/§4.6 — the refund_intents row
  // must ALSO reach its own terminal 'synced' state on this same flip, not
  // just the offline_receipts mirror. Without this the row stays stuck at
  // 'refund_event_appended' (an ACTIVE state per §4.4's fold-item-7 fix),
  // permanently blocking every subsequent refund of the same original+line
  // selection -- reproducing the exact bug this spec revision fixed.
  it('§4.4/§4.6 — also transitions the refund_intents row to synced (frees the active-intent index)', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-1', source_event_id: 'refund-intent-1' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-1'));

    await pushOfflineReceipts(db);

    expect(markSynced).toHaveBeenCalledWith(h.db, 'refund-intent-1');
  });

  it('a SALE fiscal event never touches refund_intents.markSynced (own-id lookup only)', async () => {
    const event = makeFiscalEvent({
      id: 'fe-sale-1',
      source_event_class: 'offline_receipts',
      source_event_id: 'receipt-1',
      event_version: 3,
    });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-sale-1'));

    await pushOfflineReceipts(db);

    expect(markSynced).not.toHaveBeenCalled();
  });

  it('tolerates a row already at synced (benign repeat confirmation) without failing the push', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-3', source_event_id: 'refund-intent-3' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-3'));
    vi.mocked(markSynced).mockRejectedValueOnce(
      new InvalidRefundIntentTransitionError('refund-intent-3', 'synced', ['refund_event_appended']),
    );
    vi.mocked(getRefundIntentById).mockResolvedValueOnce({ id: 'refund-intent-3', state: 'synced' } as never);

    const result = await pushOfflineReceipts(db);

    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    // The offline_receipts mirror still flips -- the swallowed transition
    // error must not skip the sibling write above it.
    expect(updateReceiptStatusByIdempotencyKey).toHaveBeenCalledWith(h.db, 'refund-intent-3', 'synced');
  });

  it('propagates a genuinely unexpected markSynced failure (not the benign already-synced case)', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-4', source_event_id: 'refund-intent-4' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-4'));
    vi.mocked(markSynced).mockRejectedValueOnce(new Error('disk I/O error'));

    const result = await pushOfflineReceipts(db);

    // pushOfflineReceipts catches per-event errors and counts them as
    // failed rather than throwing out of the whole sync tick (matching
    // this function's existing per-event error-isolation contract).
    expect(result.failed).toBeGreaterThan(0);
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

/**
 * Wave-2 fix-wave finding 12 (codex M-4) — the three post-ACK flips are
 * ONE transaction, each requires exactly one affected row, and only a
 * RE-READ-CONFIRMED already-`synced` intent is suppressed.
 */
describe('refund ACK local flips — atomicity and affected-row assertions (finding 12)', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(updateReceiptStatusByIdempotencyKey).mockResolvedValue(1);
    vi.mocked(getRefundIntentById).mockResolvedValue({ id: 'refund-intent-1', state: 'synced' } as never);
  });

  it('runs all three flips inside ONE write-gate transaction', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-1', source_event_id: 'refund-intent-1' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-1'));

    await pushOfflineReceipts(db);

    // One transaction for the ACK flips (the 'syncing' pre-flip is a
    // deliberate pre-transaction write — it is what makes the event
    // recoverable if the process dies mid-request).
    const ackTransactions = vi
      .mocked(withWriteTransaction)
      .mock.calls.filter(([lane]) => lane === 'fiscal');
    expect(ackTransactions).toHaveLength(1);
  });

  it('fails the push when the receipt flip matches ZERO rows (never half-applies)', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-9', source_event_id: 'refund-intent-9' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-9'));
    vi.mocked(updateReceiptStatusByIdempotencyKey).mockResolvedValue(0);

    const result = await pushOfflineReceipts(db);

    expect(result.pushed).toBe(0);
    expect(result.failed).toBe(1);
    // The intent transition is never even attempted — the transaction
    // aborts on the row-count assertion.
    expect(markSynced).not.toHaveBeenCalled();
  });

  it('propagates an invalid intent transition when the re-read does NOT confirm synced', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-10', source_event_id: 'refund-intent-10' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-10'));
    vi.mocked(markSynced).mockRejectedValueOnce(
      new InvalidRefundIntentTransitionError('refund-intent-10', 'synced', ['refund_event_appended']),
    );
    // The row is at a WRONG state, not the benign already-synced case.
    vi.mocked(getRefundIntentById).mockResolvedValueOnce({ id: 'refund-intent-10', state: 'drafted' } as never);

    const result = await pushOfflineReceipts(db);

    expect(result.failed).toBe(1);
  });

  it('propagates an invalid intent transition when the row is MISSING entirely', async () => {
    const event = makeFiscalEvent({ id: 'fe-refund-11', source_event_id: 'refund-intent-11' });
    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValue([event]);
    vi.mocked(apiPostRaw).mockResolvedValue(successResponse('fe-refund-11'));
    vi.mocked(markSynced).mockRejectedValueOnce(
      new InvalidRefundIntentTransitionError('refund-intent-11', 'synced', ['refund_event_appended']),
    );
    vi.mocked(getRefundIntentById).mockResolvedValueOnce(null);

    const result = await pushOfflineReceipts(db);

    expect(result.failed).toBe(1);
  });
});
