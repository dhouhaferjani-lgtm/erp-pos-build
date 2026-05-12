import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

async function runOnlyV32(adapter: SqliteTestAdapter): Promise<void> {
  const v32 = migrations.find((migration) => migration.version === 32);
  if (!v32) {
    throw new Error('Migration v32 not found in migrations.ts');
  }
  if (v32.run) {
    await v32.run(adapter);
  } else {
    await adapter.execute(v32.sql);
  }
}

interface ReceiptRow {
  id: string;
  status: string;
  sync_error: string | null;
  retry_count: number;
}

async function insertReceipt(
  adapter: SqliteTestAdapter,
  row: {
    id: string;
    idempotency_key: string;
    status: string;
    sync_error: string | null;
    retry_count?: number;
  },
): Promise<void> {
  await adapter.execute(
    `INSERT INTO offline_receipts (
       id, idempotency_key, receipt_number, terminal_id, terminal_code,
       operator_id, operator_name, lines, subtotal, tax_amount, total,
       currency, fiscal_hash, previous_hash, hash_sequence,
       payment_method_id, payment_repository_id,
       status, sync_error, retry_count
     ) VALUES (
       $1, $2, 'R-001', 't1', 'C1', 'op1', 'Op One', '[]', '0', '0', '0',
       'TND', 'h', 'p', 1, 'pm1', 'pr1', $3, $4, $5
     )`,
    [row.id, row.idempotency_key, row.status, row.sync_error, row.retry_count ?? 0],
  );
}

d('Migration v32 — recover_stuck_offline_receipts_from_db_lock', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 31);
  });

  afterEach(() => {
    adapter.close();
  });

  it('resets status, retry_count, and sync_error for lock-failed receipts only', async () => {
    await insertReceipt(adapter, {
      id: 'r1',
      idempotency_key: 'k1',
      status: 'failed',
      sync_error: 'error returned from database: (code: 5) database is locked',
      retry_count: 5,
    });
    await insertReceipt(adapter, {
      id: 'r2',
      idempotency_key: 'k2',
      status: 'failed',
      sync_error: 'Hash chain break: client previous_hash does not match',
      retry_count: 1,
    });
    await insertReceipt(adapter, {
      id: 'r3',
      idempotency_key: 'k3',
      status: 'pending',
      sync_error: null,
      retry_count: 0,
    });
    // Edge: a previously-stuck row that was persisted as 'Unknown error'
    // BEFORE the coerceSyncError fix shipped — must NOT be recovered by
    // the LIKE-match (the recovery is intentionally narrow).
    await insertReceipt(adapter, {
      id: 'r4',
      idempotency_key: 'k4',
      status: 'failed',
      sync_error: 'Unknown error',
      retry_count: 5,
    });

    await runOnlyV32(adapter);

    const rows = await adapter.select<ReceiptRow[]>(
      'SELECT id, status, sync_error, retry_count FROM offline_receipts ORDER BY id',
    );
    const byId = Object.fromEntries(rows.map((r) => [r.id, r]));

    expect(byId.r1).toEqual({
      id: 'r1',
      status: 'pending',
      sync_error: null,
      retry_count: 0,
    });
    expect(byId.r2).toEqual({
      id: 'r2',
      status: 'failed',
      sync_error: 'Hash chain break: client previous_hash does not match',
      retry_count: 1,
    });
    expect(byId.r3).toEqual({
      id: 'r3',
      status: 'pending',
      sync_error: null,
      retry_count: 0,
    });
    expect(byId.r4).toEqual({
      id: 'r4',
      status: 'failed',
      sync_error: 'Unknown error',
      retry_count: 5,
    });
  });

  it('is idempotent — running v32 a second time leaves no rows to mutate', async () => {
    await insertReceipt(adapter, {
      id: 'r1',
      idempotency_key: 'k1',
      status: 'failed',
      sync_error: 'error returned from database: (code: 5) database is locked',
      retry_count: 5,
    });

    await runOnlyV32(adapter);
    await runOnlyV32(adapter);

    const rows = await adapter.select<ReceiptRow[]>(
      "SELECT id, status, sync_error, retry_count FROM offline_receipts WHERE id = 'r1'",
    );
    expect(rows[0]).toEqual({
      id: 'r1',
      status: 'pending',
      sync_error: null,
      retry_count: 0,
    });
  });

  it('matches case-insensitively (sqlite LIKE default; tolerant of mixed casing)', async () => {
    await insertReceipt(adapter, {
      id: 'r1',
      idempotency_key: 'k1',
      status: 'failed',
      sync_error: 'Error returned from Database: (code: 5) DATABASE IS LOCKED',
      retry_count: 5,
    });

    await runOnlyV32(adapter);

    const rows = await adapter.select<ReceiptRow[]>(
      "SELECT status FROM offline_receipts WHERE id = 'r1'",
    );
    // SQLite's LIKE is case-insensitive by default for ASCII; this row's
    // signature must therefore recover even if the plugin layer ever
    // upper-cases the message.
    expect(rows[0]?.status).toBe('pending');
  });
});
