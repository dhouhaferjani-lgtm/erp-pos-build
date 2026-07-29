import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

export type OfflineReceiptStatus = 'pending' | 'syncing' | 'synced' | 'failed';

// Module-level debounce handle — one pending sync at most.
let pendingSyncTimer: ReturnType<typeof setTimeout> | null = null;

// T2.2 Step 5.1: exported so the service layer can fire it AFTER
// db.execute('COMMIT'). The trigger used to live inside insertOfflineReceipt
// (i.e. inside the BEGIN/COMMIT window) which let the debounced syncStore
// read race the COMMIT under load. Callers must invoke this only after the
// transaction has durably committed.
export function scheduleDebouncedSync(): void {
  if (pendingSyncTimer !== null) {
    clearTimeout(pendingSyncTimer);
  }
  pendingSyncTimer = setTimeout(() => {
    pendingSyncTimer = null;
    // Lazy import to avoid circular dep with syncScheduler (which imports repositories).
    import('@/stores/syncStore')
      .then((mod) => mod.useSyncStore.getState().triggerSync())
      .catch((err: unknown) => {
        console.warn('[fiscal] debounced sync trigger failed to load', err);
      });
  }, 250);
}

export interface OfflineReceipt {
  id: string;
  idempotency_key: string;
  receipt_number: string;
  terminal_id: string;
  terminal_code: string;
  operator_id: string;
  operator_name: string;
  lines: string; // JSON
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  total: string;
  currency: string;
  fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  transaction_discount_amount: string | null;
  transaction_discount_reason: string | null;
  tendered_amount: string | null;
  change_due: string | null;
  payment_method_id: string;
  payment_repository_id: string;
  status: OfflineReceiptStatus;
  retry_count: number;
  /** JSON-encoded array of {payment_method_id, repository_id, amount, card_last_four?, transaction_reference?} */
  payments_json: string;
  consumption_mode: string | null;
  table_id: string | null;
  /**
   * v3 signed rounding mirror (spec §4.3), at currency scale. Null — not a
   * canonical zero — on an unrounded receipt, so "rounding did not apply" and
   * "rounding applied and netted to zero" stay distinguishable locally.
   */
  cash_rounding_adjustment: string | null;
  cash_rounding_denomination: string | null;
  /** Local auto-accepted tender shortfall. Null when no tolerance was applied. */
  tolerance_shortfall: string | null;
  canonical_bytes?: string | null;
  /** Set after first successful sync; null until then. */
  server_receipt_id: string | null;
  /**
   * Fiscal hash schema version (2 | 3) this receipt was sealed under at the
   * moment of insert. Sent unchanged in the sync payload so the server can
   * hard-reject if the terminal's current version drifted (Codex review B1).
   */
  fiscal_schema_version: 2 | 3;
  /**
   * T2.7 — when the cashier sealed this receipt against a terminal in
   * training mode. SQLite-native 0 or 1 (mirrors `voided`); the wire-shape
   * builder converts to a boolean before sending. Training receipts skip the
   * local fiscal-hash chain advance and persist with a placeholder fiscal
   * hash; the server-side sync ingest path (PR #103) honors the flag and
   * skips chain validation, year roll-over, finalize, and hash mismatch.
   */
  is_training: 0 | 1;
  created_at: string;
  synced_at: string | null;
  sync_error: string | null;
}

