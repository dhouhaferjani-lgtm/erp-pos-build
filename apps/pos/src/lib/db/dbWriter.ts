import { invoke } from '@tauri-apps/api/core';
import type { SqlSurface } from '@/lib/fiscal/FiscalEventEngine';

/**
 * JS face of the Rust single-connection writer (src-tauri/src/db_writer.rs).
 * All multi-statement transactions run on this surface via the writeGate —
 * never on the pooled plugin Database. Rejections are plain STRINGS (Tauri
 * boundary), same as the plugin.
 */
export async function openWriter(dbName: string): Promise<void> {
  await invoke('writer_open', { db: dbName });
}

export async function closeWriter(dbName: string): Promise<void> {
  await invoke('writer_close', { db: dbName });
}

export function createWriterSurface(dbName: string): SqlSurface {
  return {
    execute: (sql: string, params: unknown[] = []) =>
      invoke<{ rowsAffected: number; lastInsertId: number }>('writer_execute', {
        db: dbName,
        sql,
        values: params,
      }),
    select: <T>(sql: string, params: unknown[] = []) =>
      invoke<T>('writer_select', { db: dbName, sql, values: params }),
  };
}
