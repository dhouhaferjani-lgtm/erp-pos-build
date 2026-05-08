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

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({
      setScheduler: vi.fn(),
      setPendingCount: setPendingCountSpy,
      setLastSyncAt: setLastSyncAtSpy,
    }),
  },
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptCount: getPendingReceiptCountSpy,
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
});