export async function insertOfflineReceipt(
  db: Database,
  receipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'>,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO offline_receipts (
      id, idempotency_key, receipt_number, terminal_id, terminal_code,
      operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
      total, currency, fiscal_hash, previous_hash, hash_sequence,
      transaction_discount_amount, transaction_discount_reason,
      tendered_amount, change_due, payment_method_id, payment_repository_id, status,
      payments_json, consumption_mode, table_id, fiscal_schema_version, is_training, canonical_bytes,
      cash_rounding_adjustment, cash_rounding_denomination, tolerance_shortfall
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25, $26, $27, $28, $29, $30, $31, $32)`,
    [
      receipt.id, receipt.idempotency_key, receipt.receipt_number,
      receipt.terminal_id, receipt.terminal_code,
      receipt.operator_id, receipt.operator_name,
      receipt.lines, receipt.subtotal, receipt.tax_amount, receipt.discount_amount,
      receipt.total, receipt.currency, receipt.fiscal_hash, receipt.previous_hash,
      receipt.hash_sequence, receipt.transaction_discount_amount,
      receipt.transaction_discount_reason, receipt.tendered_amount,
      receipt.change_due, receipt.payment_method_id, receipt.payment_repository_id,
      receipt.status,
      receipt.payments_json, receipt.consumption_mode, receipt.table_id,
      receipt.fiscal_schema_version, receipt.is_training, receipt.canonical_bytes ?? null,
      receipt.cash_rounding_adjustment ?? null,
      receipt.cash_rounding_denomination ?? null,
      receipt.tolerance_shortfall ?? null,
    ]
  );
  // T2.2 Step 5.1: this function is now transactionally pure. The
  // scheduleDebouncedSync trigger has moved to receiptService.createOfflineReceipt,
  // fired after `db.execute('COMMIT')`, so the scheduler never reads SQLite
  // before the commit has durably persisted.
}

export async function getPendingReceipts(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    "SELECT * FROM offline_receipts WHERE status = 'pending' ORDER BY hash_sequence ASC"
  );
}

// TODO(go-live-followup): stranded-receipt operator/admin UI (Phase 0
//   deferred). Today a 'failed' row is visible in the badge count but has no
//   surface for an operator to inspect, retry, or void. Pre-go-live this is
//   acceptable because failures are rare and the cashier can rely on the next
//   automatic retry; post-go-live we want a manager-screen tile listing
//   stranded receipts with their last sync error and a retry/abandon action.
//   See docs/superpowers/plans/2026-05-09-pos-t2.2-crash-safety-small-wins-kickoff-prompt.md
//   Section 3 Step 5.3 row 6.
export async function getPendingReceiptCount(db: Database): Promise<number> {
  const result = await queryOne<{ count: number }>(
    db,
    "SELECT COUNT(*) as count FROM offline_receipts WHERE status IN ('pending', 'failed')"
  );
  return result?.count ?? 0;
}

/**
 * T2.1 Step D — boot-time recovery for stranded `'syncing'` rows.
 *
 * `syncService.updateReceiptStatus(... 'syncing')` advances a receipt's
 * status BEFORE the sync HTTP call completes. On success the response
 * handler advances to `'synced'`; on failure to `'failed'`. A
 * SIGKILL / power-cut / OS-level kill BETWEEN the status update and
 * the response handler leaves the row at `'syncing'` permanently —
 * `getPendingReceiptsForSync` filters `WHERE status IN ('pending',
 * 'failed')`, so the orphan is invisible to every subsequent retry.
 * Net effect: a fiscal record with a hash-chain advance but no
 * server-side counterpart, never retried, eventually visible only via
 * `php artisan pos:verify-chains` as a chain break.
 *
 * This recovery demotes any `'syncing'` row back to `'pending'` so the
 * next sync tick re-attempts it. Idempotent across multiple boots —
 * subsequent calls find no `'syncing'` rows and are no-ops.
 *
 * Idempotency reasoning: a row at `'syncing'` is in one of three
 * states post-crash:
 *   1. HTTP request never left the device → server has nothing →
 *      retry as fresh send. ✓
 *   2. HTTP request succeeded server-side but response was lost →
 *      retry → server detects via T0.2 idempotency_key and returns
 *      the prior result → client advances to `'synced'`. ✓
 *   3. HTTP request succeeded AND response landed but the local
 *      status update failed → retry → same as (2). ✓
 *
 * Returns the number of rows demoted (for boot-time observability).
 */
export async function recoverStrandedSyncingReceipts(
  db: Database,
): Promise<number> {
  const result = await execute(
    db,
    "UPDATE offline_receipts SET status = 'pending' WHERE status = 'syncing'",
  );
  return result.rowsAffected;
}

export async function updateReceiptStatus(
  db: Database,
  id: string,
  status: OfflineReceiptStatus,
  syncError?: string,
): Promise<void> {
  if (status === 'synced') {
    await execute(
      db,
      "UPDATE offline_receipts SET status = $1, synced_at = datetime('now'), sync_error = NULL WHERE id = $2",
      [status, id]
    );
  } else {
    await execute(
      db,
      'UPDATE offline_receipts SET status = $1, sync_error = $2 WHERE id = $3',
      [status, syncError ?? null, id]
    );
  }
}

export const MAX_SYNC_RETRIES = 5;

export async function getPendingReceiptsForSync(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE status IN ('pending', 'failed') AND retry_count < $1
     ORDER BY hash_sequence ASC`,
    [MAX_SYNC_RETRIES]
  );
}

/**
 * Task 10 (location-aware stock) — `lines` JSON blobs of every receipt the
 * server has NOT yet acknowledged, for the availability selector's
 * pending-sale subtraction (spec §4.4).
 *
 * Predicate notes:
 * - `status != 'synced'` (NOT the drain's `IN ('pending','failed') AND
 *   retry_count < MAX`): a `'syncing'` row and a stuck `'failed'` row are
 *   both fiscally sealed sales the server snapshot cannot reflect yet, so
 *   they must still subtract from availability.
 * - `voided = 0`: a sealed-then-voided receipt's sale was reversed.
 * - `is_training = 0`: training receipts never move real stock.
 *
 * Refund/return records live in the separate `local_refund_records` table
 * and are deliberately NOT read here — refunds never alter availability.
 */
