import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
}));

import {
  enqueueAuditEvent,
  getPendingAuditEvents,
  markAuditEventsSyncing,
  markAuditEventSynced,
  markAuditEventFailed,
  recoverStrandedSyncingAuditEvents,
  pruneSyncedAuditEvents,
  countPendingAuditEvents,
  MAX_AUDIT_RETRIES,
  type QueuedAuditEvent,
} from '../queuedAuditEventRepository';
import { queryAll, queryOne, execute } from '@/lib/db';

const db = {} as import('@tauri-apps/plugin-sql').default;

function makeRow(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 1,
    event_id: 'evt-1',
    event_type: 'pos.login',
    aggregate_type: 'PosSession',
    aggregate_id: 'device-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    operator_id: 'operator-1',
    payload: '{"multi_tenant":true}',
    metadata: '{"device_id":"d1"}',
    occurred_at: '2026-06-04T10:00:00.000Z',
    status: 'pending',
    retry_count: 0,
    ...overrides,
  };
}

describe('queuedAuditEventRepository', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('enqueues a pending audit event row', async () => {
    const row: Omit<QueuedAuditEvent, 'id'> = {
      eventId: 'evt-1',
      eventType: 'pos.login',
      aggregateType: 'PosSession',
      aggregateId: 'device-1',
      tenantId: 'tenant-1',
      companyId: 'company-1',
      operatorId: 'operator-1',
      payload: '{"multi_tenant":true}',
      metadata: '{"device_id":"d1"}',
      occurredAt: '2026-06-04T10:00:00.000Z',
      status: 'pending',
      retryCount: 0,
    };

    await enqueueAuditEvent(db, row);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO queued_audit_events/);
    expect(params).toEqual(
      expect.arrayContaining([
        'evt-1',
        'pos.login',
        'PosSession',
        'device-1',
        'tenant-1',
        'company-1',
        'operator-1',
        '{"multi_tenant":true}',
        '{"device_id":"d1"}',
        '2026-06-04T10:00:00.000Z',
        'pending',
      ]),
    );
  });

  it('getPending selects pending+failed under the retry cap, ordered by id', async () => {
    vi.mocked(queryAll).mockResolvedValue([makeRow({ id: 3 })]);

    const rows = await getPendingAuditEvents(db, 100);

    expect(rows).toHaveLength(1);
    expect(rows[0]?.eventId).toBe('evt-1');
    expect(rows[0]?.id).toBe(3);
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/WHERE status IN \('pending', 'failed'\)/);
    expect(sql).toMatch(/retry_count < /);
    expect(sql).toMatch(/ORDER BY id ASC/);
    expect(sql).toMatch(/LIMIT/);
    // MAX cap + limit are bound params
    expect(params).toEqual(expect.arrayContaining([MAX_AUDIT_RETRIES, 100]));
  });

  it('getPending defaults the limit to 100', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);
    await getPendingAuditEvents(db);
    const [, , params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(params).toEqual(expect.arrayContaining([100]));
  });

  it('returns failed rows so they are retried, but never returns synced/syncing', async () => {
    // The SQL filter is asserted above; here we confirm the row mapper carries
    // a failed row through faithfully (status preserved, retry_count surfaced).
    vi.mocked(queryAll).mockResolvedValue([
      makeRow({ id: 5, status: 'failed', retry_count: 3 }),
    ]);
    const rows = await getPendingAuditEvents(db, 100);
    expect(rows[0]?.status).toBe('failed');
    expect(rows[0]?.retryCount).toBe(3);
  });

  it('marks a batch of ids as syncing', async () => {
    await markAuditEventsSyncing(db, [1, 2, 3]);
    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/UPDATE queued_audit_events SET status = 'syncing'/);
    expect(sql).toMatch(/WHERE id IN \(\$1, \$2, \$3\)/);
    expect(params).toEqual([1, 2, 3]);
  });

  it('markAuditEventsSyncing is a no-op on an empty id list', async () => {
    await markAuditEventsSyncing(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('marks a row synced and stamps synced_at', async () => {
    await markAuditEventSynced(db, 42);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/UPDATE queued_audit_events SET status = 'synced'/);
    expect(sql).toMatch(/synced_at = datetime\('now'\)/);
    expect(params).toEqual([42]);
  });

  it('marks a row failed, increments retry_count, and records the error', async () => {
    await markAuditEventFailed(db, 7, 'network down');
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/status = 'failed'/);
    expect(sql).toMatch(/retry_count = retry_count \+ 1/);
    expect(params).toEqual(['network down', 7]);
  });

  it('recoverStranded flips syncing rows back to pending', async () => {
    vi.mocked(execute).mockResolvedValueOnce({ rowsAffected: 2 });
    const recovered = await recoverStrandedSyncingAuditEvents(db);
    expect(recovered).toBe(2);
    const [, sql] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/UPDATE queued_audit_events SET status = 'pending'/);
    expect(sql).toMatch(/WHERE status = 'syncing'/);
  });

  it('prune deletes only synced rows older than keepDays', async () => {
    await pruneSyncedAuditEvents(db, 14);
    const [, sql] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/DELETE FROM queued_audit_events/);
    expect(sql).toMatch(/status = 'synced'/);
    expect(sql).toMatch(/-14 days/);
    // never prunes failed/pending/syncing rows
    expect(sql).not.toMatch(/status = 'failed'/);
  });

  it('prune defaults keepDays to 14', async () => {
    await pruneSyncedAuditEvents(db);
    const [, sql] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/-14 days/);
  });

  it('counts pending+failed rows under the cap', async () => {
    vi.mocked(queryOne).mockResolvedValue({ count: 5 });
    const n = await countPendingAuditEvents(db);
    expect(n).toBe(5);
    const [, sql, params] = vi.mocked(queryOne).mock.calls[0]!;
    expect(sql).toMatch(/SELECT COUNT\(\*\) AS count FROM queued_audit_events/);
    expect(sql).toMatch(/status IN \('pending', 'failed'\)/);
    expect(sql).toMatch(/retry_count < /);
    expect(params).toEqual([MAX_AUDIT_RETRIES]);
  });

  it('countPending returns 0 when the count query yields null', async () => {
    vi.mocked(queryOne).mockResolvedValue(null);
    const n = await countPendingAuditEvents(db);
    expect(n).toBe(0);
  });

  it('exposes a retry cap of 10', () => {
    expect(MAX_AUDIT_RETRIES).toBe(10);
  });
});
