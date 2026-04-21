import { create } from 'zustand';
import type { SyncResult } from '@/lib/sync/syncService';
import type { SyncScheduler } from '@/lib/sync/syncScheduler';

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
    set({
      isSyncing: false,
      lastSyncAt: Date.now(),
      lastSyncResult: result,
      lastError: result.errors.length > 0 ? result.errors[0] : null,
      pendingReceiptCount: result.receiptsFailed,
    });
  },

  failSync: (error: string) => {
    set({
      isSyncing: false,
      lastError: error,
    });
  },

  setPendingCount: (count: number) => {
    set({ pendingReceiptCount: count });
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

  setChainBreak: (broken, receiptNumber) => set({
    chainBreak: broken,
    chainBreakReceiptNumber: receiptNumber,
    chainBreakAcknowledgedAt: broken ? null : new Date().toISOString(),
  }),

  acknowledgeChainBreak: () => set({ chainBreakAcknowledgedAt: new Date().toISOString() }),
}));
