import type Database from '@tauri-apps/plugin-sql';
import { queryOne, execute } from '@/lib/db';

export async function logSyncOperation(
  db: Database,
  operation: string,
  entityType: string,
  entityId: string | null,
  status: 'success' | 'error',
  details?: string,
): Promise<void> {
  await execute(
    db,
    'INSERT INTO sync_log (operation, entity_type, entity_id, status, details) VALUES ($1, $2, $3, $4, $5)',
    [operation, entityType, entityId, status, details ?? null]
  );
}

export async function getSyncMetadata(db: Database, key: string): Promise<string | null> {
  const row = await queryOne<{ value: string }>(
    db,
    'SELECT value FROM sync_metadata WHERE key = $1',
    [key]
  );
  return row?.value ?? null;
}

export async function setSyncMetadata(db: Database, key: string, value: string): Promise<void> {
  await execute(
    db,
    `INSERT INTO sync_metadata (key, value, updated_at) VALUES ($1, $2, datetime('now'))
     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')`,
    [key, value]
  );
}

export async function cleanupOldSyncLogs(db: Database, daysToKeep: number = 7): Promise<void> {
  await execute(
    db,
    "DELETE FROM sync_log WHERE created_at < datetime('now', '-' || $1 || ' days')",
    [daysToKeep]
  );
}
