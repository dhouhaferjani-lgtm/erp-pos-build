import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

/**
 * OUTBOX repository for the POS audit / fraud-detection pipeline (Sub-Spec C).
 *
 * Mirrors the fiscal-event / cash-drawer outbox retry pattern (NOT the simpler
 * `queued_pin_updates` clone): `getPending` returns pending AND failed rows
 * under the retry cap so transient failures are retried; `recoverStranded`
 * demotes crash-stranded `syncing` rows on boot; `prune` deletes only `synced`
 * rows past the retention window (failed rows are never silently dropped — they
 * surface via `countPendingAuditEvents`).
 */

export type AuditEventSyncStatus = 'pending' | 'syncing' | 'synced' | 'failed';

export interface QueuedAuditEvent {
  id: number;
  eventId: string;
  eventType: string;
  aggregateType: string;
  aggregateId: string;
  tenantId: string;
  companyId: string | null;
  operatorId: string | null;
  payload: string;
  metadata: string;
  occurredAt: string;
  status: AuditEventSyncStatus;
  retryCount: number;
}

interface QueuedAuditEventRow {
  id: number;
  event_id: string;
  event_type: string;
  aggregate_type: string;
  aggregate_id: string;
  tenant_id: string;
  company_id: string | null;
  operator_id: string | null;
  payload: string;
  metadata: string;
  occurred_at: string;
  status: AuditEventSyncStatus;
  retry_count: number;
}

/**
 * Dead-letter cap. A row whose `retry_count` reaches this value stays in the
 * table (queryable + counted via `countPendingAuditEvents`) but is excluded
 * from the active drain so a permanently-rejecting event can't wedge the queue.
 */
export const MAX_AUDIT_RETRIES = 10;

function rowToEvent(row: QueuedAuditEventRow): QueuedAuditEvent {
  return {
    id: row.id,
    eventId: row.event_id,
    eventType: row.event_type,
    aggregateType: row.aggregate_type,
    aggregateId: row.aggregate_id,
    tenantId: row.tenant_id,
    companyId: row.company_id,
    operatorId: row.operator_id,
    payload: row.payload,
    metadata: row.metadata,
    occurredAt: row.occurred_at,
    status: row.status,
    retryCount: row.retry_count,
  };
}

export async function enqueueAuditEvent(
  db: Database,
  row: Omit<QueuedAuditEvent, 'id'>,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO queued_audit_events (
       event_id, event_type, aggregate_type, aggregate_id,
       tenant_id, company_id, operator_id,
       payload, metadata, occurred_at, status, retry_count
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)`,
    [
      row.eventId,
      row.eventType,
      row.aggregateType,
      row.aggregateId,
      row.tenantId,
      row.companyId,
      row.operatorId,
      row.payload,
      row.metadata,
      row.occurredAt,
      row.status,
      row.retryCount,
    ],
  );
}

export async function getPendingAuditEvents(
  db: Database,
  limit = 100,
): Promise<QueuedAuditEvent[]> {
  const rows = await queryAll<QueuedAuditEventRow>(
    db,
    `SELECT * FROM queued_audit_events
      WHERE status IN ('pending', 'failed') AND retry_count < $1
      ORDER BY id ASC
      LIMIT $2`,
    [MAX_AUDIT_RETRIES, limit],
  );
  return rows.map(rowToEvent);
}

export async function markAuditEventsSyncing(
  db: Database,
  ids: number[],
): Promise<void> {
  if (ids.length === 0) return;
  const placeholders = ids.map((_, i) => `$${String(i + 1)}`).join(', ');
  await execute(
    db,
    `UPDATE queued_audit_events SET status = 'syncing', sync_error = NULL WHERE id IN (${placeholders})`,
    ids,
  );
}

export async function markAuditEventSynced(
  db: Database,
  id: number,
): Promise<void> {
  await execute(
    db,
    `UPDATE queued_audit_events SET status = 'synced', synced_at = datetime('now'), sync_error = NULL WHERE id = $1`,
    [id],
  );
}

export async function markAuditEventFailed(
  db: Database,
  id: number,
  error: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE queued_audit_events
        SET status = 'failed', retry_count = retry_count + 1, sync_error = $1
      WHERE id = $2`,
    [error, id],
  );
}

/**
 * Boot-time recovery for rows stranded at `syncing` by a crash / power-cut
 * between `markAuditEventsSyncing` and the response handler. Demotes them to
 * `pending` so the next drain re-attempts; the server's per-event idempotent
 * insert dedups any double-delivery. Returns the number of rows demoted.
 */
export async function recoverStrandedSyncingAuditEvents(
  db: Database,
): Promise<number> {
  const result = await execute(
    db,
    "UPDATE queued_audit_events SET status = 'pending', sync_error = NULL WHERE status = 'syncing'",
  );
  return result.rowsAffected;
}

/**
 * Prune `synced` rows older than `keepDays`. Only `synced` rows are deleted —
 * pending / syncing / failed rows are retained (failed rows surface via
 * `countPendingAuditEvents` rather than being silently dropped).
 */
export async function pruneSyncedAuditEvents(
  db: Database,
  keepDays = 14,
): Promise<void> {
  await execute(
    db,
    `DELETE FROM queued_audit_events
      WHERE status = 'synced'
        AND created_at < datetime('now', '-${String(keepDays)} days')`,
  );
}

export async function countPendingAuditEvents(db: Database): Promise<number> {
  const row = await queryOne<{ count: number }>(
    db,
    `SELECT COUNT(*) AS count FROM queued_audit_events
      WHERE status IN ('pending', 'failed') AND retry_count < $1`,
    [MAX_AUDIT_RETRIES],
  );
  return row?.count ?? 0;
}
