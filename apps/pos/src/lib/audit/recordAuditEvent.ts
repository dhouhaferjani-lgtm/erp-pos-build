import { getDatabase } from '@/lib/db';
import { enqueueAuditEvent } from '@/lib/db/repositories/queuedAuditEventRepository';
import { getDeviceId } from '@/lib/device';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import type { PosAuditEventType } from './eventTypes';

/**
 * Build-stamped app version for the audit metadata. Falls back to '0.0.0' when
 * the `VITE_APP_VERSION` define is absent (dev / test).
 */
const APP_VERSION = import.meta.env.VITE_APP_VERSION ?? '0.0.0';

export interface RecordAuditEventInput {
  type: PosAuditEventType;
  aggregateType: string;
  aggregateId: string;
  payload?: Record<string, unknown>;
  /**
   * Explicit context overrides. Used where the store may not be committed yet
   * (e.g. `pos.login`, where the just-resolved user/tenant is passed directly).
   */
  tenantId?: string;
  companyId?: string | null;
  operatorId?: string | null;
  /** Business timestamp; defaults to now (client ISO). */
  occurredAt?: string;
}

/**
 * Best-effort emit helper for the POS audit / fraud-detection pipeline.
 *
 * Stamps a client `event_id` (UUID — becomes the server `audit_events` PK),
 * resolves tenant/company/operator from explicit input or the stores, attaches
 * device/terminal/shift/offline/version metadata, and enqueues the row into the
 * `queued_audit_events` outbox for the sync drain to push.
 *
 * **Best-effort by contract:** the entire body is wrapped in try/catch. A
 * missing tenant or a `getDatabase()` failure → `console.warn` + return; it
 * NEVER throws into the caller. Callers MUST fire-and-forget OUTSIDE any
 * Zustand `set()` updater: `void recordAuditEvent(...).catch(() => {})`.
 */
export async function recordAuditEvent(input: RecordAuditEventInput): Promise<void> {
  try {
    const auth = useAuthStore.getState();
    const tenantId = input.tenantId ?? auth.user?.tenantId;
    if (!tenantId) return; // drop silently if no tenant context

    const term = useTerminalStore.getState();
    const operatorId =
      input.operatorId ??
      useOperatorStore.getState().operator?.id ??
      auth.user?.id ??
      null;

    const row = {
      eventId: crypto.randomUUID(),
      eventType: input.type,
      aggregateType: input.aggregateType,
      aggregateId: input.aggregateId,
      tenantId,
      companyId: input.companyId ?? auth.companyId ?? null,
      operatorId,
      payload: JSON.stringify(input.payload ?? {}),
      metadata: JSON.stringify({
        device_id: getDeviceId(),
        terminal_id: term.terminal?.id ?? null,
        shift_id: term.shift?.id ?? null,
        is_offline: !useConnectivityStore.getState().isOnline,
        app_version: APP_VERSION,
      }),
      occurredAt: input.occurredAt ?? new Date().toISOString(),
      status: 'pending' as const,
      retryCount: 0,
    };

    const db = await getDatabase(auth.companyId ?? '');
    await enqueueAuditEvent(db, row);
  } catch (error) {
    console.warn('[audit] recordAuditEvent failed (non-fatal):', error);
  }
}
