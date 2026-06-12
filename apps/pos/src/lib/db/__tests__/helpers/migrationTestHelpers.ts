/**
 * FU-6 — shared SQLite migration runners for repository/stock tests.
 *
 * Six test files independently defined a byte-near-identical
 * `applyAllMigrations` helper (and one also a `runMigrationsUpTo` /
 * `runMigrationVersion` pair). Hoisted here so adding a new migration no
 * longer requires editing every copy.
 *
 * All runners accept a {@link SqliteTestAdapter} and dispatch each migration
 * through its `run(db)` hook (programmatic migrations) or `execute(sql)`
 * (raw-SQL migrations), mirroring production `runMigrations`.
 */
import type { SqliteTestAdapter } from './sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';

/** Apply every migration, in declared order, against the in-memory adapter. */
export async function applyAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  await runMigrationsUpTo(adapter, Infinity);
}

/** Apply migrations with `version <= maxVersion`, in declared order. */
export async function runMigrationsUpTo(
  adapter: SqliteTestAdapter,
  maxVersion: number,
): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter.asDatabase());
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

/** Apply a single migration by version (throws if absent). */
export async function runMigrationVersion(
  adapter: SqliteTestAdapter,
  version: number,
): Promise<void> {
  const migration = migrations.find((item) => item.version === version);
  if (!migration) {
    throw new Error(`Migration v${version} not found.`);
  }
  if (migration.run) {
    await migration.run(adapter.asDatabase());
  } else if (migration.sql) {
    await adapter.execute(migration.sql);
  }
}
