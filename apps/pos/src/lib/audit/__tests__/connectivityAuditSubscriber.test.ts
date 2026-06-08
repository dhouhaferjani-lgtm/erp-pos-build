import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// ── Mocks ────────────────────────────────────────────────────────────────
const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('../recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: () => 'device-xyz',
}));

const getDatabase = vi.fn().mockResolvedValue({});
vi.mock('@/lib/db', () => ({
  getDatabase: (...args: unknown[]) => getDatabase(...args),
}));

const getPendingReceiptCount = vi.fn().mockResolvedValue(0);
vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  getPendingReceiptCount: (...args: unknown[]) => getPendingReceiptCount(...args),
}));

const getPendingCashDrawerOps = vi.fn().mockResolvedValue([]);
vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  getPendingCashDrawerOps: (...args: unknown[]) => getPendingCashDrawerOps(...args),
}));

const countPendingAuditEvents = vi.fn().mockResolvedValue(0);
vi.mock('@/lib/db/repositories/queuedAuditEventRepository', () => ({
  countPendingAuditEvents: (...args: unknown[]) => countPendingAuditEvents(...args),
}));

const authState: { companyId: string | null } = { companyId: 'company-1' };
vi.mock('@/stores/authStore', () => ({
  useAuthStore: { getState: () => authState },
}));

import { useConnectivityStore } from '@/stores/connectivityStore';
import {
  startConnectivityAuditSubscriber,
  __resetConnectivityAuditSubscriberForTests,
  BOOT_GRACE_MS,
} from '../connectivityAuditSubscriber';

function setOnline(value: boolean): void {
  useConnectivityStore.setState({ isOnline: value });
}

interface AuditArg {
  type: string;
  aggregateType: string;
  aggregateId: string;
  payload: Record<string, unknown>;
}

function firstArg(type: string): AuditArg {
  const call = recordAuditEvent.mock.calls
    .map((c) => c[0] as AuditArg)
    .find((a) => a.type === type);
  if (!call) throw new Error(`no audit call recorded for ${type}`);
  return call;
}

/** Flush the async went_online emit (getDatabase + 3 count reads) before asserting. */
async function flush(): Promise<void> {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve();
  }
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('connectivityAuditSubscriber', () => {
  let dateNowSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    getDatabase.mockResolvedValue({});
    getPendingReceiptCount.mockResolvedValue(0);
    getPendingCashDrawerOps.mockResolvedValue([]);
    countPendingAuditEvents.mockResolvedValue(0);
    authState.companyId = 'company-1';
    __resetConnectivityAuditSubscriberForTests();
    // Start in a known online state.
    useConnectivityStore.setState({ isOnline: true });
    // Simulate the subscriber being started well in the past so that all
    // transitions in the existing test suite are already past the boot-grace
    // window. We do this by making the first call to Date.now() (inside
    // startConnectivityAuditSubscriber) return a time far in the past, and
    // subsequent calls return a time BOOT_GRACE_MS+1 later.
    const BASE = 1_000_000_000_000;
    let callCount = 0;
    dateNowSpy = vi.spyOn(Date, 'now').mockImplementation(() => {
      callCount += 1;
      // The first call is from start() recording startedAt; return a base value.
      // All subsequent calls (from handleTransition checks) are BOOT_GRACE_MS+1
      // after the base, so Date.now() - startedAt > BOOT_GRACE_MS.
      return callCount === 1 ? BASE : BASE + BOOT_GRACE_MS + 1;
    });
  });

  afterEach(() => {
    dateNowSpy.mockRestore();
    __resetConnectivityAuditSubscriberForTests();
  });

  it('does not emit on the initial subscribe (no transition)', () => {
    startConnectivityAuditSubscriber();
    expect(recordAuditEvent).not.toHaveBeenCalled();
  });

  it('does not emit when a state change does not flip isOnline', () => {
    startConnectivityAuditSubscriber();
    // Trigger a store change that leaves isOnline unchanged.
    useConnectivityStore.setState({ serverReachable: true });
    expect(recordAuditEvent).not.toHaveBeenCalled();
  });

  it('emits pos.went_offline EXACTLY ONCE on online→offline', async () => {
    startConnectivityAuditSubscriber();

    setOnline(false);
    await flush();

    const offlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    expect(offlineCalls).toHaveLength(1);

    const arg = firstArg('pos.went_offline');
    expect(arg.aggregateType).toBe('PosSession');
    expect(arg.aggregateId).toBe('device-xyz');
    expect(typeof arg.payload.online_duration_ms).toBe('number');
    expect(arg.payload.online_duration_ms as number).toBeGreaterThanOrEqual(0);
  });

  it('emits pos.went_online EXACTLY ONCE on offline→online with queued counts from SQLite', async () => {
    getPendingReceiptCount.mockResolvedValue(3);
    getPendingCashDrawerOps.mockResolvedValue([{ id: 'a' }, { id: 'b' }]);
    countPendingAuditEvents.mockResolvedValue(5);

    // Begin offline, then come online.
    useConnectivityStore.setState({ isOnline: false });
    startConnectivityAuditSubscriber();

    setOnline(true);
    await flush();

    const onlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_online',
    );
    expect(onlineCalls).toHaveLength(1);

    const arg = firstArg('pos.went_online');
    expect(arg.aggregateType).toBe('PosSession');
    expect(arg.aggregateId).toBe('device-xyz');
    expect(typeof arg.payload.offline_duration_ms).toBe('number');
    expect(arg.payload.queued_receipts).toBe(3);
    expect(arg.payload.queued_cash_ops).toBe(2);
    expect(arg.payload.queued_audit).toBe(5);
  });

  it('emits each edge once across an offline→online→offline sequence', async () => {
    startConnectivityAuditSubscriber();

    setOnline(false); // edge 1: went_offline
    await flush();
    setOnline(true); // edge 2: went_online
    await flush();
    setOnline(false); // edge 3: went_offline
    await flush();

    const offline = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    const online = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_online',
    );
    expect(offline).toHaveLength(2);
    expect(online).toHaveLength(1);
  });

  it('degrades queued counts to null when the SQLite read fails (still emits)', async () => {
    getPendingReceiptCount.mockRejectedValue(new Error('db locked'));

    useConnectivityStore.setState({ isOnline: false });
    startConnectivityAuditSubscriber();

    setOnline(true);
    await flush();

    const onlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_online',
    );
    expect(onlineCalls).toHaveLength(1);
    const payload = firstArg('pos.went_online').payload;
    expect(payload.queued_receipts).toBeNull();
    expect(payload.queued_cash_ops).toBeNull();
    expect(payload.queued_audit).toBeNull();
  });

  it('degrades counts to null when there is no company context', async () => {
    authState.companyId = null;

    useConnectivityStore.setState({ isOnline: false });
    startConnectivityAuditSubscriber();

    setOnline(true);
    await flush();

    expect(getDatabase).not.toHaveBeenCalled();
    const payload = firstArg('pos.went_online').payload;
    expect(payload.queued_receipts).toBeNull();
    expect(payload.queued_cash_ops).toBeNull();
    expect(payload.queued_audit).toBeNull();
  });

  it('an emit failure never breaks the subscriber (best-effort)', async () => {
    recordAuditEvent.mockRejectedValue(new Error('enqueue failed'));

    expect(() => {
      startConnectivityAuditSubscriber();
      setOnline(false);
    }).not.toThrow();
    await flush();

    // A subsequent edge still fires (subscriber is not wedged).
    setOnline(true);
    await flush();
    expect(recordAuditEvent).toHaveBeenCalled();
  });

  it('double-start is a no-op: a single subscription, one emit per edge', async () => {
    const stop1 = startConnectivityAuditSubscriber();
    const stop2 = startConnectivityAuditSubscriber();

    setOnline(false);
    await flush();

    const offline = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    expect(offline).toHaveLength(1); // not 2 — second start did not add a listener

    // Both returned stop fns reference the same subscription.
    expect(typeof stop1).toBe('function');
    expect(typeof stop2).toBe('function');
  });
});

