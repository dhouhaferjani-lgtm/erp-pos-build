import { describe, it, expect, beforeEach, vi } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

vi.mock('@/lib/sync/syncService', () => ({
  runFullSync: vi.fn(),
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn().mockReturnValue({ isOnline: true }),
    subscribe: vi.fn(() => () => {}),
  },
}));

// vi.mock factories are hoisted to the top of the file by vitest, so
// any spy referenced inside them must also be hoisted via vi.hoisted().
const {
  setPendingCountSpy,
  getPendingReceiptCountSpy,
  setSyncMetadataSpy,
  syncStoreState,
} = vi.hoisted(() => {
  // Stateful mock — completeSync writes lastSyncAt the way the real
  // store action does, so the scheduler's post-completeSync read picks
  // up a real timestamp.
  const state: {
    isSyncing: boolean;
    lastSyncAt: number | null;
    startSync: () => void;
    completeSync: (result: unknown) => void;
    failSync: (err: string) => void;
    setChainBreak: (broken: boolean, n: string | null) => void;
    setPendingCount: (n: number) => void;
    setLastSyncAt: (n: number | null) => void;
  } = {
    isSyncing: false,
    lastSyncAt: null,
    startSync: vi.fn(),
    completeSync: vi.fn((_result: unknown) => {
      state.lastSyncAt = Date.now();
    }),
    failSync: vi.fn(),
    setChainBreak: vi.fn(),
    setPendingCount: vi.fn(),
    setLastSyncAt: vi.fn(),
  };
  return {
    syncStoreState: state,
    setPendingCountSpy: state.setPendingCount,
    getPendingReceiptCountSpy: vi.fn<(db: unknown) => Promise<number>>(),
    setSyncMetadataSpy: vi.fn<(db: unknown, key: string, value: string) => Promise<void>>(),
  };
});

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn(() => syncStoreState),
  },
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptCount: getPendingReceiptCountSpy,
  getLastSyncedReceiptNumber: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  setSyncMetadata: setSyncMetadataSpy,
  getSyncMetadata: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      checkSession: vi.fn().mockResolvedValue(undefined),
      logout: vi.fn(),
    }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn().mockReturnValue({
      refreshHashChainReady: vi.fn().mockResolvedValue(undefined),
      refreshTerminalRecord: vi.fn().mockResolvedValue(undefined),
    }),
  },
}));

const productRefreshSpy = vi.fn().mockResolvedValue(undefined);
const paymentRefreshSpy = vi.fn().mockResolvedValue(undefined);

vi.mock('@/stores/productStore', () => ({
  useProductStore: {
    getState: vi.fn(() => ({ refreshFromSQLite: productRefreshSpy })),
  },
}));

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: {
    getState: vi.fn(() => ({ refreshFromSQLite: paymentRefreshSpy })),
  },
}));

import { SyncScheduler } from '../syncScheduler';
import { runFullSync, type SyncResult } from '@/lib/sync/syncService';

function makeMockDb(): Database {
  return {} as Database;
}

function makeSyncResult(overrides: Partial<SyncResult> = {}): SyncResult {
  return {
    receiptsPushed: 0,
    receiptsFailed: 0,
    zReportsPushed: 0,
    zReportsFailed: 0,
    cashDrawerOpsPushed: 0,
    pinUpdatesPushed: 0,
    voucherLedgerPushed: 0,
    voucherLedgerFailed: 0,
    productsPulled: 0,
    paymentConfigPulled: false,
    operatorsPulled: 0,
    terminalStatePulled: false,
    tablesPulled: false,
    activeMenuPulled: false,
    vouchersPulled: 0,
    voucherLedgerPulled: 0,
    receiptQrIndexPulled: 0,
    customersPulled: 0,
    chainBreak: false,
    errors: [],
    degraded: false,
    ...overrides,
  };
}

