import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import { queryAll, execute } from '@/lib/db';
import {
  insertHeldTransaction,
  listHeldTransactions,
  listAllHeldTransactions,
  deleteHeldTransaction,
  deleteHeldTransactionsByIds,
  type HeldTransactionRow,
} from '../heldTransactionRepository';

describe('heldTransactionRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('insertHeldTransaction persists all fields', async () => {
    const row: HeldTransactionRow = {
      id: 'held-1',
      terminal_id: 'term-1',
      operator_id: 'op-1',
      label: 'Table 3',
      items_json: '[{"id":"line-1"}]',
      transaction_discount_json: null,
      subtotal: '25.90',
      total: '25.90',
      item_count: 2,
      held_at: '2026-04-23T09:00:00Z',
    };

    await insertHeldTransaction(db, row);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO held_transactions/);
    expect(params).toEqual([
      row.id,
      row.terminal_id,
      row.operator_id,
      row.label,
      row.items_json,
      row.transaction_discount_json,
      row.subtotal,
      row.total,
      row.item_count,
      row.held_at,
    ]);
  });

  it('listHeldTransactions filters by terminal_id and orders by held_at DESC', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);

    await listHeldTransactions(db, 'term-abc');

    expect(queryAll).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/SELECT \* FROM held_transactions WHERE terminal_id = \$1 ORDER BY held_at DESC/);
    expect(params).toEqual(['term-abc']);
  });

  it('deleteHeldTransaction issues a DELETE by id', async () => {
    await deleteHeldTransaction(db, 'held-1');

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/DELETE FROM held_transactions WHERE id = \$1/);
    expect(params).toEqual(['held-1']);
  });

  it('listAllHeldTransactions orders every held transaction by held_at DESC', async () => {
    vi.mocked(queryAll).mockResolvedValue([]);

    await listAllHeldTransactions(db);

    expect(queryAll).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(queryAll).mock.calls[0]!;
    expect(sql).toMatch(/SELECT \* FROM held_transactions ORDER BY held_at DESC/);
    expect(params).toEqual([]);
  });

  it('deleteHeldTransactionsByIds deletes all ids in one statement', async () => {
    await deleteHeldTransactionsByIds(db, ['held-1', 'held-2']);

    expect(execute).toHaveBeenCalledTimes(1);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/DELETE FROM held_transactions WHERE id IN \(\$1, \$2\)/);
    expect(params).toEqual(['held-1', 'held-2']);
  });

  it('deleteHeldTransactionsByIds is a no-op for an empty id list', async () => {
    await deleteHeldTransactionsByIds(db, []);

    expect(execute).not.toHaveBeenCalled();
  });
});
