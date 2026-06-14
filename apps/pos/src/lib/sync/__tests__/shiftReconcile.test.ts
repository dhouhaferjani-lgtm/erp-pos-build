/**
 * Offline-first shifts Phase 6.2 — background reconcile of the device's open
 * shift against the server projection (spec §4.c).
 *
 * `fetchCurrentShift` answers "is a shift open?" purely from local SQLite. A
 * background pass in the sync cycle compares that local OPEN shift against the
 * server's `GET /pos/shifts/{id}` projection:
 *   - server OPEN  → healthy, no-op
 *   - server CLOSED → the shift was closed elsewhere (web-admin recovery). This
 *     is a genuine conflict: surface an advisory, audited banner. Never
 *     auto-close locally (a sale may be mid-flight).
 *   - server 404    → the SESSION_OPEN has not synced/projected yet. Normal
 *     offline state; no action.
 *
 * The audit is idempotent across ticks — a second detection for the same shift
 * does not re-enqueue an outbox event.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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
  return { apiGet: vi.fn(), ApiRequestError };
});

vi.mock('@/lib/db/repositories/localShiftRepository', () => ({
  getCurrentOpenShift: vi.fn(),
}));

import {
  reconcileOpenShift,
  recordRemoteCloseConflict,
  applyShiftReconcileVerdict,
  type ReconcileBannerSink,
  type ShiftReconcileVerdict,
} from '../shiftReconcile';
import { apiGet, ApiRequestError } from '@/lib/api';
import {
  getCurrentOpenShift,
  type LocalShift,
} from '@/lib/db/repositories/localShiftRepository';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  getPendingAuditEvents,
} from '@/lib/db/repositories/queuedAuditEventRepository';

const TERMINAL_ID = 'term-1';
const SHIFT_ID = '019700aa-bbbb-7ccc-8ddd-eeeeffff0001';

function localOpenShift(overrides: Partial<LocalShift> = {}): LocalShift {
  return {
    id: SHIFT_ID,
    terminal_id: TERMINAL_ID,
    session_id: SHIFT_ID,
    shift_number: 7,
    status: 'OPEN' as const,
    opening_cash: '100.000',
    opened_at: '2026-06-14T08:00:00Z',
    closed_at: null,
    cashier_id: 'cashier-1',
    cashier_name: 'Alice',
    fiscal_shift_id: SHIFT_ID,
    ...overrides,
  };
}

describe('reconcileOpenShift (detection)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const db = {} as import('@tauri-apps/plugin-sql').default;

  it('returns none when there is no local open shift', async () => {
    vi.mocked(getCurrentOpenShift).mockResolvedValue(null);

    expect(await reconcileOpenShift(db, TERMINAL_ID)).toEqual({ kind: 'none' });
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('returns healthy when the server still has the shift OPEN', async () => {
    vi.mocked(getCurrentOpenShift).mockResolvedValue(localOpenShift());
    vi.mocked(apiGet).mockResolvedValue({ id: SHIFT_ID, status: 'OPEN', shift_number: 7 });

    expect(await reconcileOpenShift(db, TERMINAL_ID)).toEqual({ kind: 'healthy', shiftId: SHIFT_ID });
    expect(apiGet).toHaveBeenCalledWith(`/pos/shifts/${SHIFT_ID}`);
  });

  it('returns closed_remotely when the server has the shift CLOSED', async () => {
    vi.mocked(getCurrentOpenShift).mockResolvedValue(localOpenShift());
    vi.mocked(apiGet).mockResolvedValue({ id: SHIFT_ID, status: 'CLOSED', shift_number: 7 });

    expect(await reconcileOpenShift(db, TERMINAL_ID)).toEqual({
      kind: 'closed_remotely',
      shiftId: SHIFT_ID,
      shiftNumber: 7,
    });
  });

  it('returns not_projected when the server 404s (not synced yet)', async () => {
    vi.mocked(getCurrentOpenShift).mockResolvedValue(localOpenShift());
    vi.mocked(apiGet).mockRejectedValue(new ApiRequestError(404, 'Not Found', 'NOT_FOUND'));

    expect(await reconcileOpenShift(db, TERMINAL_ID)).toEqual({
      kind: 'not_projected',
      shiftId: SHIFT_ID,
    });
  });

  it('returns unknown on a transient network error (no banner flip)', async () => {
    vi.mocked(getCurrentOpenShift).mockResolvedValue(localOpenShift());
    vi.mocked(apiGet).mockRejectedValue(new Error('offline'));

    expect(await reconcileOpenShift(db, TERMINAL_ID)).toEqual({
      kind: 'unknown',
      shiftId: SHIFT_ID,
    });
  });
});

describe('recordRemoteCloseConflict (idempotent audit)', () => {
  let adapter: SqliteTestAdapter;
  const ctx = { tenantId: 'tenant-1', companyId: 'company-1', operatorId: 'op-1' };

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('enqueues an advisory audit event for the remote close', async () => {
    const db = adapter.asDatabase();
    const enqueued = await recordRemoteCloseConflict(
      db,
      { shiftId: SHIFT_ID, shiftNumber: 7 },
      ctx,
    );

    expect(enqueued).toBe(true);
    const pending = await getPendingAuditEvents(db);
    expect(pending).toHaveLength(1);
    expect(pending[0]!.eventType).toBe('pos.shift.remote_close_detected');
    expect(pending[0]!.aggregateId).toBe(SHIFT_ID);
    const payload = JSON.parse(pending[0]!.payload) as { shift_id: string; shift_number: number };
    expect(payload.shift_id).toBe(SHIFT_ID);
    expect(payload.shift_number).toBe(7);
  });

  it('is idempotent — a second detection does not re-enqueue', async () => {
    const db = adapter.asDatabase();
    await recordRemoteCloseConflict(db, { shiftId: SHIFT_ID, shiftNumber: 7 }, ctx);
    const second = await recordRemoteCloseConflict(db, { shiftId: SHIFT_ID, shiftNumber: 7 }, ctx);

    expect(second).toBe(false);
    expect(await getPendingAuditEvents(db)).toHaveLength(1);
  });
});

describe('applyShiftReconcileVerdict (banner + audit orchestration)', () => {
  let adapter: SqliteTestAdapter;
  const ctx = { tenantId: 'tenant-1', companyId: 'company-1', operatorId: 'op-1' };

  function makeBanner(): ReconcileBannerSink & {
    flagged: Array<{ shiftId: string; shiftNumber: number }>;
    cleared: number;
  } {
    const flagged: Array<{ shiftId: string; shiftNumber: number }> = [];
    let cleared = 0;
    return {
      flagged,
      get cleared() {
        return cleared;
      },
      flag(conflict) {
        flagged.push(conflict);
      },
      clear() {
        cleared += 1;
      },
    };
  }

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('flags the banner and audits on a remote close', async () => {
    const db = adapter.asDatabase();
    const banner = makeBanner();
    const verdict: ShiftReconcileVerdict = {
      kind: 'closed_remotely',
      shiftId: SHIFT_ID,
      shiftNumber: 7,
    };

    await applyShiftReconcileVerdict(db, verdict, ctx, banner);

    expect(banner.flagged).toEqual([{ shiftId: SHIFT_ID, shiftNumber: 7 }]);
    expect(banner.cleared).toBe(0);
    expect(await getPendingAuditEvents(db)).toHaveLength(1);
  });

  it('flags but does not audit when the tenant context is missing', async () => {
    const db = adapter.asDatabase();
    const banner = makeBanner();
    const verdict: ShiftReconcileVerdict = {
      kind: 'closed_remotely',
      shiftId: SHIFT_ID,
      shiftNumber: 7,
    };

    await applyShiftReconcileVerdict(
      db,
      verdict,
      { tenantId: '', companyId: null, operatorId: null },
      banner,
    );

    expect(banner.flagged).toHaveLength(1);
    expect(await getPendingAuditEvents(db)).toHaveLength(0);
  });

  it.each(['healthy', 'none', 'not_projected'] as const)(
    'clears the banner on a %s verdict',
    async (kind) => {
      const db = adapter.asDatabase();
      const banner = makeBanner();
      const verdict =
        kind === 'none'
          ? ({ kind } as ShiftReconcileVerdict)
          : ({ kind, shiftId: SHIFT_ID } as ShiftReconcileVerdict);

      await applyShiftReconcileVerdict(db, verdict, ctx, banner);

      expect(banner.cleared).toBe(1);
      expect(banner.flagged).toHaveLength(0);
    },
  );

  it('leaves the banner untouched on an unknown (transient) verdict', async () => {
    const db = adapter.asDatabase();
    const banner = makeBanner();

    await applyShiftReconcileVerdict(db, { kind: 'unknown', shiftId: SHIFT_ID }, ctx, banner);

    expect(banner.flagged).toHaveLength(0);
    expect(banner.cleared).toBe(0);
  });
});
