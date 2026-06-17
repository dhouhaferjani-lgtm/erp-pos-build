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

function mapRow(row: LocalShiftRow): LocalShift {
  return { ...row, fiscal_shift_id: row.id };
}

const SELECT_COLUMNS =
  'id, terminal_id, session_id, shift_number, status, opening_cash, opened_at, closed_at, cashier_id, cashier_name';

/**
 * The next per-terminal shift number = `MAX(local MAX(shift_number), seed) + 1`
 * (1 when the terminal has never opened a shift and no seed is given). Must be
 * computed inside the same write transaction as the insert so two opens can't
 * race onto the same number.
 *
 * `seed` (offline-first shifts Phase 6.1) is the server's cached
 * `MAX(pos_shifts.shift_number)` (`getShiftNumberSeed`). On a freshly-installed
 * device `local_shifts` is empty, so the seed keeps numbering continuing from
 * the server's value rather than restarting at 1 and colliding with
 * server-projected numbers from a prior install. Defaults to 0 — the legacy
 * local-only behaviour — when the caller has no server history to seed from.
 */
export async function nextShiftNumber(
  db: Database,
  terminalId: string,
  seed = 0,
): Promise<number> {
  const row = await queryOne<{ max_number: number | null }>(
    db,
    `SELECT MAX(shift_number) AS max_number FROM local_shifts WHERE terminal_id = $1`,
    [terminalId],
  );
  return Math.max(row?.max_number ?? 0, seed) + 1;
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
