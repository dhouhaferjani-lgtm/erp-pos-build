import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

const auditRepo = {
  getPendingAuditEvents: vi.fn(),
  markAuditEventsSyncing: vi.fn().mockResolvedValue(undefined),
  markAuditEventSynced: vi.fn().mockResolvedValue(undefined),
  markAuditEventFailed: vi.fn().mockResolvedValue(undefined),
  recoverStrandedSyncingAuditEvents: vi.fn().mockResolvedValue(0),
  pruneSyncedAuditEvents: vi.fn().mockResolvedValue(undefined),
};
vi.mock('@/lib/db/repositories/queuedAuditEventRepository', () => ({
  getPendingAuditEvents: (...a: unknown[]) => auditRepo.getPendingAuditEvents(...a),
  markAuditEventsSyncing: (...a: unknown[]) => auditRepo.markAuditEventsSyncing(...a),
  markAuditEventSynced: (...a: unknown[]) => auditRepo.markAuditEventSynced(...a),
  markAuditEventFailed: (...a: unknown[]) => auditRepo.markAuditEventFailed(...a),
  recoverStrandedSyncingAuditEvents: (...a: unknown[]) =>
    auditRepo.recoverStrandedSyncingAuditEvents(...a),
  pruneSyncedAuditEvents: (...a: unknown[]) => auditRepo.pruneSyncedAuditEvents(...a),
  MAX_AUDIT_RETRIES: 10,
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
}));

const connectivityState = { isOnline: true };
vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: { getState: () => connectivityState },
}));

import {
  pushQueuedAuditEvents,
  MAX_AUDIT_BATCHES_PER_TICK,
} from '../syncService';
import { apiPost } from '@/lib/api';
import type { QueuedAuditEvent } from '@/lib/db/repositories/queuedAuditEventRepository';

const db = {} as import('@tauri-apps/plugin-sql').default;

function makeEvent(id: number): QueuedAuditEvent {
  return {
    id,
    eventId: `evt-${String(id)}`,
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
}

describe('pushQueuedAuditEvents', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    connectivityState.isOnline = true;
    auditRepo.getPendingAuditEvents.mockResolvedValue([]);
    vi.mocked(apiPost).mockResolvedValue({ data: { created: 0, duplicates: 0 } });
  });

  it('drains a single batch of pending events, marks them syncing then synced', async () => {
    const events = [makeEvent(1), makeEvent(2)];
    auditRepo.getPendingAuditEvents
      .mockResolvedValueOnce(events)
      .mockResolvedValueOnce([]);

    const total = await pushQueuedAuditEvents(db);

    expect(total).toBe(2);
    expect(auditRepo.markAuditEventsSyncing).toHaveBeenCalledWith(db, [1, 2]);

    // POST envelope shape: snake_case fields, parsed payload/metadata
    expect(apiPost).toHaveBeenCalledTimes(1);
    const [path, body] = vi.mocked(apiPost).mock.calls[0]! as [
      string,
      { events: Array<Record<string, unknown>> },
    ];
    expect(path).toBe('/pos/audit-events/sync');
    expect(body.events).toHaveLength(2);
    const env = body.events[0]!;
    expect(env.event_id).toBe('evt-1');
    expect(env.event_type).toBe('pos.login');
    expect(env.aggregate_type).toBe('PosSession');
    expect(env.aggregate_id).toBe('device-1');
    expect(env.tenant_id).toBe('tenant-1');
    expect(env.company_id).toBe('company-1');
    expect(env.operator_id).toBe('operator-1');
    expect(env.payload).toEqual({ multi_tenant: true });
    expect(env.metadata).toEqual({ device_id: 'd1' });
    expect(env.occurred_at).toBe('2026-06-04T10:00:00.000Z');

    expect(auditRepo.markAuditEventSynced).toHaveBeenCalledWith(db, 1);
    expect(auditRepo.markAuditEventSynced).toHaveBeenCalledWith(db, 2);
    expect(auditRepo.markAuditEventFailed).not.toHaveBeenCalled();
  });

  it('marks every event in a batch failed and stops the tick on POST error', async () => {
    const events = [makeEvent(1), makeEvent(2)];
    auditRepo.getPendingAuditEvents.mockResolvedValueOnce(events);
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('network down'));

    const total = await pushQueuedAuditEvents(db);

    expect(total).toBe(0);
    expect(auditRepo.markAuditEventFailed).toHaveBeenCalledWith(db, 1, 'network down');
    expect(auditRepo.markAuditEventFailed).toHaveBeenCalledWith(db, 2, 'network down');
    expect(auditRepo.markAuditEventSynced).not.toHaveBeenCalled();
    // stopped: only one getPending call (no second batch attempt)
    expect(auditRepo.getPendingAuditEvents).toHaveBeenCalledTimes(1);
  });

  it('drains multiple batches in one tick (250 pending -> 3 batches, 3 POSTs)', async () => {
    const batch1 = Array.from({ length: 100 }, (_, i) => makeEvent(i + 1));
    const batch2 = Array.from({ length: 100 }, (_, i) => makeEvent(i + 101));
    const batch3 = Array.from({ length: 50 }, (_, i) => makeEvent(i + 201));
    auditRepo.getPendingAuditEvents
      .mockResolvedValueOnce(batch1)
      .mockResolvedValueOnce(batch2)
      .mockResolvedValueOnce(batch3)
      .mockResolvedValueOnce([]);

    const total = await pushQueuedAuditEvents(db);

    expect(total).toBe(250);
    expect(apiPost).toHaveBeenCalledTimes(3);
    expect(auditRepo.getPendingAuditEvents).toHaveBeenCalledWith(db, 100);
  });

  it('stops at the per-tick batch budget', async () => {
    // Always return a full batch so the loop would never naturally stop.
    auditRepo.getPendingAuditEvents.mockResolvedValue(
      Array.from({ length: 100 }, (_, i) => makeEvent(i + 1)),
    );

    const total = await pushQueuedAuditEvents(db);

    expect(apiPost).toHaveBeenCalledTimes(MAX_AUDIT_BATCHES_PER_TICK);
    expect(total).toBe(100 * MAX_AUDIT_BATCHES_PER_TICK);
  });

  it('is a no-op when offline', async () => {
    connectivityState.isOnline = false;
    auditRepo.getPendingAuditEvents.mockResolvedValue([makeEvent(1)]);

    const total = await pushQueuedAuditEvents(db);

    expect(total).toBe(0);
    expect(auditRepo.getPendingAuditEvents).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
  });

  it('returns 0 when the queue is empty (no POST)', async () => {
    auditRepo.getPendingAuditEvents.mockResolvedValueOnce([]);
    const total = await pushQueuedAuditEvents(db);
    expect(total).toBe(0);
    expect(apiPost).not.toHaveBeenCalled();
  });
});