export async function getUnsyncedReceiptLineBlobs(db: Database): Promise<string[]> {
  const rows = await queryAll<{ lines: string }>(
    db,
    `SELECT lines FROM offline_receipts
     WHERE status != 'synced' AND voided = 0 AND is_training = 0
     ORDER BY hash_sequence ASC`,
  );
  return rows.map((row) => row.lines);
}

export async function incrementRetryCount(db: Database, id: string): Promise<void> {
  await execute(
    db,
    'UPDATE offline_receipts SET retry_count = retry_count + 1 WHERE id = $1',
    [id]
  );
}

export async function getStuckReceipts(db: Database): Promise<OfflineReceipt[]> {
  return queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE status = 'failed' AND retry_count >= $1
     ORDER BY hash_sequence ASC`,
    [MAX_SYNC_RETRIES]
  );
}

export async function getReceiptByIdempotencyKey(
  db: Database,
  key: string,
): Promise<OfflineReceipt | null> {
  return queryOne<OfflineReceipt>(
    db,
    'SELECT * FROM offline_receipts WHERE idempotency_key = $1',
    [key]
  );
}

/**
 * Phase 1: retain all synced receipts within the current fiscal year (calendar
 * year for now). Phase 2 will read fiscal_year.start_date from companyConfig
 * and gate the boundary per tenant — most retail tenants in France use the
 * calendar year as their fiscal year, so calendar-year boundaries are correct
 * for the launch wave.
 *
 * At fiscal-year close, an archival flow (NF525-compliant long-term storage)
 * will migrate the prior year's receipts off the local SQLite and we'll
 * re-introduce a pruning boundary aligned with that flow.
 *
 * The retention anchor is `created_at` (the moment of fiscal posting on this
 * terminal), not `synced_at` — the fiscal year boundary cares about the date
 * the receipt was issued, not when it was uploaded to the server.
 *
 * SQLite `datetime('now', 'start of year')` resolves to January 1 of the
 * current year at 00:00:00 UTC. Because `created_at` is stored as ISO 8601
 * TEXT and 'YYYY-MM-DD HH:MM:SS' both compare lexicographically AND
 * chronologically, the strict-less-than comparison is correct.
 */
export async function cleanupSyncedReceipts(db: Database): Promise<void> {
  await execute(
    db,
    "DELETE FROM offline_receipts WHERE status = 'synced' AND created_at < datetime('now', 'start of year')"
  );
}

/**
 * Garbage-collect terminally-stuck offline receipts (failed, retries
 * exhausted, older than 90 days).
 *
 * KNOWN RESIDUAL (FU-4) — phantom availability: the location-stock
 * availability selector subtracts unsynced offline-receipt lines directly
 * (via {@see getUnsyncedReceiptLineBlobs}); there is no separate deductions
 * store. Deleting a stuck receipt here therefore REMOVES its deduction, which
 * can transiently resurrect `effectiveAvailable` — showing as available stock
 * that was sold locally but never confirmed to the server.
 *
 * This is an ACCEPTED residual, bounded by design:
 *   - it only affects receipts that failed to sync for 90 days AND exhausted
 *     retries — a deep edge case;
 *   - the server never ingested the sale, so its own stock never reflected the
 *     deduction either; the local figure simply re-aligns to the server's.
 *
 * If a stock re-pull gate is ever added to this cleanup, the deductions must be
 * cleared ATOMICALLY with the pull completing (pull, then delete — never the
 * reverse) so the window above never opens.
 */
export async function cleanupStuckReceipts(db: Database): Promise<void> {
  await execute(
    db,
    `DELETE FROM offline_receipts
     WHERE status = 'failed' AND retry_count >= $1
     AND created_at < datetime('now', '-90 days')`,
    [MAX_SYNC_RETRIES]
  );
}

export async function setServerReceiptId(
  db: Database,
  idempotencyKey: string,
  serverReceiptId: string,
): Promise<void> {
  await execute(
    db,
    'UPDATE offline_receipts SET server_receipt_id = $1 WHERE idempotency_key = $2',
    [serverReceiptId, idempotencyKey]
  );
}

export async function getOfflineReceiptById(
  db: Database,
  id: string,
): Promise<OfflineReceipt | null> {
  return queryOne<OfflineReceipt>(
    db,
    'SELECT * FROM offline_receipts WHERE id = $1',
    [id]
  );
}

export async function getLastSyncedReceiptNumber(db: Database): Promise<string | null> {
  const row = await queryOne<{ receipt_number: string }>(
    db,
    "SELECT receipt_number FROM offline_receipts WHERE status = 'synced' ORDER BY hash_sequence DESC LIMIT 1",
  );
  return row?.receipt_number ?? null;
}
