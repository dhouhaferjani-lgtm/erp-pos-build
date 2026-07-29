import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
}));

const triggerSyncSpy = vi.fn();
vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: () => ({ triggerSync: triggerSyncSpy }),
  },
}));

import { insertOfflineReceipt, type OfflineReceipt } from '../offlineReceiptRepository';

const db = {} as import('@tauri-apps/plugin-sql').default;

function makeReceipt(): Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> {
  return {
    id: 'r-1',
    idempotency_key: 'k-1',
    receipt_number: 'MAIN-T001-2026-00000001',
    terminal_id: 't-1',
    terminal_code: 'T001',
    operator_id: 'op-1',
    operator_name: 'Cashier',
    lines: '[]',
    subtotal: '10.00',
    tax_amount: '0.00',
    discount_amount: '0.00',
    total: '10.00',
    currency: 'TND',
    fiscal_hash: 'hash',
    previous_hash: 'prev',
    hash_sequence: 1,
    transaction_discount_amount: null,
    transaction_discount_reason: null,
    tendered_amount: '10.00',
    change_due: '0.00',
    payment_method_id: 'pm-1',
    payment_repository_id: 'repo-1',
    status: 'pending',
    payments_json: '[]',
    consumption_mode: null,
    table_id: null,
    cash_rounding_adjustment: null,
    cash_rounding_denomination: null,
    tolerance_shortfall: null,
    fiscal_schema_version: 2,
    is_training: 0,
  };
}

describe('insertOfflineReceipt — pure repository (no side-effects)', () => {
  // T2.2 Step 5.1: the sync trigger has moved from the repository to the
  // service layer (createOfflineReceipt, post-COMMIT). The repository
  // function is now transactionally pure: DB-work only, no scheduler
  // side-effects. Tests for the trigger itself live in
  // receiptService.test.ts under "T2.2 Step 5.1: post-COMMIT sync trigger".
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('T2.2 regression guard: insertOfflineReceipt does NOT trigger sync (single insert)', async () => {
    await insertOfflineReceipt(db, makeReceipt());

    // Pre-T2.2 the repository called scheduleDebouncedSync() at the end of
    // insertOfflineReceipt; after the 250 ms debounce that fired triggerSync.
    // Post-T2.2 the trigger lives in receiptService.createOfflineReceipt
    // after db.execute('COMMIT'), so the repository must not call it.
    await vi.advanceTimersByTimeAsync(500);

    expect(triggerSyncSpy).not.toHaveBeenCalled();
  });

  it('T2.2 regression guard: insertOfflineReceipt does NOT trigger sync (multiple rapid inserts)', async () => {
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-1', idempotency_key: 'k-1' });
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-2', idempotency_key: 'k-2' });
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-3', idempotency_key: 'k-3' });

    await vi.advanceTimersByTimeAsync(500);

    expect(triggerSyncSpy).not.toHaveBeenCalled();
  });
});
