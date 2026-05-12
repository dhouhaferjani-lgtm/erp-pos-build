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

async function runOnlyV31(adapter: SqliteTestAdapter): Promise<void> {
  const v31 = migrations.find((migration) => migration.version === 31);
  if (!v31) {
    throw new Error('Migration v31 not found in migrations.ts');
  }
  await adapter.execute(v31.sql);
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
}

d('Migration v31 — create_pos_migration_state', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates the pos_migration_state table for one-shot runtime flags', async () => {
    await runMigrationsUpTo(adapter, 30);
    await runOnlyV31(adapter);

    const cols = await adapter.select<ColumnInfo[]>('PRAGMA table_info(pos_migration_state)');

    expect(cols.map((column) => column.name)).toEqual(['key', 'value', 'updated_at']);
    expect(cols.find((column) => column.name === 'key')?.type).toBe('TEXT');
    expect(cols.find((column) => column.name === 'value')?.notnull).toBe(1);
  });

  it('is idempotent because the state table uses IF NOT EXISTS', async () => {
    await runMigrationsUpTo(adapter, 30);
    await runOnlyV31(adapter);
    await expect(runOnlyV31(adapter)).resolves.toBeUndefined();
  });
});
