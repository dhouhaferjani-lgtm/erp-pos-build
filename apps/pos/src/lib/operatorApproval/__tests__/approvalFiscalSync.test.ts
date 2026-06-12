/**
 * FU-9 — approval drains trigger an immediate post-drain location-stock pull.
 *
 * When an offline approval flow (e.g. manager-PIN discount) drains the receipt
 * queue, the server snapshot now includes those receipts; re-baselining stock
 * right then collapses the local pending adjustment instead of waiting up to
 * 60s for the periodic tick. The pull is fire-and-forget + swallowed — the
 * approval flow must never block on it, and a stock-pull failure is non-fatal.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Hoisted so the (hoisted) vi.mock factories below can reference it.
const { fakeDb } = vi.hoisted(() => ({ fakeDb: { tag: 'db' } }));

vi.mock('@/lib/sync/syncService', () => ({
  pushOfflineReceipts: vi.fn().mockResolvedValue({ pushed: 0, failed: 0 }),
  pullLocationStock: vi.fn().mockResolvedValue({ count: 0 }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue(fakeDb),
  queryAll: vi.fn(),
}));

import { ensureApprovalFiscalEventsSynced } from '../approvalFiscalSync';
import { pushOfflineReceipts, pullLocationStock } from '@/lib/sync/syncService';
import { queryAll } from '@/lib/db';

const syncedRow = { id: 'evt-1', sync_status: 'synced', sync_error: null };

describe('ensureApprovalFiscalEventsSynced — FU-9 post-drain stock pull', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(pushOfflineReceipts).mockResolvedValue({ pushed: 0, failed: 0 } as never);
    vi.mocked(pullLocationStock).mockResolvedValue({ count: 0 });
    vi.mocked(queryAll).mockResolvedValue([syncedRow]);
  });

  it('pulls location stock (delta) after draining the receipt queue', async () => {
    await ensureApprovalFiscalEventsSynced('company-1', ['evt-1']);

    expect(pushOfflineReceipts).toHaveBeenCalledTimes(1);
    expect(pullLocationStock).toHaveBeenCalledTimes(1);
    expect(pullLocationStock).toHaveBeenCalledWith(fakeDb, 'delta');

    // Drain BEFORE the re-baseline pull.
    const drainOrder = vi.mocked(pushOfflineReceipts).mock.invocationCallOrder[0];
    const pullOrder = vi.mocked(pullLocationStock).mock.invocationCallOrder[0];
    expect(drainOrder).toBeLessThan(pullOrder!);
  });

  it('does not pull (or drain) when there are no event ids', async () => {
    await ensureApprovalFiscalEventsSynced('company-1', []);

    expect(pushOfflineReceipts).not.toHaveBeenCalled();
    expect(pullLocationStock).not.toHaveBeenCalled();
  });

  it('swallows a failed post-drain stock pull (the approval flow is never blocked)', async () => {
    vi.mocked(pullLocationStock).mockRejectedValue(new Error('network down'));

    await expect(
      ensureApprovalFiscalEventsSynced('company-1', ['evt-1']),
    ).resolves.toBeUndefined();
  });
});
