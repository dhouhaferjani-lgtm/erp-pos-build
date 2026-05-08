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

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({
      isSyncing: false,
      startSync: vi.fn(),
      completeSync: vi.fn(),
      failSync: vi.fn(),
      setChainBreak: vi.fn(),
    }),
  },
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
    chainBreak: false,
    errors: [],
    ...overrides,
  };
}

describe('SyncScheduler', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productRefreshSpy.mockClear();
    paymentRefreshSpy.mockClear();
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
  });
});
