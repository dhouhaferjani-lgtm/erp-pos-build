/**
 * Bug 5 anchor — extract a useful message from any throwable for persisting
 * to `sync_error` columns and sync_log rows.
 *
 * The Tauri `@tauri-apps/plugin-sql` plugin throws SQLite failures as raw
 * strings, not `Error` instances ("error returned from database: (code: 5)
 * database is locked"). The previous ternary
 * `error instanceof Error ? error.message : 'Unknown error'` collapsed every
 * non-Error throwable to the literal string `'Unknown error'`, which:
 *   - destroyed the lock signature needed by migration v32 recovery, and
 *   - obscured root-cause analysis for every other plugin-level failure.
 *
 * This coercer preserves raw strings verbatim, JSON-stringifies plain
 * objects, and falls back to `String()` for everything else. Cyclical
 * objects (whose JSON.stringify throws) drop back to `String()` too.
 */
export function coerceSyncError(error: unknown): string {
  if (error instanceof Error) return error.message;
  if (typeof error === 'string') return error;
  if (error !== null && typeof error === 'object') {
    try {
      return JSON.stringify(error);
    } catch {
      return String(error);
    }
  }
  return String(error);
}