describe('SyncScheduler', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productRefreshSpy.mockClear();
    paymentRefreshSpy.mockClear();
    syncStoreState.lastSyncAt = null;
    getPendingReceiptCountSpy.mockReset();
    getPendingReceiptCountSpy.mockResolvedValue(0);
    setSyncMetadataSpy.mockReset();
    setSyncMetadataSpy.mockResolvedValue(undefined);
  });

  describe('tick', () => {
    it('T0.5: rehydrates BOTH productStore AND paymentStore from SQLite after runFullSync resolves', async () => {
      // Pre-T0.5, the scheduler tick rehydrated only `productStore` from
      // SQLite — leaving `paymentStore` to drift stale relative to whatever
      // the platform side committed since the cashier's last login. Combined
      // with the silently-broken `pullPaymentConfig` (which 404'd against
      // `/treasury/payment-methods`), the cashier could end up working
      // against a payment-method config that hadn't refreshed in hours.
      // T0.5 wires `paymentStore.refreshFromSQLite()` into the same tick so
      // both stores' in-memory state tracks the SQLite cache.
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult());

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');
      await scheduler.syncNow();

      // Both spies invoked exactly once per tick — no double-fire, no skip.
      expect(productRefreshSpy).toHaveBeenCalledTimes(1);
      expect(paymentRefreshSpy).toHaveBeenCalledTimes(1);
    });

    it('T0.5: paymentStore refresh failure does not block productStore refresh', async () => {
      // Defense-in-depth: the two stores' refresh paths are independent.
      // A SQLite read failure on one (e.g. transient lock contention) must
      // not starve the other of its rehydrate. The scheduler hook fires both
      // refresh calls and isolates their failure modes via `.catch`.
      // (The symmetric direction — productStore failure not blocking
      // paymentStore — is implicitly covered by the fire-and-forget call
      // ordering in syncScheduler.ts: paymentStore.refreshFromSQLite is
      // dispatched on a separate microtask AFTER productStore's, so a
      // synchronous-throw from productStore's getState() would only break
      // setup, not paymentStore's dispatch. Codex round-1 MINOR noted the
      // original title overclaimed symmetry; this test covers the realistic
      // failure mode only.)
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult());
      paymentRefreshSpy.mockRejectedValueOnce(new Error('SQLite locked'));

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');

      // syncNow must NOT reject even though paymentRefresh rejected — the
      // tick is supposed to swallow refresh failures and continue.
      await expect(scheduler.syncNow()).resolves.not.toThrow();

      expect(productRefreshSpy).toHaveBeenCalledTimes(1);
      expect(paymentRefreshSpy).toHaveBeenCalledTimes(1);
    });

    // T1.3 Step 4.1: scheduler reads SQLite pending-receipt count after
    // every tick and writes it to the store via setPendingCount. The
    // result.receiptsFailed value is the wrong source — it only reflects
    // failures from the just-completed tick, not the running total.
    it('T1.3: scheduler tick calls setPendingCount with the SQLite-sourced count after completeSync', async () => {
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult({ receiptsFailed: 0 }));
      // SQLite has 7 pending or failed receipts that pre-date this tick.
      getPendingReceiptCountSpy.mockResolvedValueOnce(7);

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');
      await scheduler.syncNow();

      expect(getPendingReceiptCountSpy).toHaveBeenCalledTimes(1);
      expect(setPendingCountSpy).toHaveBeenCalledTimes(1);
      expect(setPendingCountSpy).toHaveBeenCalledWith(7);
    });

    it('T1.3: scheduler swallows getPendingReceiptCount failures without crashing the tick', async () => {
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult());
      getPendingReceiptCountSpy.mockRejectedValueOnce(new Error('SQLite locked'));

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');
      await expect(scheduler.syncNow()).resolves.not.toThrow();

      // Failure means setPendingCount stays at its prior value (the
      // catch block doesn't write a stale 0).
      expect(setPendingCountSpy).not.toHaveBeenCalled();
    });

    // T1.3 Step 4.2: scheduler persists lastSyncAt to sync_metadata
    // after every tick so the timestamp survives app restarts.
    it('T1.3: scheduler tick calls setSyncMetadata with last_sync_at after completeSync', async () => {
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult());

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');
      await scheduler.syncNow();

      expect(setSyncMetadataSpy).toHaveBeenCalledTimes(1);
      const [, key, value] = setSyncMetadataSpy.mock.calls[0]!;
      expect(key).toBe('last_sync_at');
      expect(typeof value).toBe('string');
      const parsed = Number(value);
      expect(Number.isFinite(parsed)).toBe(true);
      expect(parsed).toBeGreaterThan(0);
    });

    it('T1.3: scheduler swallows setSyncMetadata failures without crashing the tick', async () => {
      vi.mocked(runFullSync).mockResolvedValue(makeSyncResult());
      setSyncMetadataSpy.mockRejectedValueOnce(new Error('SQLite locked'));

      const scheduler = new SyncScheduler(makeMockDb(), 'terminal-1');
      await expect(scheduler.syncNow()).resolves.not.toThrow();
    });
  });
});
