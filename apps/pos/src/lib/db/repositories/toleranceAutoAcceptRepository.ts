import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

/**
 * Durable per-shift tender-tolerance auto-accept budget (spec §8.1, owner
 * ruling 2026-07-29).
 *
 * SQLite is the AUTHORITY. `paymentStore` keeps an in-memory mirror so the cash
 * screen can decide synchronously what to show, but every gate decision reads
 * through to this table: the in-memory copy is cleared by
 * `teardownPosSessionStores()` (one tap away in Settings) and by any app
 * restart, both of which would otherwise refill the budget on an open shift.
 *
 * Task 10's EOD preview reads {@link getToleranceAutoAcceptCount} for the shift
 * it is closing.
 */

/** Accepts already spent on `shiftId`. Zero when the shift has spent none. */
export async function getToleranceAutoAcceptCount(
  db: Database,
  shiftId: string,
): Promise<number> {
  const rows = await queryAll<{ accept_count: number }>(
    db,
    'SELECT accept_count FROM tolerance_auto_accepts WHERE shift_id = $1',
    [shiftId],
  );
  const count = rows[0]?.accept_count;
  // A corrupt/absent value must never read as "budget available".
  return typeof count === 'number' && Number.isFinite(count) && count > 0 ? count : 0;
}

/**
 * Charge one accept against `shiftId` and return the new count.
 *
 * The increment happens INSIDE SQLite (`accept_count + 1` in the upsert), not
 * read-modify-write in JS, so two overlapping checkouts on the same shift
 * cannot both read N and both write N+1.
 */
export async function recordToleranceAutoAccept(
  db: Database,
  shiftId: string,
): Promise<number> {
  await execute(
    db,
    `INSERT INTO tolerance_auto_accepts (shift_id, accept_count, updated_at)
     VALUES ($1, 1, datetime('now'))
     ON CONFLICT(shift_id) DO UPDATE SET
       accept_count = tolerance_auto_accepts.accept_count + 1,
       updated_at   = datetime('now')`,
    [shiftId],
  );
  return getToleranceAutoAcceptCount(db, shiftId);
}
