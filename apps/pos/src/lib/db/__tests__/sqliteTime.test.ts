import { describe, expect, it } from 'vitest';
import { toSqliteUtc } from '../sqliteTime';

/**
 * offline_receipts.created_at (and every other DEFAULT (datetime('now'))
 * column) stores `YYYY-MM-DD HH:MM:SS` UTC with a SPACE separator. Shift
 * timestamps arrive as ISO 8601 with a `T` separator (server Carbon
 * `+00:00`, or JS `toISOString()` `.000Z`). SQLite compares TEXT
 * lexicographically, and `' ' < 'T'`, so binding an ISO timestamp against
 * a datetime('now') column silently excludes every same-day row. Every
 * JS-supplied boundary must be normalized through toSqliteUtc first.
 */
describe('toSqliteUtc', () => {
  it('converts a JS toISOString() value (millis + Z)', () => {
    expect(toSqliteUtc('2026-06-12T08:54:51.000Z')).toBe('2026-06-12 08:54:51');
  });

  it('converts a Carbon toIso8601String() value (+00:00 offset)', () => {
    expect(toSqliteUtc('2026-06-12T08:54:51+00:00')).toBe('2026-06-12 08:54:51');
  });

  it('converts a non-UTC offset to UTC', () => {
    expect(toSqliteUtc('2026-06-12T09:54:51+01:00')).toBe('2026-06-12 08:54:51');
  });

  it('passes through a value already in SQLite UTC format', () => {
    expect(toSqliteUtc('2026-06-12 08:54:51')).toBe('2026-06-12 08:54:51');
  });

  it('normalized output sorts correctly against datetime(\'now\') strings', () => {
    const receiptCreatedAt = '2026-06-12 17:03:08'; // datetime('now') format
    const shiftOpenedAt = toSqliteUtc('2026-06-12T08:54:51+00:00');
    // The exact comparison SQLite performs: receipt must be >= shift open.
    expect(receiptCreatedAt >= shiftOpenedAt).toBe(true);
  });

  it('throws on an unparseable timestamp', () => {
    expect(() => toSqliteUtc('not-a-date')).toThrow();
  });

  it('throws on an empty string', () => {
    expect(() => toSqliteUtc('')).toThrow();
  });
});
