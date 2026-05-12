/**
 * T1.2 Step 2.4 — pre-warm payment config at terminal activation.
 *
 * BEFORE: seedOfflineHashChain seeded SQLite state and started the sync
 * scheduler but did NOT eagerly fetch payment config. The first
 * fetchPaymentConfig() call happened lazily from HomePage's useEffect
 * once the cashier reached the home screen — leaving a small window
 * where Step 2.3's gate (the only thing that prevents broken-checkout)
 * had to engage.
 *
 * AFTER: seedOfflineHashChain fires `void usePaymentStore.getState()
 * .fetchPaymentConfig()` AFTER scheduler.start, fire-and-forget so the
 * activation path doesn't block on payment config loading. Errors in
 * the pre-warm fetch must NOT propagate into seedOfflineHashChain's
 * catch (which logs differently) — they're swallowed by the
 * fire-and-forget `.catch` with serializeErrorForLog.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

const startSpy = vi.fn();
const fetchPaymentConfigSpy = vi.fn().mockResolvedValue(undefined);

// T1.3 Step 4.1 + 4.2: startup hydration spies — invoked from
// seedOfflineHashChain after scheduler.start.
const setPendingCountSpy = vi.fn();
const setLastSyncAtSpy = vi.fn();
const getPendingReceiptCountSpy = vi.fn().mockResolvedValue(0);
const getSyncMetadataSpy = vi.fn().mockResolvedValue(null);

// T2.1 Step D: stranded-syncing recovery spy — invoked BEFORE
// scheduler.start to demote any rows left at `'syncing'` after a
// SIGKILL / power-cut between updateReceiptStatus and the response
// handler.
const recoverStrandedSyncingReceiptsSpy = vi.fn().mockResolvedValue(0);

// Cash-drawer ops counterpart of Step D: same crash-stranding pattern
// at `syncService.pushCashDrawerOps` advancing rows to `'syncing'`
// before the HTTP call.
const recoverStrandedSyncingCashDrawerOpsSpy = vi.fn().mockResolvedValue(0);

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/sync/syncService', () => ({
  pullTerminalState: vi.fn().mockResolvedValue(undefined),
  pullZChainState: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/sync/syncScheduler', () => ({
  SyncScheduler: vi.fn().mockImplementation(() => ({
    start: startSpy,
  })),
}));

// Mutable mock state so tests can assert race-guard behaviour by
// configuring an in-memory lastSyncAt value.
const syncStoreMockState: { lastSyncAt: number | null } = { lastSyncAt: null };
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({
      setScheduler: vi.fn(),
      setPendingCount: setPendingCountSpy,
      setLastSyncAt: setLastSyncAtSpy,
      lastSyncAt: syncStoreMockState.lastSyncAt,
    }),
    setState: (partial: { lastSyncAt?: number | null }) => {
      if (Object.prototype.hasOwnProperty.call(partial, 'lastSyncAt')) {
        syncStoreMockState.lastSyncAt = partial.lastSyncAt ?? null;
      }
    },
  },
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptCount: getPendingReceiptCountSpy,
  recoverStrandedSyncingReceipts: recoverStrandedSyncingReceiptsSpy,
}));

vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  recoverStrandedSyncingCashDrawerOps: recoverStrandedSyncingCashDrawerOpsSpy,
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: getSyncMetadataSpy,
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/images/imageCache', () => ({
  initImageCache: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: {
    getState: () => ({ fetchPaymentConfig: fetchPaymentConfigSpy }),
  },
}));

import { seedOfflineHashChain } from '../terminalStore';
import { useAuthStore } from '@/stores/authStore';

describe('T1.2 Step 2.4 — seedOfflineHashChain pre-warms payment config', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    startSpy.mockClear();
    fetchPaymentConfigSpy.mockClear();
    fetchPaymentConfigSpy.mockResolvedValue(undefined);
    setPendingCountSpy.mockClear();
    setLastSyncAtSpy.mockClear();
    getPendingReceiptCountSpy.mockClear();
    getPendingReceiptCountSpy.mockResolvedValue(0);
    getSyncMetadataSpy.mockClear();
    getSyncMetadataSpy.mockResolvedValue(null);
    syncStoreMockState.lastSyncAt = null;
    recoverStrandedSyncingReceiptsSpy.mockClear();
    recoverStrandedSyncingReceiptsSpy.mockResolvedValue(0);
    recoverStrandedSyncingCashDrawerOpsSpy.mockClear();
    recoverStrandedSyncingCashDrawerOpsSpy.mockResolvedValue(0);

    useAuthStore.setState({ companyId: 'company-1' } as never);
  });

  it('T1.2: seedOfflineHashChain fires fetchPaymentConfig AFTER scheduler.start', async () => {
    await seedOfflineHashChain('term-1');
    // Allow the fire-and-forget pre-warm to be scheduled.
    await Promise.resolve();
    await Promise.resolve();

    expect(startSpy).toHaveBeenCalledTimes(1);
    expect(fetchPaymentConfigSpy).toHaveBeenCalledTimes(1);

    const startOrder = startSpy.mock.invocationCallOrder[0]!;
    const fetchOrder = fetchPaymentConfigSpy.mock.invocationCallOrder[0]!;
    expect(fetchOrder).toBeGreaterThan(startOrder);
  });

  it('T1.2: pre-warm fetchPaymentConfig rejection does NOT propagate into seedOfflineHashChain', async () => {
    fetchPaymentConfigSpy.mockRejectedValueOnce(new Error('config fetch failed'));
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    // The seed must resolve cleanly even though the fire-and-forget
    // fetchPaymentConfig rejects.
    await expect(seedOfflineHashChain('term-1')).resolves.toBeUndefined();

    // Allow the rejection to propagate to the .catch handler.
    await Promise.resolve();
    await Promise.resolve();

    // The error must be swallowed by the fire-and-forget .catch with the
    // T0.1 serializeErrorForLog payload — never bubble into the outer
    // seedOfflineHashChain catch (which has a different log prefix).
    expect(consoleError).toHaveBeenCalled();
    const calls = consoleError.mock.calls;
    const preWarmErrorLog = calls.find(
      (call) =>
        typeof call[0] === 'string' &&
        call[0].includes('preWarm') &&
        call[0].includes('paymentConfig'),
    );
    expect(preWarmErrorLog).toBeDefined();

    // The structured payload uses serializeErrorForLog's typed fields.
    if (preWarmErrorLog) {
      const payload = preWarmErrorLog[1] as Record<string, unknown>;
      expect(payload).toHaveProperty('errorName');
      expect(payload).toHaveProperty('message');
    }

    consoleError.mockRestore();
  });

  // T1.3 Step 4.1: startup hydration of pendingReceiptCount so the
  // header badge is truthful from boot, before the first scheduler
  // tick fires.
  it('T1.3: seedOfflineHashChain hydrates pendingReceiptCount from SQLite on startup', async () => {
    getPendingReceiptCountSpy.mockResolvedValueOnce(5);

    await seedOfflineHashChain('term-1');
    // Allow the awaited hydrations to settle.
    await Promise.resolve();
    await Promise.resolve();

    expect(getPendingReceiptCountSpy).toHaveBeenCalledTimes(1);
    expect(setPendingCountSpy).toHaveBeenCalledWith(5);
  });

  // T1.3 Step 4.2: startup hydration of lastSyncAt so the SyncButton's
  // "X minutes ago" affordance survives app restarts.
  it('T1.3: seedOfflineHashChain hydrates lastSyncAt from sync_metadata on startup', async () => {
    getSyncMetadataSpy.mockResolvedValueOnce('1717891200000');

    await seedOfflineHashChain('term-1');
    await Promise.resolve();
    await Promise.resolve();

    expect(getSyncMetadataSpy).toHaveBeenCalledWith(expect.anything(), 'last_sync_at');
    expect(setLastSyncAtSpy).toHaveBeenCalledWith(1717891200000);
  });

  it('T1.3: lastSyncAt hydration handles null sync_metadata value gracefully', async () => {
    getSyncMetadataSpy.mockResolvedValueOnce(null);

    await seedOfflineHashChain('term-1');
    await Promise.resolve();
    await Promise.resolve();

    // No metadata → don't write anything; leave lastSyncAt at its
    // initial-state null. setLastSyncAt MUST NOT be called with null
    // when there's nothing to hydrate (otherwise we'd clobber a
    // value the scheduler may have written between boot and the
    // hydration await).
    expect(setLastSyncAtSpy).not.toHaveBeenCalled();
  });

  it('T1.3: lastSyncAt hydration rejects corrupt non-numeric values', async () => {
    getSyncMetadataSpy.mockResolvedValueOnce('not-a-number');

    await seedOfflineHashChain('term-1');
    await Promise.resolve();
    await Promise.resolve();

    expect(setLastSyncAtSpy).not.toHaveBeenCalled();
  });

  // T1.3 Codex round-1 finding 2: hydration race guard. seedOfflineHashChain
  // awaits getSyncMetadata AFTER scheduler.start fires the first tick.
  // If the tick wrote a FRESHER Date.now() before this await resolves,
  // an unguarded hydration would clobber the fresh in-memory value with
  // the older persisted one. Pre-fix: the hydration always wrote.
  // Post-fix: in-memory wins when its value is newer than the persisted
  // value; hydration only fires on a null in-memory state OR when the
  // persisted value is strictly newer.
  it('T1.3: lastSyncAt hydration does NOT clobber a fresher in-memory value (race guard)', async () => {
    // Simulate the race: scheduler.start() fired the first tick, which
    // wrote a fresh Date.now() to in-memory state, BEFORE the
    // hydration await resolved with an older persisted timestamp.
    syncStoreMockState.lastSyncAt = 1717891300000; // fresher
    getSyncMetadataSpy.mockResolvedValueOnce('1717891200000'); // older

    await seedOfflineHashChain('term-1');
    await Promise.resolve();
    await Promise.resolve();

    // Hydration must skip the write — in-memory wins on conflict.
    expect(setLastSyncAtSpy).not.toHaveBeenCalled();
  });

  it('T1.3: lastSyncAt hydration writes when persisted value is strictly newer than in-memory', async () => {
    syncStoreMockState.lastSyncAt = 1717891200000; // older
    getSyncMetadataSpy.mockResolvedValueOnce('1717891300000'); // newer

    await seedOfflineHashChain('term-1');
    await Promise.resolve();
    await Promise.resolve();

    expect(setLastSyncAtSpy).toHaveBeenCalledWith(1717891300000);
  });

  // ---------------------------------------------------------------------
  // T2.1 Step D — boot-time stranded-syncing receipt recovery.
  //
  // The bug: syncService.updateReceiptStatus(id, 'syncing') runs BEFORE
  // the sync HTTP call. SIGKILL/power-cut between the status update and
  // the response handler leaves the row at 'syncing' permanently.
  // getPendingReceiptsForSync filters WHERE status IN ('pending','failed')
  // → orphan invisible to every retry → silent fiscal-record loss.
  //
  // The fix: seedOfflineHashChain runs an UPDATE …'pending' WHERE
  // status='syncing' on boot, BEFORE scheduler.start so the first tick
  // sees the demoted rows in getPendingReceiptsForSync.
  // ---------------------------------------------------------------------

  it('T2.1 D.1: seedOfflineHashChain calls recoverStrandedSyncingReceipts on boot', async () => {
    recoverStrandedSyncingReceiptsSpy.mockResolvedValueOnce(2);

    await seedOfflineHashChain('term-1');

    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledTimes(1);
    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledWith(expect.anything());
  });

  it('T2.1 D.2: stranded-syncing recovery runs BEFORE scheduler.start()', async () => {
    recoverStrandedSyncingReceiptsSpy.mockResolvedValueOnce(1);

    await seedOfflineHashChain('term-1');

    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledTimes(1);
    expect(startSpy).toHaveBeenCalledTimes(1);

    const recoverOrder = recoverStrandedSyncingReceiptsSpy.mock.invocationCallOrder[0]!;
    const startOrder = startSpy.mock.invocationCallOrder[0]!;
    expect(recoverOrder).toBeLessThan(startOrder);
  });

  it('T2.1 D.3: recovery is idempotent across multiple boots', async () => {
    // First boot: 1 stranded row.
    recoverStrandedSyncingReceiptsSpy.mockResolvedValueOnce(1);
    await seedOfflineHashChain('term-1');
    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledTimes(1);

    // Second boot: 0 stranded rows (the first boot demoted them).
    recoverStrandedSyncingReceiptsSpy.mockResolvedValueOnce(0);
    await seedOfflineHashChain('term-1');
    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledTimes(2);

    // Both calls succeeded (no thrown errors); seedOfflineHashChain
    // resolves cleanly across both boots.
  });

  it('T2.1 D.4: recovery error is caught + serialized via serializeErrorForLog (does NOT propagate)', async () => {
    recoverStrandedSyncingReceiptsSpy.mockRejectedValueOnce(
      new Error('SQLite UPDATE failed'),
    );
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    // seedOfflineHashChain MUST resolve cleanly even though the recovery
    // UPDATE rejected.
    await expect(seedOfflineHashChain('term-1')).resolves.toBeUndefined();

    // The error was logged via serializeErrorForLog's payload shape.
    const recoverErrorLog = consoleError.mock.calls.find(
      (call) =>
        typeof call[0] === 'string' &&
        call[0].toLowerCase().includes('strandedsyncing'),
    );
    expect(recoverErrorLog).toBeDefined();
    if (recoverErrorLog) {
      const payload = recoverErrorLog[1] as Record<string, unknown>;
      expect(payload).toHaveProperty('errorName');
      expect(payload).toHaveProperty('message');
    }

    // The scheduler still starts despite the recovery failure — a
    // SQLite-level bad state shouldn't strand the cashier with a
    // dead scheduler.
    expect(startSpy).toHaveBeenCalledTimes(1);

    consoleError.mockRestore();
  });

  // ---------------------------------------------------------------------
  // Cash-drawer Step-D counterpart — same crash-stranding pattern at
  // `syncService.pushCashDrawerOps:506`. updateCashDrawerOpStatus(... 'syncing')
  // runs BEFORE the sync HTTP call. SIGKILL/power-cut between the status
  // update and the response handler leaves the row at 'syncing'
  // permanently. getPendingCashDrawerOps filters
  //   WHERE status IN ('pending','failed') AND retry_count < 5
  // → orphan invisible to every retry → silent loss of a deposit / payout
  // the cashier counted on for till reconciliation.
  //
  // Recovery shape mirrors Step D exactly: an UPDATE …'pending' WHERE
  // status='syncing' on boot, BEFORE scheduler.start so the first tick
  // sees the demoted rows.
  // ---------------------------------------------------------------------

  it('cash-drawer E.1: seedOfflineHashChain calls recoverStrandedSyncingCashDrawerOps on boot', async () => {
    recoverStrandedSyncingCashDrawerOpsSpy.mockResolvedValueOnce(2);

    await seedOfflineHashChain('term-1');

    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledTimes(1);
    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledWith(expect.anything());
  });

  it('cash-drawer E.2: stranded-syncing recovery runs BEFORE scheduler.start()', async () => {
    recoverStrandedSyncingCashDrawerOpsSpy.mockResolvedValueOnce(1);

    await seedOfflineHashChain('term-1');

    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledTimes(1);
    expect(startSpy).toHaveBeenCalledTimes(1);

    const recoverOrder = recoverStrandedSyncingCashDrawerOpsSpy.mock.invocationCallOrder[0]!;
    const startOrder = startSpy.mock.invocationCallOrder[0]!;
    expect(recoverOrder).toBeLessThan(startOrder);
  });

  it('cash-drawer E.3: recovery is idempotent across multiple boots', async () => {
    recoverStrandedSyncingCashDrawerOpsSpy.mockResolvedValueOnce(1);
    await seedOfflineHashChain('term-1');
    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledTimes(1);

    recoverStrandedSyncingCashDrawerOpsSpy.mockResolvedValueOnce(0);
    await seedOfflineHashChain('term-1');
    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledTimes(2);
  });

  it('cash-drawer E.4: recovery error is caught + serialized via serializeErrorForLog (does NOT propagate)', async () => {
    recoverStrandedSyncingCashDrawerOpsSpy.mockRejectedValueOnce(
      new Error('SQLite UPDATE failed'),
    );
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    await expect(seedOfflineHashChain('term-1')).resolves.toBeUndefined();

    const recoverErrorLog = consoleError.mock.calls.find(
      (call) =>
        typeof call[0] === 'string' &&
        call[0].toLowerCase().includes('strandedsyncingcashdrawer'),
    );
    expect(recoverErrorLog).toBeDefined();
    if (recoverErrorLog) {
      const payload = recoverErrorLog[1] as Record<string, unknown>;
      expect(payload).toHaveProperty('errorName');
      expect(payload).toHaveProperty('message');
    }

    expect(startSpy).toHaveBeenCalledTimes(1);

    consoleError.mockRestore();
  });

  it('cash-drawer E.5: cash-drawer recovery error does NOT prevent the receipts recovery from also running', async () => {
    // Independence guarantee: a SQLite blip in one recovery path must not
    // strand the other. Both should be invoked and swallowed
    // independently.
    recoverStrandedSyncingCashDrawerOpsSpy.mockRejectedValueOnce(
      new Error('cash drawer SQLite blip'),
    );
    recoverStrandedSyncingReceiptsSpy.mockResolvedValueOnce(1);
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    await expect(seedOfflineHashChain('term-1')).resolves.toBeUndefined();

    expect(recoverStrandedSyncingCashDrawerOpsSpy).toHaveBeenCalledTimes(1);
    expect(recoverStrandedSyncingReceiptsSpy).toHaveBeenCalledTimes(1);
    expect(startSpy).toHaveBeenCalledTimes(1);

    consoleError.mockRestore();
  });
});
