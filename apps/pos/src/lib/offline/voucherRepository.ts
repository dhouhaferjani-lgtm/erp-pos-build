/**
 * Local SQLite mirror for vouchers + voucher_ledger + receipt_qr_index.
 *
 * Phase 1 contract (POS Refund Flow plan §0.2):
 *   "Every customer-facing operation … reads from and writes to the local
 *    SQLite database first, every time, regardless of whether the internet
 *    is available."
 *
 * The lookup helpers in this file (`findByCode`, `findReceiptByQrToken`,
 * `findReceiptByNumber`) MUST NOT touch the network. They are the local-first
 * answer-path used by Task 50 (scan dispatcher), Task 52 (returns home),
 * and Task 53 (voucher tender). The companion sync functions in
 * `@/lib/sync/syncService` push and pull this mirror on a separate timeline.
 *
 * Do NOT import `apiGet`, `apiPost`, or `@tauri-apps/plugin-http` from here.
 */

import type Database from '@tauri-apps/plugin-sql';
import { queryOne, queryAll, execute } from '@/lib/db';

// ─── Types ──────────────────────────────────────────────────────────────────

export type VoucherStatus =
  | 'Issued'
  | 'PartiallyRedeemed'
  | 'FullyRedeemed'
  | 'Expired'
  | 'Voided';

export type VoucherRedemptionMode = 'Bearer' | 'CustomerBound';

export type VoucherSource =
  | 'Refund'
  | 'ExchangeSurplus'
  | 'Goodwill'
  | 'LoyaltyCredit'
  | 'GiftCardPurchase'
  | 'Promotional';

export type VoucherLedgerEvent =
  | 'Issued'
  | 'Redeemed'
  | 'PartiallyRedeemed'
  | 'Expired'
  | 'Voided'
  | 'Transferred'
  | 'Reversed';

export type LedgerSyncStatus = 'synced' | 'pending' | 'failed';

/**
 * Local mirror row for a voucher. Monetary fields are decimal strings at the
 * server's internal precision (currency_scale + 2). Stored as TEXT for the
 * same reason migration 21 widened other monetary columns: avoid IEEE 754
 * coercion of multi-decimal currencies.
 */
export interface LocalVoucher {
  id: string;
  code: string;
  initial_balance: string;
  current_balance: string;
  currency: string;
  status: VoucherStatus;
  redemption_mode: VoucherRedemptionMode;
  voucher_kind: string;
  source: VoucherSource;
  issued_at: string;
  expires_at: string | null;
  partner_id: string | null;
  issued_to_partner_id: string | null;
  redeemable_at_terminal_id: string | null;
  notes: string | null;
  synced_at: string;
}

/**
 * Local mirror row for a voucher_ledger entry. `sync_status = 'pending'`
 * indicates a row written locally during a refund/exchange that has not yet
 * been pushed to the server; `'synced'` covers both server-pulled rows and
 * locally-written rows after a successful push.
 */
export interface LocalVoucherLedgerEntry {
  id: string;
  voucher_id: string;
  event: VoucherLedgerEvent;
  amount: string;
  currency: string;
  receipt_id: string | null;
  terminal_id: string | null;
  user_id: string;
  occurred_at: string;
  sync_status: LedgerSyncStatus;
  sync_error: string | null;
  synced_at: string | null;
}

/**
 * Local index entry tying a server-signed QR token (and its raw receipt
 * number) to the receipt_uuid the dispatcher needs to look up downstream
 * data. `qr_token` is nullable for offline-issued receipts whose token has
 * not yet been signed back from the server.
 *
 * `partner_id` is nullable — populated once migration 26 runs AND the
 * backend sync payload includes the field. Rows created before that wiring
 * will have partner_id = null.
 */
export interface LocalReceiptQrIndexEntry {
  receipt_uuid: string;
  qr_token: string | null;
  receipt_number: string;
  terminal_id: string;
  posted_at: string;
  total: string;
  currency: string;
  /** Added by migration 26. Null until sync layer passes the value through. */
  partner_id: string | null;
  synced_at: string;
}

// ─── Lookup helpers (LOCAL ONLY — NO API CALLS) ─────────────────────────────

const VOUCHER_COLUMNS =
  'id, code, initial_balance, current_balance, currency, status, redemption_mode, ' +
  "voucher_kind, source, issued_at, expires_at, partner_id, issued_to_partner_id, " +
  'redeemable_at_terminal_id, notes, synced_at';

const RECEIPT_QR_INDEX_COLUMNS =
  'receipt_uuid, qr_token, receipt_number, terminal_id, posted_at, total, currency, partner_id, synced_at';

export async function findByCode(db: Database, code: string): Promise<LocalVoucher | null> {
  return queryOne<LocalVoucher>(
    db,
    `SELECT ${VOUCHER_COLUMNS} FROM vouchers WHERE code = $1 LIMIT 1`,
    [code],
  );
}

