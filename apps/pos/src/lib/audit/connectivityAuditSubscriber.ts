import { getDatabase } from '@/lib/db';
import { getPendingCashDrawerOps } from '@/lib/db/repositories/cashDrawerRepository';
import { getPendingReceiptCount } from '@/lib/db/repositories/offlineReceiptRepository';
import { countPendingAuditEvents } from '@/lib/db/repositories/queuedAuditEventRepository';
import { getDeviceId } from '@/lib/device';
import { useAuthStore } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { recordAuditEvent } from './recordAuditEvent';

/**
 * Connectivity-transition audit subscriber (Sub-Spec C, Task 12).
 *
 * `connectivityStore` only holds the *current* `isOnline` flag — it exposes no
 * edge callback (Codex r1 MAJOR3). This module is the single owner of the
 * online↔offline *edge*: it subscribes ONCE to the store, tracks the previous
 * online state plus the wall-clock at which the current online/offline window
 * began, and emits EXACTLY ONCE per transition:
 *
 *   - online → offline → `pos.went_offline { online_duration_ms }`
 *   - offline → online → `pos.went_online { offline_duration_ms, queued_receipts,
 *       queued_cash_ops, queued_audit }` (queued counts read from SQLite).
 *
 * The very first Zustand emission after subscribe is not a transition (the store
 * may settle its boot state); the `previousIsOnline` seed taken at `start()`
 * means a same-value emission is a no-op and only a genuine flip emits. Emits
 * are best-effort (delegated to `recordAuditEvent`, which never throws); a
 * count-read failure degrades the queued counts to `null` rather than dropping
 * the event.
 */

let unsubscribe: (() => void) | null = null;
let previousIsOnline = false;
let onlineWindowStartedAt = 0;
let offlineWindowStartedAt = 0;

interface QueuedCounts {
  queued_receipts: number | null;
  queued_cash_ops: number | null;
  queued_audit: number | null;
}

/**
 * Read the offline-backlog counts from SQLite for the `pos.went_online` payload.
 * Best-effort: any failure (no company context, DB open failure, query error)
 * degrades the affected count(s) to `null` so the event still records.
 */
async function readQueuedCounts(): Promise<QueuedCounts> {
  const counts: QueuedCounts = {
    queued_receipts: null,
    queued_cash_ops: null,
    queued_audit: null,
  };

  try {
    const companyId = useAuthStore.getState().companyId;
    if (!companyId) return counts;

    const db = await getDatabase(companyId);

    counts.queued_receipts = await getPendingReceiptCount(db);
    counts.queued_cash_ops = (await getPendingCashDrawerOps(db)).length;
    counts.queued_audit = await countPendingAuditEvents(db);
  } catch (error) {
    console.warn('[audit] readQueuedCounts failed (non-fatal):', error);
  }

  return counts;
}

function handleTransition(isOnline: boolean): void {
  if (isOnline === previousIsOnline) return; // not an edge — no-op

  const now = Date.now();
  const deviceId = getDeviceId();

  if (isOnline) {
    // offline → online
    const offlineDurationMs = offlineWindowStartedAt > 0 ? now - offlineWindowStartedAt : 0;
    onlineWindowStartedAt = now;
    previousIsOnline = true;

    void (async () => {
      const queued = await readQueuedCounts();
      void recordAuditEvent({
        type: 'pos.went_online',
        aggregateType: 'PosSession',
        aggregateId: deviceId,
        payload: {
          offline_duration_ms: offlineDurationMs,
          queued_receipts: queued.queued_receipts,
          queued_cash_ops: queued.queued_cash_ops,
          queued_audit: queued.queued_audit,
        },
      }).catch(() => {});
    })();
  } else {
    // online → offline
    const onlineDurationMs = onlineWindowStartedAt > 0 ? now - onlineWindowStartedAt : 0;
    offlineWindowStartedAt = now;
    previousIsOnline = false;

    void recordAuditEvent({
      type: 'pos.went_offline',
      aggregateType: 'PosSession',
      aggregateId: deviceId,
      payload: { online_duration_ms: onlineDurationMs },
    }).catch(() => {});
  }
}

/**
 * Start the connectivity-transition subscriber. Idempotent: a second call while
 * already subscribed is a no-op (guards against React StrictMode double-invoke /
 * accidental double-wiring). Seeds `previousIsOnline` + the current window start
 * from the store's present state so the first genuine flip is the first emit.
 * Returns a stop function that unsubscribes and clears the singleton.
 */
export function startConnectivityAuditSubscriber(): () => void {
  if (unsubscribe) return stopConnectivityAuditSubscriber;

  const now = Date.now();
  previousIsOnline = useConnectivityStore.getState().isOnline;
  if (previousIsOnline) {
    onlineWindowStartedAt = now;
    offlineWindowStartedAt = 0;
  } else {
    offlineWindowStartedAt = now;
    onlineWindowStartedAt = 0;
  }

  unsubscribe = useConnectivityStore.subscribe((state) => {
    handleTransition(state.isOnline);
  });

  return stopConnectivityAuditSubscriber;
}

/** Stop the subscriber and reset the singleton so a later `start()` re-arms. */
export function stopConnectivityAuditSubscriber(): void {
  if (unsubscribe) {
    unsubscribe();
    unsubscribe = null;
  }
}

/** Test-only: reset module singleton state between cases. */
export function __resetConnectivityAuditSubscriberForTests(): void {
  stopConnectivityAuditSubscriber();
  previousIsOnline = false;
  onlineWindowStartedAt = 0;
  offlineWindowStartedAt = 0;
}
