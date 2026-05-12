/**
 * Cash-drawer ops boot-time stranded-`'syncing'` recovery.
 *
 * Same shape as PR #94's T2.1 Step D recovery for offline_receipts. The
 * cash-drawer push path in `syncService.pushCashDrawerOps` advances a row
 * to `'syncing'` BEFORE the sync HTTP call (see `syncService.ts:506`). A
 * SIGKILL / power-cut / OS-level kill BETWEEN the status update and the
 * response handler leaves the row at `'syncing'` permanently.
 *
 * `getPendingCashDrawerOps` filters
 *   `WHERE status IN ('pending', 'failed') AND retry_count < 5`
 * so the orphan is invisible to every subsequent retry — silent loss of a
 * cash-drawer event that the merchant relied on for their till
 * reconciliation. Recovery demotes any `'syncing'` row back to `'pending'`
 * on boot, idempotent across multiple boots.
 *
 * Idempotency reasoning matches Step D: T0.2's per-op `idempotency_key`
 * dedups any double-send server-side.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  recoverStrandedSyncingCashDrawerOps,
  type OfflineCashDrawerOp,
} from '../cashDrawerRepository';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function insertOp(
  adapter: SqliteTestAdapter,
  id: string,
  status: OfflineCashDrawerOp['status'],
  retryCount = 0,
): Promise<void> {
  await adapter.execute(
    `INSERT INTO offline_cash_drawer_ops (
      id, idempotency_key, type, amount, reason,
      operator_id, operator_name, terminal_id, shift_id, status, retry_count
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)`,
    [id, `key-${id}`, 'deposit', '10.00', 'change', 'op-1', 'Cashier', 't-1', 's-1', status, retryCount],
  );
}

d('recoverStrandedSyncingCashDrawerOps — boot-time crash recovery', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('demotes a stranded `syncing` row to `pending`', async () => {
    await insertOp(adapter, 'op-stranded', 'syncing', 1);

    const recovered = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    expect(recovered).toBe(1);

    const rows = await adapter.select<{ id: string; status: string }[]>(
      'SELECT id, status FROM offline_cash_drawer_ops WHERE id = $1',
      ['op-stranded'],
    );
    expect(rows).toEqual([{ id: 'op-stranded', status: 'pending' }]);
  });

  it('is idempotent — second call finds zero stranded rows', async () => {
    await insertOp(adapter, 'op-stranded', 'syncing');

    const first = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());
    const second = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    expect(first).toBe(1);
    expect(second).toBe(0);
  });

  it('does not touch `pending`, `failed`, or `synced` rows', async () => {
    await insertOp(adapter, 'op-pending', 'pending');
    await insertOp(adapter, 'op-failed', 'failed', 2);
    await insertOp(adapter, 'op-synced', 'synced');
    await insertOp(adapter, 'op-syncing', 'syncing', 1);

    const recovered = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    expect(recovered).toBe(1);

    const rows = await adapter.select<{ id: string; status: string }[]>(
      'SELECT id, status FROM offline_cash_drawer_ops ORDER BY id',
    );
    expect(rows).toEqual([
      { id: 'op-failed', status: 'failed' },
      { id: 'op-pending', status: 'pending' },
      { id: 'op-synced', status: 'synced' },
      { id: 'op-syncing', status: 'pending' },
    ]);
  });

  it('demotes multiple stranded rows in a single call', async () => {
    await insertOp(adapter, 'op-1', 'syncing');
    await insertOp(adapter, 'op-2', 'syncing', 2);
    await insertOp(adapter, 'op-3', 'syncing', 4);

    const recovered = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    expect(recovered).toBe(3);

    const rows = await adapter.select<{ status: string; cnt: number }[]>(
      "SELECT status, COUNT(*) as cnt FROM offline_cash_drawer_ops GROUP BY status",
    );
    expect(rows).toEqual([{ status: 'pending', cnt: 3 }]);
  });

  it('decrements retry_count by 1 to subtract the syncing-transition increment', async () => {
    // Codex round-1 P2: cash-drawer's updateCashDrawerOpStatus increments
    // retry_count for every non-'synced' transition, including 'syncing'.
    // Without compensation, a row that entered syncing at retry_count = N
    // crashed at N+1; the recovery must decrement back to N so the row's
    // retry budget reflects only completed attempts.
    await insertOp(adapter, 'op-retry-3', 'syncing', 3);

    await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    const rows = await adapter.select<{ id: string; status: string; retry_count: number }[]>(
      'SELECT id, status, retry_count FROM offline_cash_drawer_ops WHERE id = $1',
      ['op-retry-3'],
    );
    expect(rows).toEqual([{ id: 'op-retry-3', status: 'pending', retry_count: 2 }]);
  });

  it('Codex P2 regression: a final-attempt strand (retry_count = 5) recovers as eligible for retry', async () => {
    // Specific scenario Codex flagged: a row at retry_count = 4 enters
    // syncing → updateCashDrawerOpStatus increments to 5 → SIGKILL → row
    // stranded. Without the decrement, recovery demotes status but the
    // `retry_count < 5` filter inside getPendingCashDrawerOps then skips
    // it forever. With the decrement, retry_count returns to 4 and the
    // op is eligible for one more retry — which is exactly the
    // crash-recovery contract.
    await insertOp(adapter, 'op-final-attempt', 'syncing', 5);

    await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    const recovered = await adapter.select<{ id: string; status: string; retry_count: number }[]>(
      "SELECT id, status, retry_count FROM offline_cash_drawer_ops WHERE status IN ('pending','failed') AND retry_count < 5 ORDER BY id",
    );
    expect(recovered).toEqual([{ id: 'op-final-attempt', status: 'pending', retry_count: 4 }]);
  });

  it('floors retry_count at 0 (defensive — should never happen in production)', async () => {
    // Defence-in-depth: a row with retry_count = 0 in 'syncing' state is
    // logically impossible (the `'pending' → 'syncing'` transition always
    // bumps from 0 to ≥ 1) but the SQL `MAX(0, retry_count - 1)` floor
    // prevents an underflow if data ever arrives this way.
    await insertOp(adapter, 'op-retry-0', 'syncing', 0);

    await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    const rows = await adapter.select<{ id: string; status: string; retry_count: number }[]>(
      'SELECT id, status, retry_count FROM offline_cash_drawer_ops WHERE id = $1',
      ['op-retry-0'],
    );
    expect(rows).toEqual([{ id: 'op-retry-0', status: 'pending', retry_count: 0 }]);
  });

  it('returns 0 when there are no stranded rows', async () => {
    await insertOp(adapter, 'op-pending', 'pending');
    await insertOp(adapter, 'op-synced', 'synced');

    const recovered = await recoverStrandedSyncingCashDrawerOps(adapter.asDatabase());

    expect(recovered).toBe(0);
  });
});
