/**
 * Real-SQLite replay test for migration v28 (fiscal_schema_version).
 *
 * Codex review R1 (2026-04-30): the production migration `add_fiscal_schema_version`
 * adds NOT NULL DEFAULT 2 columns to two tables that may already contain rows
 * (`terminal_state` after activation, `offline_receipts` for any in-flight
 * pending sync). Without a real-SQLite replay, a defective migration would
 * pass linting and unit tests but fail in production at deploy time, when
 * cashiers are mid-shift and rows already exist.
 *
 * The three scenarios:
 *   1. Replay v28 over an empty schema — both columns must be present and
 *      typed as `INTEGER NOT NULL DEFAULT 2`.
 *   2. Replay v28 over a populated `offline_receipts` table — pre-existing
 *      rows must backfill cleanly to `fiscal_schema_version = 2` (the
 *      legacy default).
 *   3. Replay v28 over a populated `terminal_state` table — same backfill
 *      semantics.
 *
 * The error-swallowing branch in v28 (`duplicate column` → ignore) is also
 * proven by running the migration twice in scenario 1.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

/**
 * Run migrations [1, maxVersion] in sequence against the adapter.
 */
async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const m of migrations) {
    if (m.version > maxVersion) continue;
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function runOnlyV28(adapter: SqliteTestAdapter): Promise<void> {
  const v28 = migrations.find((m) => m.version === 28);
  if (!v28) {
    throw new Error('Migration v28 not found in migrations.ts');
  }
  if (!v28.run) {
    throw new Error('Migration v28 must have a run() function');
  }
  await v28.run(adapter);
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | number | null;
}

d('Migration v28 — add_fiscal_schema_version', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('replays cleanly over a fresh empty schema (terminal_state + offline_receipts)', async () => {
    // Run migrations 1..27, then v28 separately so we can assert exactly what
    // the v28 step contributes.
    await runMigrationsUpTo(adapter, 27);
    await runOnlyV28(adapter);

    const terminalCols = await adapter.select<ColumnInfo[]>(
      'PRAGMA table_info(terminal_state)',
    );
    const tsVersionCol = terminalCols.find((c) => c.name === 'fiscal_schema_version');
    expect(tsVersionCol, 'terminal_state.fiscal_schema_version must exist after v28').toBeDefined();
    expect(tsVersionCol?.type).toBe('INTEGER');
    expect(tsVersionCol?.notnull).toBe(1);
    expect(String(tsVersionCol?.dflt_value)).toBe('2');

    const offlineCols = await adapter.select<ColumnInfo[]>(
      'PRAGMA table_info(offline_receipts)',
    );
    const orVersionCol = offlineCols.find((c) => c.name === 'fiscal_schema_version');
    expect(orVersionCol, 'offline_receipts.fiscal_schema_version must exist after v28').toBeDefined();
    expect(orVersionCol?.type).toBe('INTEGER');
    expect(orVersionCol?.notnull).toBe(1);
    expect(String(orVersionCol?.dflt_value)).toBe('2');
  });

  it('is idempotent: running v28 twice does not throw on duplicate column', async () => {
    await runMigrationsUpTo(adapter, 27);
    await runOnlyV28(adapter);
    // Second run must swallow the "duplicate column" error and exit cleanly.
    await expect(runOnlyV28(adapter)).resolves.toBeUndefined();
  });

  it('backfills existing offline_receipts rows to fiscal_schema_version = 2', async () => {
    // Migrate up to v27, seed three rows that pre-date the v28 column, then
    // run v28 and assert the backfill.
    await runMigrationsUpTo(adapter, 27);

    // Insert minimum-viable rows. The `offline_receipts` schema is large, so
    // we bind only the NOT NULL columns and let DEFAULT fill the rest. SQLite
    // tolerates omitted nullable columns.
    // SqliteTestAdapter binds positional parameters as `$1, $2, ...` (Postgres
    // style) to match the production tauri-plugin-sql contract.
    const seedSql = `
      INSERT INTO offline_receipts (
        id, idempotency_key, receipt_number, terminal_id, terminal_code,
        operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
        total, currency, fiscal_hash, previous_hash, hash_sequence,
        tendered_amount, change_due, payment_method_id, payment_repository_id,
        status
      ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21)
    `;
    for (let i = 1; i <= 3; i++) {
      await adapter.execute(seedSql, [
        `id-${i}`,
        `idem-${i}`,
        `R-${i}`,
        'terminal-1',
        'T001',
        'op-1',
        'Operator',
        '[]',
        '10.00',
        '0.00',
        '0.00',
        '10.00',
        'EUR',
        `hash-${i}`,
        'prev',
        i,
        '10.00',
        '0.00',
        'pm-1',
        'repo-1',
        'pending',
      ]);
    }

    await runOnlyV28(adapter);

    const rows = await adapter.select<Array<{ id: string; fiscal_schema_version: number }>>(
      'SELECT id, fiscal_schema_version FROM offline_receipts ORDER BY id',
    );
    expect(rows).toHaveLength(3);
    for (const row of rows) {
      expect(
        row.fiscal_schema_version,
        `pre-existing offline_receipts row ${row.id} must backfill to 2`,
      ).toBe(2);
    }
  });

  it('backfills existing terminal_state rows to fiscal_schema_version = 2', async () => {
    await runMigrationsUpTo(adapter, 27);

    // terminal_state's NOT NULL columns from earlier migrations.
    const seedSql = `
      INSERT INTO terminal_state (
        terminal_id, terminal_code, location_code, genesis_seed,
        last_hash, hash_sequence
      ) VALUES ($1, $2, $3, $4, $5, $6)
    `;
    await adapter.execute(seedSql, [
      'terminal-pre-v28',
      'T999',
      'MAIN',
      'seed',
      'GENESIS',
      0,
    ]);

    await runOnlyV28(adapter);

    const rows = await adapter.select<Array<{ terminal_id: string; fiscal_schema_version: number }>>(
      'SELECT terminal_id, fiscal_schema_version FROM terminal_state',
    );
    expect(rows).toHaveLength(1);
    expect(
      rows[0]!.fiscal_schema_version,
      'pre-existing terminal_state row must backfill to 2 (legacy default)',
    ).toBe(2);
  });
});
