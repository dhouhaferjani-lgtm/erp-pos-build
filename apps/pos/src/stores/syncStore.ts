import { create } from 'zustand';
import type { SyncResult } from '@/lib/sync/syncService';
import type { SyncScheduler } from '@/lib/sync/syncScheduler';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';
import { useTerminalStore } from '@/stores/terminalStore';

/**
 * Resolve the active terminal id for the FiscalChain aggregate on
 * `pos.fiscal_chain_break`. Falls back to `'unknown'` so the event still
 * records when the terminal store is not yet hydrated.
 */
function resolveTerminalId(): string {
  return useTerminalStore.getState().terminal?.id ?? 'unknown';
}

interface SyncState {
  isSyncing: boolean;
  lastSyncAt: number | null;
  lastSyncResult: SyncResult | null;
  pendingReceiptCount: number;
  lastError: string | null;
  scheduler: SyncScheduler | null;
  chainBreak: boolean;
  chainBreakReceiptNumber: string | null;
  chainBreakAcknowledgedAt: string | null;
}

interface SyncActions {
  startSync: () => void;
  completeSync: (result: SyncResult) => void;
  failSync: (error: string) => void;
  setPendingCount: (count: number) => void;
  incrementPendingCount: () => void;
  setLastSyncAt: (timestamp: number | null) => void;
  setScheduler: (scheduler: SyncScheduler | null) => void;
  triggerSync: () => void;
  reset: () => void;
  setChainBreak: (broken: boolean, receiptNumber: string | null) => void;
  acknowledgeChainBreak: () => void;
}

type SyncStore = SyncState & SyncActions;

const initialState: SyncState = {
  isSyncing: false,
  lastSyncAt: null,
  lastSyncResult: null,
  pendingReceiptCount: 0,
  lastError: null,
  scheduler: null,
  chainBreak: false,
  chainBreakReceiptNumber: null,
  chainBreakAcknowledgedAt: null,
};

export const useSyncStore = create<SyncStore>()((set, get) => ({
  ...initialState,

  startSync: () => {
    set({ isSyncing: true, lastError: null });
  },

  completeSync: (result: SyncResult) => {
    // T1.3 Step 4.1: pendingReceiptCount no longer derives from
    // result.receiptsFailed (which only counts failures from THIS
    // tick, drifting from SQLite truth across ticks). The scheduler
    // calls setPendingCount(getPendingReceiptCount(db)) immediately
    // after this completeSync to refresh the badge from source-of-
    // truth. Leaving pendingReceiptCount untouched here means the
    // scheduler is the single writer.
    set({
      isSyncing: false,
      lastSyncAt: Date.now(),
      lastSyncResult: result,
      lastError: result.errors.length > 0 ? result.errors[0] : null,
    });
  },

  failSync: (error: string) => {
    set({
      isSyncing: false,
      lastError: error,
    });

    // Sub-Spec C Task 12 — `pos.sync_failed_orphaned`. `failSync` is an
    // AGGREGATE failure signal (one error message per tick); it carries no
    // per-receipt detail, so we emit ONCE with `receipt_id: null` and the
    // available summary rather than inventing per-receipt state. Fire-and-
    // forget OUTSIDE the `set()`; never throws into the action.
    void recordAuditEvent({
      type: 'pos.sync_failed_orphaned',
      aggregateType: 'OfflineReceipt',
      aggregateId: resolveTerminalId(),
      payload: {
        receipt_id: null,
        idempotency_key: null,
        retry_count: null,
        error,
      },
    }).catch(() => {});
  },

  setPendingCount: (count: number) => {
    set({ pendingReceiptCount: count });
  },

  incrementPendingCount: () => {
    set((state) => ({ pendingReceiptCount: state.pendingReceiptCount + 1 }));
  },

  // T1.3 Step 4.2: explicit hydration entry point for lastSyncAt.
  // The scheduler's completeSync still writes Date.now() in-memory;
  // this action is for boot-time hydration from sync_metadata so the
  // SyncButton's "X minutes ago" affordance survives app restarts.
  setLastSyncAt: (timestamp: number | null) => {
    set({ lastSyncAt: timestamp });
  },

  setScheduler: (scheduler: SyncScheduler | null) => {
    set({ scheduler });
  },

  triggerSync: () => {
    const { scheduler, isSyncing } = get();
    if (!scheduler || isSyncing) return;
    // Don't set isSyncing here — tick() handles it via startSync().
    // Setting it here would cause tick() to bail out at its own isSyncing guard.
    scheduler.syncNow().catch(() => {
      // syncNow errors are handled inside tick(), this is just for safety
    });
  },

  reset: () => {
    set(initialState);
  },

  setChainBreak: (broken, receiptNumber) => {
    set({
      chainBreak: broken,
      chainBreakReceiptNumber: receiptNumber,
      chainBreakAcknowledgedAt: broken ? null : new Date().toISOString(),
    });

    // Sub-Spec C Task 12 — `pos.fiscal_chain_break`. Only emit when a break is
    // being SET (broken === true); clearing it (broken === false, the resolution
    // path) is not a fraud signal. Aggregate FiscalChain / terminal id. Fire-and-
    // forget OUTSIDE the `set()`; never throws into the action.
    if (broken) {
      void recordAuditEvent({
        type: 'pos.fiscal_chain_break',
        aggregateType: 'FiscalChain',
        aggregateId: resolveTerminalId(),
        payload: {
          receipt_number: receiptNumber,
          acknowledged: false,
        },
      }).catch(() => {});
    }
  },

  acknowledgeChainBreak: () => {
    set({ chainBreakAcknowledgedAt: new Date().toISOString() });

    // Sub-Spec C Task 12 — operator acknowledged an active chain break. Emit the
    // same `pos.fiscal_chain_break` type with `acknowledged: true` so the
    // detection center can correlate the break with its acknowledgement.
    const receiptNumber = get().chainBreakReceiptNumber;
    void recordAuditEvent({
      type: 'pos.fiscal_chain_break',
      aggregateType: 'FiscalChain',
      aggregateId: resolveTerminalId(),
      payload: {
        receipt_number: receiptNumber,
        acknowledged: true,
      },
    }).catch(() => {});
  },
}));
