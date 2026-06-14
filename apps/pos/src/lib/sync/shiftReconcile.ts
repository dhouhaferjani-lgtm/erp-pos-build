/**
 * Offline-first shifts Phase 6.2 — background reconcile of the device's open
 * shift against the server projection (spec §4.c).
 *
 * `fetchCurrentShift` answers "is a shift open on this terminal?" purely from
 * local SQLite (`local_shifts`), never the network. This module is the
 * background pass that detects the one case where local truth and the server
 * projection genuinely diverge: the shift was closed elsewhere (a web-admin
 * recovery `SESSION_CLOSE`-on-behalf) while the device still has it OPEN.
 *
 * It is **advisory** — it never auto-closes the local shift (a sale may be
 * mid-flight; Decision 4 disallows web force-close by default). It surfaces an
 * audited banner instead, and the audit is idempotent across ticks. This fixes
 * the legacy "server silently wins on reconnect" bug without flipping local
 * state behind the operator's back.
 */
import type Database from '@tauri-apps/plugin-sql';
import { apiGet, ApiRequestError } from '@/lib/api';
import { getCurrentOpenShift } from '@/lib/db/repositories/localShiftRepository';
import {
  auditEventExistsForAggregate,
  enqueueAuditEvent,
} from '@/lib/db/repositories/queuedAuditEventRepository';

/** Event type for the advisory remote-close audit (outbox + dedup key). */
export const REMOTE_CLOSE_AUDIT_EVENT_TYPE = 'pos.shift.remote_close_detected';

/**
 * The outcome of reconciling the device's local OPEN shift against the server's
 * `GET /pos/shifts/{id}` projection:
 * - `none`            — no local open shift; nothing to reconcile.
 * - `healthy`         — server still has the shift OPEN (or any non-closed
 *                       state); the projection agrees with local truth.
 * - `closed_remotely` — server has the shift CLOSED: a genuine conflict
 *                       (closed via web-admin recovery). Surface the banner.
 * - `not_projected`   — server 404s: the SESSION_OPEN has not synced/projected
 *                       yet. Normal offline state; no action.
 * - `unknown`         — transient network / unexpected error; leave any
 *                       existing banner untouched (no flip on a blip).
 */
export type ShiftReconcileVerdict =
  | { kind: 'none' }
  | { kind: 'healthy'; shiftId: string }
  | { kind: 'closed_remotely'; shiftId: string; shiftNumber: number }
  | { kind: 'not_projected'; shiftId: string }
  | { kind: 'unknown'; shiftId: string };

interface ServerShiftStatusResponse {
  id: string;
  status: string;
  shift_number: number;
}

/**
 * Compare the device's local OPEN shift against the server projection. Pure
 * detection — no side effects (banner + audit are applied by the caller so the
 * verdict stays unit-testable and the orchestration stays explicit).
 */
export async function reconcileOpenShift(
  db: Database,
  terminalId: string,
): Promise<ShiftReconcileVerdict> {
  const local = await getCurrentOpenShift(db, terminalId);
  if (!local) {
    return { kind: 'none' };
  }

  try {
    const remote = await apiGet<ServerShiftStatusResponse>(`/pos/shifts/${local.id}`);
    if (remote.status === 'CLOSED') {
      return { kind: 'closed_remotely', shiftId: local.id, shiftNumber: local.shift_number };
    }
    return { kind: 'healthy', shiftId: local.id };
  } catch (error) {
    if (error instanceof ApiRequestError && error.status === 404) {
      // Not found server-side → the open has not synced/projected yet.
      return { kind: 'not_projected', shiftId: local.id };
    }
    // Offline / 5xx / 403 / unexpected — advisory only, never flip the banner
    // on a transient blip. The next tick re-reconciles.
    return { kind: 'unknown', shiftId: local.id };
  }
}

/**
 * Where the reconcile surfaces the advisory banner. Injected so the branching
 * orchestration stays unit-testable without mocking the terminal store; the
 * production caller wires these to the store actions.
 */
export interface ReconcileBannerSink {
  flag(conflict: { shiftId: string; shiftNumber: number }): void;
  clear(): void;
}

/**
 * Apply a reconcile verdict: surface/clear the advisory banner and, on a remote
 * close, enqueue the idempotent audit event. Never auto-closes the local shift.
 * - `closed_remotely` → flag the banner + audit (audit only when a tenant
 *   context is available; the banner is always shown so the operator is aware).
 * - `healthy` / `none` / `not_projected` → clear the banner (no active conflict
 *   for the current open shift).
 * - `unknown` → leave the banner untouched (don't flip on a transient blip).
 */
export async function applyShiftReconcileVerdict(
  db: Database,
  verdict: ShiftReconcileVerdict,
  ctx: { tenantId: string; companyId: string | null; operatorId: string | null },
  banner: ReconcileBannerSink,
): Promise<void> {
  switch (verdict.kind) {
    case 'closed_remotely':
      if (ctx.tenantId) {
        await recordRemoteCloseConflict(
          db,
          { shiftId: verdict.shiftId, shiftNumber: verdict.shiftNumber },
          ctx,
        );
      }
      banner.flag({ shiftId: verdict.shiftId, shiftNumber: verdict.shiftNumber });
      break;
    case 'healthy':
    case 'none':
    case 'not_projected':
      banner.clear();
      break;
    case 'unknown':
      // Transient — leave any existing banner as-is; the next tick re-checks.
      break;
  }
}

/**
 * Enqueue the advisory remote-close audit event, idempotently. Returns true
 * when an event was newly enqueued, false when one already exists for the shift
 * (so a persistent conflict is audited once, not once per sync tick). The DB
 * dedup also survives app restarts, unlike the in-memory banner.
 */
export async function recordRemoteCloseConflict(
  db: Database,
  conflict: { shiftId: string; shiftNumber: number },
  ctx: { tenantId: string; companyId: string | null; operatorId: string | null },
): Promise<boolean> {
  if (await auditEventExistsForAggregate(db, REMOTE_CLOSE_AUDIT_EVENT_TYPE, conflict.shiftId)) {
    return false;
  }

  const occurredAt = new Date().toISOString();
  await enqueueAuditEvent(db, {
    eventId: crypto.randomUUID(),
    eventType: REMOTE_CLOSE_AUDIT_EVENT_TYPE,
    aggregateType: 'pos_shift',
    aggregateId: conflict.shiftId,
    tenantId: ctx.tenantId,
    companyId: ctx.companyId,
    operatorId: ctx.operatorId,
    payload: JSON.stringify({
      shift_id: conflict.shiftId,
      shift_number: conflict.shiftNumber,
      detected_at: occurredAt,
      reason: 'shift closed remotely while open on device',
    }),
    metadata: JSON.stringify({ source: 'shift_reconcile', advisory: true }),
    occurredAt,
    status: 'pending',
    retryCount: 0,
  });
  return true;
}
