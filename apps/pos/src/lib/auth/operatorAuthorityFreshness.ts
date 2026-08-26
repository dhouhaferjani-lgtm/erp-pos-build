import { sqliteUtcToDate } from '@/lib/db/sqliteTime';
import { APPROVAL_CACHE_MAX_AGE_MS } from '@/lib/operatorApproval/approvalVerifier';

/**
 * Offline TTL for a PIN operator's CACHED authority (roles + permissions).
 *
 * Gate r1 (R1-3). Offline PIN verification accepts on a bcrypt match against
 * `operator_pins` and lifts the cached roles/permissions straight into the
 * operator store with no freshness check. `pullOperatorPins` refreshes that row
 * only while the device is online, and `pruneOperatorsExcept` removes DELETED
 * operators but not DEMOTED ones — so a demoted manager on a terminal that
 * never regains connectivity kept manager access indefinitely.
 *
 * Before B-13 that mattered less: the login user's roles carried the gate on
 * the ordinary terminal. After B-13 the cached operator authority is the SOLE
 * basis for every manager gate in the POS, which makes this value load-bearing
 * and its unbounded staleness a real regression in effective freshness.
 *
 * Reuses `APPROVAL_CACHE_MAX_AGE_MS` (7 days) deliberately rather than picking
 * a second number: the offline approval-scope cache one layer over solves the
 * identical hazard with the identical trade-off (a shop must keep trading
 * through a multi-day outage; authority must not be indefinite), and two
 * different windows for "how long may a device trust cached authority" would
 * be an accident waiting to be explained.
 *
 * Past the TTL only the MANAGER surfaces close. Selling, refunds, and the
 * operator's own shift close are unaffected — the till keeps working.
 */
export const OPERATOR_AUTHORITY_MAX_AGE_MS = APPROVAL_CACHE_MAX_AGE_MS;

/**
 * Maximum tolerated FORWARD skew on the sync stamp, mirroring
 * `APPROVAL_CACHE_MAX_FUTURE_SKEW_MS`: a stamp written implausibly far in the
 * future (a device clock wound forward, or a tampered SQLite row) would
 * otherwise keep the cache "fresh" forever.
 */
export const OPERATOR_AUTHORITY_MAX_FUTURE_SKEW_MS = 24 * 60 * 60 * 1000;

/**
 * True when a cached operator row's authority is older than the TTL (or its
 * stamp is missing, unparseable, or grossly future-dated).
 *
 * Fails CLOSED — an unreadable stamp is treated as stale, because the whole
 * point is to stop trusting authority we cannot date.
 *
 * Rule 20: `synced_at` is written `datetime('now')`, i.e.
 * `YYYY-MM-DD HH:MM:SS` UTC with a SPACE separator. `new Date()` on that
 * string is interpreted in the DEVICE timezone by every JS engine, which would
 * shift the age by the local UTC offset (and, west of Greenwich, make a stamp
 * look future-dated). `sqliteUtcToDate` is the required reader.
 */
export function isOperatorAuthorityStale(
  syncedAt: string | null | undefined,
  now: number,
  maxAgeMs: number = OPERATOR_AUTHORITY_MAX_AGE_MS,
): boolean {
  if (!syncedAt) return true;

  const syncedMs = sqliteUtcToDate(syncedAt).getTime();
  if (Number.isNaN(syncedMs)) return true;

  const age = now - syncedMs;
  // age < 0 ⇒ the stamp is in the future; tolerate ordinary clock skew, reject
  // gross forward-dating.
  if (age < -OPERATOR_AUTHORITY_MAX_FUTURE_SKEW_MS) return true;

  return age > maxAgeMs;
}
