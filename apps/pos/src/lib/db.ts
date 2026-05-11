import Database from '@tauri-apps/plugin-sql';
import { migrations } from './db/migrations';

let db: Database | null = null;
let currentDbName: string | null = null;

export async function getDatabase(companyId: string): Promise<Database> {
  const dbName = `izipos-${companyId}.db`;

  if (db && currentDbName === dbName) {
    return db;
  }

  // Close previous connection if switching companies
  if (db && currentDbName !== dbName) {
    await db.close();
    db = null;
  }

  db = await Database.load(`sqlite:${dbName}`);
  currentDbName = dbName;

  // Bug 5 — enable WAL so concurrent readers (the sync scheduler's pending
  // queue read) do not contend with the writer that the cashier-facing
  // `createOfflineReceipt` transaction holds. Default `journal_mode=DELETE`
  // serializes EVERYTHING through an exclusive lock; the Tauri plugin then
  // surfaces `(code: 5) database is locked` as a raw string, the dead-letter
  // cap saturates, and the next sealed receipt's `previous_hash` diverges
  // from the server's `terminal.last_hash` — chain-broken cascade (Bug 4)
  // plus the intermittent "Échec du paiement" the cashier sees (Bug 3).
  //
  // WAL mode is database-file-persistent — a single `PRAGMA journal_mode=WAL`
  // before migrations is sufficient; subsequent connections from the SQLx
  // pool inherit it. busy_timeout is intentionally NOT set in app code —
  // SQLx 0.8.6 already defaults connections to 5s
  // (sqlx-sqlite/src/options/mod.rs:194-201).
  //
  // Operational note: WAL creates `-wal` and `-shm` sidecar files next to
  // the main `.db` file. Any backup tooling must include them OR call
  // `PRAGMA wal_checkpoint(TRUNCATE)` and close the connection first.
  await db.execute('PRAGMA journal_mode=WAL');

  await runMigrations(db);

  // Codex r1 P2 closure — migration v32 is a one-shot retroactive cleanup
  // (logged in `_migrations`), but lock-signature failures could in principle
  // reappear post-WAL (extreme write contention, checkpoint stalls, OS-level
  // file locks on networked storage). Running the same UPDATE as an
  // idempotent startup hook every boot is the rerunnable safety net: empty
  // result when nothing is stuck, costless when there is. The migration row
  // remains as the audit-trail anchor for the initial retroactive sweep.
  await runStuckReceiptRecovery(db);

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
export async function runStuckReceiptRecovery(database: Database): Promise<void> {
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
    db = null;
    currentDbName = null;
  }
}

async function runMigrations(database: Database): Promise<void> {
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
