import type Database from '@tauri-apps/plugin-sql';
import { sqliteUtcToDate } from '@/lib/db/sqliteTime';
import { getSyncMetadata } from '@/lib/db/repositories/syncLogRepository';
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
 * `sync_metadata` key holding the last SUCCESSFUL operator-roster pull.
 *
 * Gate r2 (R2-1): this — not `operator_pins.synced_at` — is the authority
 * clock. `synced_at` is a row-level "last write" marker that two
 * discount-permission writers also bump, and one of them
 * (`updateOperatorDiscountPermissions`, via `resolveOnlineDiscountPermissions`)
 * fires on EVERY offline-accepted PIN verify. Dating a security TTL from it
 * meant a device that is online but whose roster pull is broken — pin-data 403
 * after the device account loses `pos.operate_terminal`, or a persistently
 * failing sync, both of which `pullOperatorPins` swallows and returns 0 for —
 * reset its authority clock from an endpoint that carries no authority, and a
 * demoted manager's cached roles stayed "fresh" forever.
 *
 * `pullOperatorPins` writes this key only AFTER `upsertOperators` succeeds, so
 * it moves if and only if roles and permissions were actually re-read from the
 * server. Written as an ISO 8601 instant (`new Date().toISOString()`).
 */
export const OPERATOR_ROSTER_SYNC_KEY = 'operators_last_sync';

/**
 * The instant the operator roster was last confirmed by the server, or `null`
 * if it never was (or cannot be read).
 *
 * Fails CLOSED by returning `null`, which `isOperatorAuthorityStale` reads as
 * stale: a device whose metadata table will not answer is precisely one whose
 * cached authority should not be trusted.
 */
export async function readOperatorAuthoritySyncedAt(db: Database): Promise<string | null> {
  try {
    return await getSyncMetadata(db, OPERATOR_ROSTER_SYNC_KEY);
  } catch {
    return null;
  }
}

/**
 * True when the cached operator authority is older than the TTL (or its stamp
 * is missing, unparseable, or grossly future-dated).
 *
 * Fails CLOSED — an unreadable stamp is treated as stale, because the whole
 * point is to stop trusting authority we cannot date.
 *
 * Rule 20: the roster stamp is written ISO, but this reader must survive both
 * shapes — a SQLite `datetime('now')` value is `YYYY-MM-DD HH:MM:SS` UTC with
 * a SPACE separator, and `new Date()` on that string is interpreted in the
 * DEVICE timezone by every JS engine, which would shift the age by the local
 * UTC offset (and, west of Greenwich, make a stamp look future-dated).
 * `sqliteUtcToDate` handles both and is the required reader.
 *
 * Gate r2 (r2-4), accepted: the verdict is a SNAPSHOT taken when the PIN is
 * verified and then carried on the in-memory operator, so a session that is
 * never locked and re-verified keeps its verdict past the 7-day boundary. It
 * is bounded in practice by the inactivity lock, and the other direction is
 * conservative in the same way — a stale verdict is not cleared by a later
 * successful pull either, until the next verify.
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