/**
 * Receipt-QR tokens are formatted as `v:kid:receipt_uuid:mac` (4 colon-separated
 * fields). The MAC is verified server-side; the POS only needs to extract the
 * `receipt_uuid` and look it up in the local index. Malformed tokens (wrong
 * field count, non-UUID receipt field) return null without throwing — the
 * dispatcher in Task 50 falls through to product-barcode lookup on null.
 */
export async function findReceiptByQrToken(
  db: Database,
  token: string,
): Promise<LocalReceiptQrIndexEntry | null> {
  const receiptUuid = parseReceiptUuidFromQrToken(token);
  if (receiptUuid === null) return null;

  return queryOne<LocalReceiptQrIndexEntry>(
    db,
    `SELECT ${RECEIPT_QR_INDEX_COLUMNS} FROM receipt_qr_index WHERE receipt_uuid = $1 LIMIT 1`,
    [receiptUuid],
  );
}

export async function findReceiptByNumber(
  db: Database,
  number: string,
): Promise<LocalReceiptQrIndexEntry | null> {
  return queryOne<LocalReceiptQrIndexEntry>(
    db,
    `SELECT ${RECEIPT_QR_INDEX_COLUMNS} FROM receipt_qr_index WHERE receipt_number = $1 LIMIT 1`,
    [number],
  );
}

/**
 * Returns the most recent receipts in the local index that are linked to the
 * given `partnerId`. Ordered newest-first by `posted_at`.
 *
 * This query is LOCAL ONLY — no API call. The `partner_id` column was added by
 * migration 26; rows synced before the backend wires this field will have
 * `partner_id = null` and will NOT appear here. That is expected and correct —
 * the "Find by customer" tab will simply show fewer results until the sync
 * layer is updated to populate the column.
 *
 * TODO (follow-up): Update syncService + backend ReceiptSyncController to
 * include `partner_id` in the sync payload, and pass it through
 * `upsertReceiptQrIndexEntries` so this query becomes fully useful.
 */
export async function findRecentReceiptsByPartner(
  db: Database,
  partnerId: string,
  limit: number = 20,
): Promise<LocalReceiptQrIndexEntry[]> {
  return queryAll<LocalReceiptQrIndexEntry>(
    db,
    `SELECT ${RECEIPT_QR_INDEX_COLUMNS}
       FROM receipt_qr_index
      WHERE partner_id = $1
      ORDER BY posted_at DESC
      LIMIT $2`,
    [partnerId, limit],
  );
}

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Canonical 4-segment `v:kid:receipt_uuid:mac` parser. Returns the lowercased
 * receipt UUID (the local `receipt_qr_index` stores lowercase) or null on any
 * malformation (wrong field count, non-UUID receipt field, empty input).
 *
 * Exported because the scan dispatcher (Task 50) needs to perform a cheap
 * pre-flight parse before hitting SQLite — keeping a single source of truth
 * for the token format avoids accidental drift if §4.5 ever changes.
 */
export function parseReceiptUuidFromQrToken(token: string): string | null {
  if (!token) return null;
  const parts = token.split(':');
  if (parts.length !== 4) return null;
  const candidate = parts[2];
  if (!candidate || !UUID_RE.test(candidate)) return null;
  return candidate.toLowerCase();
}

// ─── Upsert helpers (used by the sync pipeline) ─────────────────────────────

const VOUCHER_BATCH_SIZE = 50;
/** $-placeholders per voucher row (excludes datetime('now') literal for synced_at). */
const VOUCHER_PARAMS_PER_ROW = 15;

