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

  await runMigrations(db);

  return db;
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