describe('connectivityAuditSubscriber — boot-grace window (Fix 3)', () => {
  let dateNowSpy: ReturnType<typeof vi.spyOn>;
  // A controlled, mutable "current time" for the boot-grace tests.
  let fakeNow: number;

  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    getDatabase.mockResolvedValue({});
    getPendingReceiptCount.mockResolvedValue(0);
    getPendingCashDrawerOps.mockResolvedValue([]);
    countPendingAuditEvents.mockResolvedValue(0);
    authState.companyId = 'company-1';
    __resetConnectivityAuditSubscriberForTests();
    useConnectivityStore.setState({ isOnline: true });

    // Seed fakeNow at a known base; spy intercepts all Date.now() calls.
    fakeNow = 1_700_000_000_000;
    dateNowSpy = vi.spyOn(Date, 'now').mockImplementation(() => fakeNow);
  });

  afterEach(() => {
    dateNowSpy.mockRestore();
    __resetConnectivityAuditSubscriberForTests();
  });

  it('does NOT emit for a transition within the boot-grace window', async () => {
    startConnectivityAuditSubscriber(); // records startedAt = fakeNow

    // Advance time slightly (still inside grace window).
    fakeNow += BOOT_GRACE_MS - 1;
    setOnline(false);
    await flush();

    const offlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    expect(offlineCalls).toHaveLength(0);
  });

  it('updates previousIsOnline during boot-grace so the first post-grace emit is correct', async () => {
    startConnectivityAuditSubscriber(); // startedAt = fakeNow, previousIsOnline = true

    // Boot-settle flip (suppressed): true → false within grace window.
    fakeNow += BOOT_GRACE_MS - 1;
    setOnline(false); // previousIsOnline updated to false, no emit
    await flush();
    expect(recordAuditEvent).not.toHaveBeenCalled();

    // Advance past the grace window.
    fakeNow += 2; // now fakeNow - startedAt > BOOT_GRACE_MS
    // Next genuine flip (false → true): should emit went_online, NOT went_offline.
    setOnline(true);
    await flush();

    const onlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_online',
    );
    const offlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    expect(onlineCalls).toHaveLength(1);
    expect(offlineCalls).toHaveLength(0);
  });

  it('DOES emit for a transition after the boot-grace window expires', async () => {
    startConnectivityAuditSubscriber(); // startedAt = fakeNow

    // Advance past the grace window.
    fakeNow += BOOT_GRACE_MS + 1;
    setOnline(false);
    await flush();

    const offlineCalls = recordAuditEvent.mock.calls.filter(
      (c) => (c[0] as { type: string }).type === 'pos.went_offline',
    );
    expect(offlineCalls).toHaveLength(1);
  });
});
