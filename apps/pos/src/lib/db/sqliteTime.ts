/**
 * Timestamp normalization for SQLite TEXT comparisons.
 *
 * Columns declared `DEFAULT (datetime('now'))` (offline_receipts.created_at
 * et al.) store `YYYY-MM-DD HH:MM:SS` in UTC with a SPACE separator. Shift
 * and report boundaries arrive as ISO 8601 with a `T` separator — server
 * Carbon emits `2026-06-12T08:54:51+00:00`, JS `toISOString()` emits
 * `2026-06-12T08:54:51.000Z`. SQLite compares TEXT lexicographically and
 * `' ' (0x20) < 'T' (0x54)`, so binding an ISO string against a
 * datetime('now') column silently excludes every row from the same UTC day.
 *
 * Any JS-supplied timestamp used in a WHERE comparison against a
 * datetime('now') column MUST pass through {@link toSqliteUtc} first, and any
 * such column READ BACK for display MUST pass through {@link sqliteUtcToDate}.
 */

const SQLITE_UTC_FORMAT = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

/**
 * Normalize an ISO 8601 timestamp (any offset) to SQLite's
 * `YYYY-MM-DD HH:MM:SS` UTC format. Values already in that format pass
 * through unchanged (re-parsing them via `new Date()` would wrongly apply
 * the device's local timezone).
 */
export function toSqliteUtc(timestamp: string): string {
  if (SQLITE_UTC_FORMAT.test(timestamp)) return timestamp;

  const parsed = new Date(timestamp);
  if (Number.isNaN(parsed.getTime())) {
    throw new Error(`toSqliteUtc: unparseable timestamp "${timestamp}"`);
  }
  return parsed.toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Read a `datetime('now')` TEXT column back as an instant.
 *
 * The inverse of {@link toSqliteUtc}, and the same rule-20 hazard in the other
 * direction: `new Date('2026-08-21 10:05:00')` is interpreted in the DEVICE
 * timezone by every JS engine, so rendering a stored value without this helper
 * silently shifts every displayed timestamp by the local UTC offset. Values
 * already carrying an explicit offset (ISO 8601) are parsed as-is.
 */
export function sqliteUtcToDate(timestamp: string): Date {
  if (SQLITE_UTC_FORMAT.test(timestamp)) {
    return new Date(`${timestamp.replace(' ', 'T')}Z`);
  }
  return new Date(timestamp);
}
