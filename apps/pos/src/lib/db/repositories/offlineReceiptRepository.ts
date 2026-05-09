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
  /** Set after first successful sync; null until then. */
  server_receipt_id: string | null;
  /**
   * Fiscal hash schema version (2 | 3) this receipt was sealed under at the
   * moment of insert. Sent unchanged in the sync payload so the server can
   * hard-reject if the terminal's current version drifted (Codex review B1).
   */
  fiscal_schema_version: 2 | 3;
  created_at: string;
  synced_at: string | null;
  sync_error: string | null;
}

// TODO(go-live-followup): optimistic +1 increment of pendingReceiptCount on
//   insert (deferred from T1.3 PR #90). Today the badge hydrates from SQLite
//   after each sync tick (~60 s interval), so an insert is invisible in the
//   header until the next tick. An optimistic +1 here would close that
//   visible delay; the next SQLite-sourced hydration corrects any drift.
//   Hooks needed: this function (insert success → +1) and the rollback path
//   in receiptService (compensating −1, but only if we got to +1 first). See
//   docs/superpowers/plans/2026-05-08-pos-t1.3-sync-indicator-truthfulness-kickoff-prompt.md
//   Section 3 Step 4.1.
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
      payments_json, consumption_mode, table_id, fiscal_schema_version
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25, $26, $27)`,
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
      receipt.fiscal_schema_version,
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
//   See docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md
//   §"Out of scope".
export async function getPendingReceiptCount(db: Database): Promise<number> {
  const result = await queryOne<{ count: number }>(
    db,
    "SELECT COUNT(*) as count FROM offline_receipts WHERE status IN ('pending', 'failed')"
  );
  return result?.count ?? 0;
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
