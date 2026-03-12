import { create } from 'zustand';
import type { SyncResult } from '@/lib/sync/syncService';

interface SyncState {
  isSyncing: boolean;
  lastSyncAt: number | null;
  lastSyncResult: SyncResult | null;
  pendingReceiptCount: number;
  lastError: string | null;
}

interface SyncActions {
  startSync: () => void;
  completeSync: (result: SyncResult) => void;
  failSync: (error: string) => void;
  setPendingCount: (count: number) => void;
  reset: () => void;
}

type SyncStore = SyncState & SyncActions;

const initialState: SyncState = {
  isSyncing: false,
  lastSyncAt: null,
  lastSyncResult: null,
  pendingReceiptCount: 0,
  lastError: null,
};

export const useSyncStore = create<SyncStore>()((set) => ({
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

  reset: () => {
    set(initialState);
  },
}));
