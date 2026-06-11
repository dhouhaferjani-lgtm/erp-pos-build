/**
 * Shift receipt anchors (M3 — 2026-06-09 Z-report audit).
 *
 * Records the terminal's receipt `hash_sequence` at shift-open time so the
 * device Z can select the shift's receipts by monotonic, clock-rollback-immune
 * sequence (`hash_sequence > opening_hash_sequence`) instead of wall-clock
 * `created_at`. Without this, a device clock rollback during a shift could push
 * a receipt's created_at before the shift open and silently drop it from the
 * SIGNED Z totals.
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface ShiftReceiptAnchor {
  shift_id: string;
  opening_hash_sequence: number;
}

/**
 * Record the opening anchor for a shift. Idempotent on shift_id
 * (ON CONFLICT DO NOTHING) — re-opening/re-authoring must not overwrite the
 * original anchor, which would move the Z window.
 */
export async function insertShiftReceiptAnchor(
  db: Database,
  anchor: ShiftReceiptAnchor,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO shift_receipt_anchors (shift_id, opening_hash_sequence)
     VALUES ($1, $2)
     ON CONFLICT(shift_id) DO NOTHING`,
    [anchor.shift_id, anchor.opening_hash_sequence],
  );
}

/** The opening anchor for a shift, or null when none was recorded (legacy shift). */
export async function getShiftReceiptAnchor(
  db: Database,
  shiftId: string,
): Promise<ShiftReceiptAnchor | null> {
  const rows = await queryAll<ShiftReceiptAnchor>(
    db,
    `SELECT shift_id, opening_hash_sequence
     FROM shift_receipt_anchors
     WHERE shift_id = $1`,
    [shiftId],
  );
  return rows[0] ?? null;
}
