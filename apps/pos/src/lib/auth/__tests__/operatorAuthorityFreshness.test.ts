/**
 * Gate r1 (R1-3) — the offline TTL on cached operator authority.
 */
import { describe, expect, it } from 'vitest';
import {
  OPERATOR_AUTHORITY_MAX_AGE_MS,
  isOperatorAuthorityStale,
} from '../operatorAuthorityFreshness';
import { APPROVAL_CACHE_MAX_AGE_MS } from '@/lib/operatorApproval/approvalVerifier';

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
   * Rule 20. `datetime('now')` writes UTC with a SPACE separator, and
   * `new Date('2026-08-26 12:00:00')` is parsed in the DEVICE timezone. Reading
   * it without `sqliteUtcToDate` shifts every age by the local UTC offset —
   * which, west of Greenwich, makes a just-written stamp look FUTURE-dated.
   */
  it('reads the SQLite space-separated UTC stamp as UTC, not as local time', () => {
    const justWritten = sqliteStamp(0);
    expect(justWritten).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    // A naive parse would be off by the device offset; the helper must land
    // exactly on `now` and therefore read as fresh at the very edge.
    expect(isOperatorAuthorityStale(justWritten, NOW, 1000)).toBe(false);
  });

  it('still accepts an ISO stamp (the online verify path writes one)', () => {
    expect(isOperatorAuthorityStale(new Date(NOW).toISOString(), NOW)).toBe(false);
  });
});
