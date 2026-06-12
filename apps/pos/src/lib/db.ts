import Database from '@tauri-apps/plugin-sql';
import { migrations } from './db/migrations';
import { wrapDatabaseWithBusyRetry } from './db/busyRetry';
import { closeWriter, createWriterSurface, openWriter } from './db/dbWriter';
import { enqueueWrite, setWriter } from './db/writeGate';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

let db: Database | null = null;
let currentDbName: string | null = null;

export async function getDatabase(companyId: string): Promise<Database> {
  const dbName = `izipos-${companyId}.db`;

  if (db && currentDbName === dbName) {
    return db;
  }

  // Close previous connections if switching companies
  if (db && currentDbName !== dbName) {
    await db.close();
    if (currentDbName) await closeWriter(currentDbName);
    setWriter(null);
    db = null;
  }

  // Single-writer architecture (2026-06-12 design spec):
  //   - ALL writes serialize through the writeGate; multi-statement
  //     transactions run on the Rust-owned single connection opened below.
  //     The plugin's SQLx POOL splits a JS BEGIN…COMMIT across physical
  //     connections (sqlx returns in-tx connections to the idle queue
  //     without rollback) → the receipt path self-deadlocked against its
  //     own orphaned BEGIN and sync writes could join an open fiscal tx.
  //   - The plugin pool below is the READ path only (WAL ⇒ readers never
  //     block on the writer). busyRetry stays as read-side hardening for
  //     rare checkpoint-edge BUSY.
  // The writer's connect options own the WAL/synchronous/busy_timeout
  // pragmas (WAL is file-persistent), so no pool-side PRAGMA is issued.
  //
  // Operational note: WAL creates `-wal` and `-shm` sidecar files next to
  // the main `.db` file. Any backup tooling must include them OR call
  // `PRAGMA wal_checkpoint(TRUNCATE)` and close the connection first.
  await openWriter(dbName);
  const writerSurface: SqlSurface = createWriterSurface(dbName);
  setWriter(writerSurface);

  db = await Database.load(`sqlite:${dbName}`);
  currentDbName = dbName;
  wrapDatabaseWithBusyRetry(db);

  // Migrations + recovery are writes → run on the writer through the gate
  // (migrations include multi-statement blocks and BEGIN-using data
  // migrations; the pool must never see transaction statements).
  await enqueueWrite('fiscal', () => runMigrations(writerSurface));

  // Codex r1 P2 closure — migration v32 is a one-shot retroactive cleanup
  // (logged in `_migrations`), but lock-signature failures could in principle
  // reappear post-WAL (extreme write contention, checkpoint stalls, OS-level
  // file locks on networked storage). Running the same UPDATE as an
  // idempotent startup hook every boot is the rerunnable safety net: empty
  // result when nothing is stuck, costless when there is. The migration row
  // remains as the audit-trail anchor for the initial retroactive sweep.
  await enqueueWrite('fiscal', () => runStuckReceiptRecovery(writerSurface));

  return db;
}

/**
 * Bug 5 — rerunnable recovery for offline_receipts dead-lettered with the
 * SQLite lock signature. See migration v32 for the one-shot retroactive
 * counterpart. WHERE clause is intentionally identical so both code paths
 * share a single semantic.
 *
 * Exported for unit testing only — production callers should rely on
 * `getDatabase` invoking it automatically after migrations.
 */
export async function runStuckReceiptRecovery(database: SqlSurface): Promise<void> {
  await database.execute(
    `UPDATE offline_receipts
       SET status = 'pending',
           retry_count = 0,
           sync_error = NULL
     WHERE status = 'failed'
       AND sync_error LIKE '%database is locked%'`,
  );
}

export async function closeDatabase(): Promise<void> {
  if (db) {
    await db.close();
    if (currentDbName) await closeWriter(currentDbName);
    setWriter(null);
    db = null;
    currentDbName = null;
  }
}

async function runMigrations(database: SqlSurface): Promise<void> {
  // Create migrations tracking table
  await database.execute(`
    CREATE TABLE IF NOT EXISTS _migrations (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      version INTEGER NOT NULL UNIQUE,
      name TEXT NOT NULL,
      applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
  `);

  // Get applied migration versions
  const applied = await database.select<{ version: number }[]>(
    'SELECT version FROM _migrations ORDER BY version'
  );
  const appliedVersions = new Set(applied.map((r) => r.version));

  // Apply pending migrations in order
  for (const migration of migrations) {
    if (!appliedVersions.has(migration.version)) {
      if (migration.run) {
        await migration.run(database);
      } else if (migration.sql) {
        await database.execute(migration.sql);
      }
      await database.execute(
        'INSERT INTO _migrations (version, name) VALUES ($1, $2)',
        [migration.version, migration.name]
      );
    }
  }
}

// Type-safe query helpers
export async function queryAll<T>(
  database: Database,
  sql: string,
  params: unknown[] = [],
): Promise<T[]> {
  return database.select<T[]>(sql, params);
}

export async function queryOne<T>(
  database: Database,
  sql: string,
  params: unknown[] = [],
): Promise<T | null> {
  const rows = await database.select<T[]>(sql, params);
  return rows[0] ?? null;
}

export async function execute(
  database: Database,
  sql: string,
  params: unknown[] = [],
): Promise<{ rowsAffected: number; lastInsertId?: number }> {
  return database.execute(sql, params);
}
