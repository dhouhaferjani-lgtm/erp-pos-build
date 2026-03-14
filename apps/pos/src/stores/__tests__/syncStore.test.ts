import { describe, it, expect, beforeEach } from 'vitest';
import { useSyncStore } from '../syncStore';
import type { SyncResult } from '@/lib/sync/syncService';

function makeSyncResult(overrides: Partial<SyncResult> = {}): SyncResult {
  return {
    receiptsPushed: 3,
    receiptsFailed: 0,
    zReportsPushed: 0,
    zReportsFailed: 0,
    productsPulled: 10,
    paymentConfigPulled: true,
    operatorsPulled: 2,
    terminalStatePulled: true,
    chainBreak: false,
    errors: [],
    ...overrides,
  };
}

describe('syncStore', () => {
  beforeEach(() => {
    useSyncStore.getState().reset();
  });

  it('has correct initial state', () => {
    const state = useSyncStore.getState();
    expect(state.isSyncing).toBe(false);
    expect(state.lastSyncAt).toBeNull();
    expect(state.lastSyncResult).toBeNull();
    expect(state.pendingReceiptCount).toBe(0);
    expect(state.lastError).toBeNull();
  });

  it('startSync sets isSyncing and clears error', () => {
    useSyncStore.setState({ lastError: 'old error' });

    useSyncStore.getState().startSync();

    const state = useSyncStore.getState();
    expect(state.isSyncing).toBe(true);
    expect(state.lastError).toBeNull();
  });

  it('completeSync stores result and timestamps', () => {
    const result = makeSyncResult();

    useSyncStore.getState().startSync();
    useSyncStore.getState().completeSync(result);

    const state = useSyncStore.getState();
    expect(state.isSyncing).toBe(false);
    expect(state.lastSyncAt).not.toBeNull();
    expect(state.lastSyncResult).toEqual(result);
    expect(state.lastError).toBeNull();
    expect(state.pendingReceiptCount).toBe(0);
  });

  it('completeSync captures first error from result', () => {
    const result = makeSyncResult({
      receiptsFailed: 2,
      errors: ['Receipt R-001: timeout', 'Receipt R-002: conflict'],
    });

    useSyncStore.getState().completeSync(result);

    const state = useSyncStore.getState();
    expect(state.lastError).toBe('Receipt R-001: timeout');
    expect(state.pendingReceiptCount).toBe(2);
  });

  it('failSync records error without clearing sync state', () => {
    useSyncStore.getState().startSync();
    useSyncStore.getState().failSync('Network unreachable');

    const state = useSyncStore.getState();
    expect(state.isSyncing).toBe(false);
    expect(state.lastError).toBe('Network unreachable');
  });

  it('setPendingCount updates pending receipt count', () => {
    useSyncStore.getState().setPendingCount(5);
    expect(useSyncStore.getState().pendingReceiptCount).toBe(5);
  });

  it('reset returns to initial state', () => {
    useSyncStore.setState({
      isSyncing: true,
      lastSyncAt: Date.now(),
      lastError: 'error',
      pendingReceiptCount: 3,
    });

    useSyncStore.getState().reset();

    const state = useSyncStore.getState();
    expect(state.isSyncing).toBe(false);
    expect(state.lastSyncAt).toBeNull();
    expect(state.lastError).toBeNull();
    expect(state.pendingReceiptCount).toBe(0);
  });
});
