import { describe, it, expect, beforeEach } from 'vitest';
import { useSyncStore } from '../syncStore';
import type { SyncResult } from '@/lib/sync/syncService';

function makeSyncResult(overrides: Partial<SyncResult> = {}): SyncResult {
  return {
    receiptsPushed: 3,
    receiptsFailed: 0,
    zReportsPushed: 0,
    zReportsFailed: 0,
    cashDrawerOpsPushed: 0,
    pinUpdatesPushed: 0,
    voucherLedgerPushed: 0,
    voucherLedgerFailed: 0,
    productsPulled: 10,
    paymentConfigPulled: true,
    operatorsPulled: 2,
    terminalStatePulled: true,
    tablesPulled: true,
    activeMenuPulled: true,
    vouchersPulled: 0,
    voucherLedgerPulled: 0,
    receiptQrIndexPulled: 0,
    chainBreak: false,
    errors: [],
    degraded: false,
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
  });

  it('completeSync captures first error from result', () => {
    const result = makeSyncResult({
      receiptsFailed: 2,
      errors: ['Receipt R-001: timeout', 'Receipt R-002: conflict'],
    });

    useSyncStore.getState().completeSync(result);

    const state = useSyncStore.getState();
    expect(state.lastError).toBe('Receipt R-001: timeout');
  });

  // T1.3 Step 4.1: pendingReceiptCount reads SQLite truth, not
  // result.receiptsFailed. completeSync MUST leave pendingReceiptCount
  // untouched — the scheduler's post-completeSync hook is responsible
  // for the SQLite read + setPendingCount call. Without this contract,
  // the badge drifts from the real "needs sync attention" count after
  // the first tick that fails to queue new receipts.
  it('T1.3: completeSync does NOT overwrite pendingReceiptCount with result.receiptsFailed', () => {
    // Seed an externally-set count (as if startup hydration or the
    // scheduler's post-completeSync hook has already written it).
    useSyncStore.getState().setPendingCount(7);

    const result = makeSyncResult({ receiptsFailed: 2 });
    useSyncStore.getState().completeSync(result);

    // Pre-fix: pendingReceiptCount would now be 2 (overwritten).
    // Post-fix: pendingReceiptCount stays at 7 — the scheduler's
    // SQLite hydration step is the only writer.
    expect(useSyncStore.getState().pendingReceiptCount).toBe(7);
  });

  // T1.3 Step 4.2: persistent lastSyncAt entry point. completeSync's
  // existing in-memory write is preserved; the new setLastSyncAt action
  // is for hydration from SQLite on app boot (so the SyncButton's
  // "X minutes ago" affordance survives restarts).
  it('T1.3: setLastSyncAt updates the timestamp directly', () => {
    useSyncStore.getState().setLastSyncAt(1717891200000);
    expect(useSyncStore.getState().lastSyncAt).toBe(1717891200000);
  });

  it('T1.3: setLastSyncAt accepts null to clear the value', () => {
    useSyncStore.setState({ lastSyncAt: 1717891200000 });
    useSyncStore.getState().setLastSyncAt(null);
    expect(useSyncStore.getState().lastSyncAt).toBeNull();
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

  it('setChainBreak(true, "R1") sets the flag and clears prior acknowledgement', () => {
    useSyncStore.setState({ chainBreakAcknowledgedAt: '2026-04-01T00:00:00.000Z' });
    useSyncStore.getState().setChainBreak(true, 'R1');
    const s = useSyncStore.getState();
    expect(s.chainBreak).toBe(true);
    expect(s.chainBreakReceiptNumber).toBe('R1');
    expect(s.chainBreakAcknowledgedAt).toBeNull();
  });

  it('setChainBreak(false, null) clears the flag and records resolution timestamp', () => {
    useSyncStore.setState({ chainBreak: true, chainBreakReceiptNumber: 'R1', chainBreakAcknowledgedAt: null });
    useSyncStore.getState().setChainBreak(false, null);
    const s = useSyncStore.getState();
    expect(s.chainBreak).toBe(false);
    expect(s.chainBreakReceiptNumber).toBeNull();
    expect(s.chainBreakAcknowledgedAt).toBeTruthy();
  });

  it('acknowledgeChainBreak records a timestamp but does NOT clear the chainBreak flag', () => {
    useSyncStore.setState({ chainBreak: true, chainBreakReceiptNumber: 'R1', chainBreakAcknowledgedAt: null });
    useSyncStore.getState().acknowledgeChainBreak();
    const s = useSyncStore.getState();
    expect(s.chainBreak).toBe(true); // NOT cleared
    expect(s.chainBreakAcknowledgedAt).toBeTruthy();
  });
});
