/**
 * Gate r1 (R1-3) — the offline TTL on cached operator authority.
 */
import { describe, expect, it } from 'vitest';
import {
  OPERATOR_AUTHORITY_MAX_AGE_MS,
  isOperatorAuthorityStale,
} from '../operatorAuthorityFreshness';
import { APPROVAL_CACHE_MAX_AGE_MS } from '@/lib/operatorApproval/approvalVerifier';
import { sqliteUtcToDate } from '@/lib/db/sqliteTime';

const NOW = Date.UTC(2026, 7, 26, 12, 0, 0);
const DAY = 24 * 60 * 60 * 1000;

/** `datetime('now')` format: UTC, SPACE separator (rule 20). */
function sqliteStamp(msAgo: number): string {
  return new Date(NOW - msAgo).toISOString().slice(0, 19).replace('T', ' ');
}

describe('isOperatorAuthorityStale', () => {
  it('reuses the approval-cache window rather than inventing a second one', () => {
    expect(OPERATOR_AUTHORITY_MAX_AGE_MS).toBe(APPROVAL_CACHE_MAX_AGE_MS);
    expect(OPERATOR_AUTHORITY_MAX_AGE_MS).toBe(7 * DAY);
  });

  it('is fresh inside the window', () => {
    expect(isOperatorAuthorityStale(sqliteStamp(0), NOW)).toBe(false);
    expect(isOperatorAuthorityStale(sqliteStamp(6 * DAY), NOW)).toBe(false);
    expect(isOperatorAuthorityStale(sqliteStamp(7 * DAY), NOW)).toBe(false);
  });

  it('is stale past the window', () => {
    expect(isOperatorAuthorityStale(sqliteStamp(7 * DAY + 1000), NOW)).toBe(true);
    expect(isOperatorAuthorityStale(sqliteStamp(30 * DAY), NOW)).toBe(true);
  });

  it('fails CLOSED on a missing or unparseable stamp', () => {
    expect(isOperatorAuthorityStale(null, NOW)).toBe(true);
    expect(isOperatorAuthorityStale(undefined, NOW)).toBe(true);
    expect(isOperatorAuthorityStale('', NOW)).toBe(true);
    expect(isOperatorAuthorityStale('not-a-date', NOW)).toBe(true);
  });

  it('tolerates ordinary forward skew but rejects a grossly future stamp', () => {
    expect(isOperatorAuthorityStale(sqliteStamp(-2 * 60 * 60 * 1000), NOW)).toBe(false);
    expect(isOperatorAuthorityStale(sqliteStamp(-5 * DAY), NOW)).toBe(true);
  });

  /**
   * Rule 20, pinned TIMEZONE-INDEPENDENTLY (gate r2, r2-5).
   *
   * `datetime('now')` writes UTC with a SPACE separator, and
   * `new Date('2026-08-26 12:00:00')` is parsed in the DEVICE timezone by
   * every JS engine. The earlier version of this case only went red for a
   * naive parse when the runner sat EAST of UTC — under `TZ=UTC`, which is
   * what CI uses and what `apps/pos` pins nowhere, a naive parse yields age 0
   * and the case passed anyway. So it did not guard the bug it named.
   *
   * Asserting the parse itself is offset-free bites in every timezone.
   */
  it('reads a SQLite space-separated UTC stamp as UTC, not as local time', () => {
    const justWritten = sqliteStamp(0);
    expect(justWritten).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    expect(sqliteUtcToDate(justWritten).getTime()).toBe(NOW);
    // And a naive `new Date()` on the same string is NOT the same instant
    // unless the runner happens to sit at UTC — which is exactly why the
    // helper is mandatory rather than incidental.
    const naive = new Date(justWritten).getTime();
    const offsetMs = new Date(NOW).getTimezoneOffset() * 60 * 1000;
    expect(naive).toBe(NOW + offsetMs);
  });

  /**
   * The authority stamp is `sync_metadata.operators_last_sync`, which
   * `pullOperatorPins` writes as `new Date().toISOString()` — so ISO is the
   * SHAPE PRODUCTION ACTUALLY WRITES here, and the SQLite shape above is the
   * defensive path.
   */
  it('accepts the ISO stamp the roster pull writes', () => {
    expect(isOperatorAuthorityStale(new Date(NOW).toISOString(), NOW)).toBe(false);
    expect(isOperatorAuthorityStale(new Date(NOW - 8 * DAY).toISOString(), NOW)).toBe(true);
  });
});
