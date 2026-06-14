/**
 * Local shifts (offline-first shifts Phase 0 — 2026-06-14).
 *
 * Device-authoritative shift lifecycle, mirroring the receipt model. The
 * device mints a UUIDv7 shift id (== `fiscal_shift_id` == `pos_shifts.id`)
 * plus a per-terminal monotone `shift_number`, authors SESSION_OPEN locally,
 * and `pos_shifts` becomes a server-side projection. This table is the local
 * source of truth for "the current open shift" — read with no network.
 *
 * The one-open-per-terminal invariant is enforced by the partial unique index
 * `idx_local_shifts_one_open_per_terminal` (mirroring the server
 * `pos_shifts_one_open_per_terminal`). A second open attempt fails loud rather
 * than silently double-opening.
 *
 * SINGLE-WRITER NOTE: in production, `nextShiftNumber` + `insertLocalShift`
 * run inside the existing `withWriteTransaction('fiscal')` gate alongside the
 * SESSION_OPEN/OPENING_FLOAT fiscal authoring (Phase 1) — pass the gate's `tx`
 * handle as `db`. Never call a self-transacting wrapper inside another gate.
 */
import type Database from '@tauri-apps/plugin-sql';
import { execute, queryOne } from '@/lib/db';

export type LocalShiftStatus = 'OPEN' | 'CLOSED';

/** A row to insert; `status` defaults to OPEN, `closed_at` to NULL. */
export interface LocalShiftInput {
  id: string;
  terminal_id: string;
  session_id: string;
  shift_number: number;
  opening_cash: string;
  opened_at: string;
  cashier_id: string;
  cashier_name: string;
  status?: LocalShiftStatus;
  closed_at?: string | null;
}

interface LocalShiftRow {
  id: string;
  terminal_id: string;
  session_id: string;
  shift_number: number;
  status: LocalShiftStatus;
  opening_cash: string;
  opened_at: string;
  closed_at: string | null;
  cashier_id: string;
  cashier_name: string;
}

export interface LocalShift extends LocalShiftRow {
  /** Alias of `id` in the one-id model (== `fiscal_shift_id` == `pos_shifts.id`). */
  fiscal_shift_id: string;
}

/**
 * The pre-cutover cached shift shape (the `Shift` object stored under
 * `StorageKeys.SHIFT`). Used only by the one-time backfill.
 */
export interface CachedShiftInput {
  id: string;
  terminal_id: string;
  shift_number: number;
  status: LocalShiftStatus;
  opening_cash: string;
  opened_at: string;
  fiscal_shift_id?: string;
  fiscal_session_id?: string;
  user: { id: string; name: string };
}

const LOWER_HEX_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function isUuid(value: string | undefined): value is string {
  return value !== undefined && LOWER_HEX_UUID.test(value);
}

function mapRow(row: LocalShiftRow): LocalShift {
  return { ...row, fiscal_shift_id: row.id };
}

const SELECT_COLUMNS =
  'id, terminal_id, session_id, shift_number, status, opening_cash, opened_at, closed_at, cashier_id, cashier_name';

/**
 * The next per-terminal shift number = `MAX(shift_number) + 1` (1 when the
 * terminal has never opened a shift). Must be computed inside the same write
 * transaction as the insert so two opens can't race onto the same number.
 */
export async function nextShiftNumber(db: Database, terminalId: string): Promise<number> {
  const row = await queryOne<{ max_number: number | null }>(
    db,
    `SELECT MAX(shift_number) AS max_number FROM local_shifts WHERE terminal_id = $1`,
    [terminalId],
  );
  return (row?.max_number ?? 0) + 1;
}

/**
 * Insert a shift row. A plain INSERT (no ON CONFLICT): a second OPEN row for
 * the same terminal violates the partial unique index and throws — the
 * one-open invariant fails loud by design.
 */
export async function insertLocalShift(db: Database, shift: LocalShiftInput): Promise<void> {
  await execute(
    db,
    `INSERT INTO local_shifts
       (id, terminal_id, session_id, shift_number, status, opening_cash, opened_at, closed_at, cashier_id, cashier_name)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
    [
      shift.id,
      shift.terminal_id,
      shift.session_id,
      shift.shift_number,
      shift.status ?? 'OPEN',
      shift.opening_cash,
      shift.opened_at,
      shift.closed_at ?? null,
      shift.cashier_id,
      shift.cashier_name,
    ],
  );
}

/** Mark a shift CLOSED. Idempotent at the SQL level (re-close is a no-op UPDATE). */
export async function closeLocalShift(
  db: Database,
  shiftId: string,
  closedAt: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE local_shifts SET status = 'CLOSED', closed_at = $2 WHERE id = $1`,
    [shiftId, closedAt],
  );
}

/** The current open shift for the terminal, or null when none is open. */
export async function getCurrentOpenShift(
  db: Database,
  terminalId: string,
): Promise<LocalShift | null> {
  const row = await queryOne<LocalShiftRow>(
    db,
    `SELECT ${SELECT_COLUMNS} FROM local_shifts WHERE terminal_id = $1 AND status = 'OPEN' LIMIT 1`,
    [terminalId],
  );
  return row ? mapRow(row) : null;
}

/**
 * One-time copy of the pre-cutover cached open shift into `local_shifts`, so
 * the SQLite-first `fetchCurrentShift` (Phase 1) finds the in-flight shift
 * after the device becomes shift-authoritative. Returns true when a row was
 * inserted, false when there was nothing to copy (null cache, a CLOSED cached
 * shift, or an open shift already present for the terminal — idempotent).
 */
export async function backfillLocalShiftFromCache(
  db: Database,
  cached: CachedShiftInput | null,
): Promise<boolean> {
  if (!cached || cached.status !== 'OPEN') return false;

  // `local_shifts.id` is the canonical fiscal shift UUID (== fiscal_shift_id ==
  // pos_shifts.id). A grandfathered pre-cutover offline shift has id
  // `offline-<uuid>` (NOT a UUID) but a valid `fiscal_shift_id`; copying the
  // offline- id verbatim would later author SESSION_CLOSE with a non-UUID
  // shift_id, quarantine the close server-side, and wedge the terminal (the
  // one-open partial unique then blocks reopening). Resolve to a valid UUID;
  // if none is available the cache can't be safely adopted — skip and let the
  // shift close via its legacy path.
  const id = isUuid(cached.id) ? cached.id : isUuid(cached.fiscal_shift_id) ? cached.fiscal_shift_id : null;
  if (id === null) return false;
  const sessionId = isUuid(cached.fiscal_session_id) ? cached.fiscal_session_id : id;

  // Never clobber an existing open shift for this terminal (the device may
  // have already authored one post-cutover). This guard also makes the
  // backfill idempotent across boots.
  const existing = await getCurrentOpenShift(db, cached.terminal_id);
  if (existing) return false;

  await execute(
    db,
    `INSERT INTO local_shifts
       (id, terminal_id, session_id, shift_number, status, opening_cash, opened_at, closed_at, cashier_id, cashier_name)
     VALUES ($1, $2, $3, $4, 'OPEN', $5, $6, NULL, $7, $8)
     ON CONFLICT(id) DO NOTHING`,
    [
      id,
      cached.terminal_id,
      sessionId,
      cached.shift_number,
      cached.opening_cash,
      cached.opened_at,
      cached.user.id,
      cached.user.name,
    ],
  );
  return true;
}