export async function upsertVouchers(
  db: Database,
  vouchers: LocalVoucher[],
): Promise<void> {
  if (vouchers.length === 0) return;

  for (let i = 0; i < vouchers.length; i += VOUCHER_BATCH_SIZE) {
    const batch = vouchers.slice(i, i + VOUCHER_BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const v = batch[j]!;
      const offset = j * VOUCHER_PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6}, $${offset + 7}, $${offset + 8}, $${offset + 9}, $${offset + 10}, $${offset + 11}, $${offset + 12}, $${offset + 13}, $${offset + 14}, $${offset + 15}, datetime('now'))`,
      );
      params.push(
        v.id,
        v.code,
        v.initial_balance,
        v.current_balance,
        v.currency,
        v.status,
        v.redemption_mode,
        v.voucher_kind,
        v.source,
        v.issued_at,
        v.expires_at ?? null,
        v.partner_id ?? null,
        v.issued_to_partner_id ?? null,
        v.redeemable_at_terminal_id ?? null,
        v.notes ?? null,
      );
    }

    await execute(
      db,
      `INSERT INTO vouchers (
        id, code, initial_balance, current_balance, currency, status, redemption_mode,
        voucher_kind, source, issued_at, expires_at, partner_id, issued_to_partner_id,
        redeemable_at_terminal_id, notes, synced_at
       ) VALUES ${valueClauses.join(', ')}
       ON CONFLICT(id) DO UPDATE SET
         code = excluded.code,
         initial_balance = excluded.initial_balance,
         current_balance = excluded.current_balance,
         currency = excluded.currency,
         status = excluded.status,
         redemption_mode = excluded.redemption_mode,
         voucher_kind = excluded.voucher_kind,
         source = excluded.source,
         issued_at = excluded.issued_at,
         expires_at = excluded.expires_at,
         partner_id = excluded.partner_id,
         issued_to_partner_id = excluded.issued_to_partner_id,
         redeemable_at_terminal_id = excluded.redeemable_at_terminal_id,
         notes = excluded.notes,
         synced_at = datetime('now')`,
      params,
    );
  }
}

const LEDGER_BATCH_SIZE = 50;
/** $-placeholders per ledger row. */
const LEDGER_PARAMS_PER_ROW = 11;

export async function upsertVoucherLedgerEntries(
  db: Database,
  entries: LocalVoucherLedgerEntry[],
): Promise<void> {
  if (entries.length === 0) return;

  for (let i = 0; i < entries.length; i += LEDGER_BATCH_SIZE) {
    const batch = entries.slice(i, i + LEDGER_BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const e = batch[j]!;
      const offset = j * LEDGER_PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6}, $${offset + 7}, $${offset + 8}, $${offset + 9}, $${offset + 10}, $${offset + 11})`,
      );
      params.push(
        e.id,
        e.voucher_id,
        e.event,
        e.amount,
        e.currency,
        e.receipt_id ?? null,
        e.terminal_id ?? null,
        e.user_id,
        e.occurred_at,
        e.sync_status,
        e.synced_at ?? null,
      );
    }

    await execute(
      db,
      `INSERT INTO voucher_ledger (
        id, voucher_id, event, amount, currency, receipt_id, terminal_id, user_id,
        occurred_at, sync_status, synced_at
       ) VALUES ${valueClauses.join(', ')}
       ON CONFLICT(id) DO UPDATE SET
         voucher_id = excluded.voucher_id,
         event = excluded.event,
         amount = excluded.amount,
         currency = excluded.currency,
         receipt_id = excluded.receipt_id,
         terminal_id = excluded.terminal_id,
         user_id = excluded.user_id,
         occurred_at = excluded.occurred_at,
         sync_status = excluded.sync_status,
         synced_at = excluded.synced_at`,
      params,
    );
  }
}

const RECEIPT_QR_BATCH_SIZE = 50;
/** $-placeholders per receipt_qr_index row (includes partner_id added by migration 26). */
const RECEIPT_QR_PARAMS_PER_ROW = 8;

export async function upsertReceiptQrIndexEntries(
  db: Database,
  entries: LocalReceiptQrIndexEntry[],
): Promise<void> {
  if (entries.length === 0) return;

  for (let i = 0; i < entries.length; i += RECEIPT_QR_BATCH_SIZE) {
    const batch = entries.slice(i, i + RECEIPT_QR_BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const e = batch[j]!;
      const offset = j * RECEIPT_QR_PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6}, $${offset + 7}, $${offset + 8}, datetime('now'))`,
      );
      params.push(
        e.receipt_uuid,
        e.qr_token ?? null,
        e.receipt_number,
        e.terminal_id,
        e.posted_at,
        e.total,
        e.currency,
        e.partner_id ?? null,
      );
    }

    await execute(
      db,
      `INSERT INTO receipt_qr_index (
        receipt_uuid, qr_token, receipt_number, terminal_id, posted_at, total, currency, partner_id, synced_at
       ) VALUES ${valueClauses.join(', ')}
       ON CONFLICT(receipt_uuid) DO UPDATE SET
         qr_token = excluded.qr_token,
         receipt_number = excluded.receipt_number,
         terminal_id = excluded.terminal_id,
         posted_at = excluded.posted_at,
         total = excluded.total,
         currency = excluded.currency,
         partner_id = excluded.partner_id,
         synced_at = datetime('now')`,
      params,
    );
  }
}

/**
 * Locally-written ledger entries that haven't been pushed yet. Used by
 * `syncService.pushVoucherLedgerEntries`.
 */
export async function getPendingVoucherLedgerEntries(
  db: Database,
): Promise<LocalVoucherLedgerEntry[]> {
  return queryAll<LocalVoucherLedgerEntry>(
    db,
    `SELECT id, voucher_id, event, amount, currency, receipt_id, terminal_id, user_id,
            occurred_at, sync_status, sync_error, synced_at
       FROM voucher_ledger
      WHERE sync_status = 'pending'
      ORDER BY occurred_at ASC`,
  );
}

export async function markVoucherLedgerEntrySynced(
  db: Database,
  id: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE voucher_ledger
        SET sync_status = 'synced',
            sync_error = NULL,
            synced_at = datetime('now')
      WHERE id = $1`,
    [id],
  );
}

export async function markVoucherLedgerEntryFailed(
  db: Database,
  id: string,
  reason: string,
): Promise<void> {
  await execute(
    db,
    `UPDATE voucher_ledger
        SET sync_status = 'failed',
            sync_error = $1
      WHERE id = $2`,
    [reason, id],
  );
}
