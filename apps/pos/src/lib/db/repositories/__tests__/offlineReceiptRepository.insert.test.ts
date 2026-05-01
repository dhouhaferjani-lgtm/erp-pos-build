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
    fiscal_schema_version: 2,
  };
}

describe('insertOfflineReceipt — event-driven sync trigger', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('schedules a debounced triggerSync 250 ms after a successful insert', async () => {
    await insertOfflineReceipt(db, makeReceipt());

    // Immediate check: the sync must NOT have fired yet (payment UI must unblock first).
    expect(triggerSyncSpy).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(250);

    expect(triggerSyncSpy).toHaveBeenCalledOnce();
  });

  it('collapses multiple rapid inserts into a single sync trigger', async () => {
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-1', idempotency_key: 'k-1' });
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-2', idempotency_key: 'k-2' });
    await insertOfflineReceipt(db, { ...makeReceipt(), id: 'r-3', idempotency_key: 'k-3' });

    await vi.advanceTimersByTimeAsync(250);

    expect(triggerSyncSpy).toHaveBeenCalledOnce();
  });
});
