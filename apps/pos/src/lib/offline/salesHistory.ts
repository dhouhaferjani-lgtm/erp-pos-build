/**
 * Sales-history derivations for the manager report screen (`/reports`).
 *
 * Scope discipline: the money AGGREGATES a manager screen shows (gross sales,
 * sales count, per-tender takings) are NOT re-derived here — `buildEndOfDayPreview`
 * already owns the canonical, change-netted, refund-signed aggregation and is
 * consumed as-is with the period start standing in for the shift opening. What
 * this module adds is the part the preview does not return: the TICKET LIST
 * itself, its filters, and the refund counter-figures that make the
 * "excl. refunds" headline honest (owner ruling O-28).
 *
 * Legacy (pre-v4) refunds are folded into the refund counter-figures but can
 * never appear as ticket ROWS — see
 * `docs/superpowers/tickets/2026-08-21-pos-manager-screens-followups.md` for
 * that bounded claim, the `created_at`-vs-`settled_at` limit, and the unindexed
 * `local_refund_records.terminal_id`.
 *
 * Rule 19 — every money value stays a decimal string; bcmath only.
 * Rule 20 — every JS-supplied period boundary is bound through `toSqliteUtc`,
 *           because `offline_receipts.created_at` is `datetime('now')` TEXT
 *           (space separator) and SQLite compares TEXT lexicographically.
 */

import type Database from '@tauri-apps/plugin-sql';
import { queryAll } from '@/lib/db';
import { bcabs, bcadd, bcdiv, bccomp, bcformat, bcmul, bcsub } from '@/lib/decimal';
import { toSqliteUtc } from '@/lib/db/sqliteTime';
import { getRefundRecordsForShift } from '@/lib/db/repositories/localRefundRecordRepository';

/** Periods offered by the reports screen segment control. */
export type SalesPeriod = 'today' | 'shift' | 'week';

/** Pseudo-method used to filter split tenders; never a real `payment_methods.code`. */
export const MIXED_METHOD = 'MIXED';

export interface SalesHistoryTicket {
  id: string;
  receiptNumber: string;
  /** Raw `offline_receipts.created_at` — SQLite UTC TEXT, space separator. */
  createdAt: string;
  operatorName: string;
  /** Number of lines on the receipt (`lines` is a JSON blob, not a table). */
  itemCount: number;
  /** Distinct tender codes on the ticket, in tender order. */
  methodCodes: string[];
  /** The tender's display name when there is exactly one; null for split tenders. */
  methodLabel: string | null;
  isRefund: boolean;
  /** Signed decimal string — refund rows are already negative on the row (§7.2). */
  total: string;
}

export interface RefundTotals {
  count: number;
  /** POSITIVE magnitude, matching the Z's `refunds_amount` convention. */
  amount: string;
}

interface ReceiptRow {
  id: string;
  receipt_number: string;
  created_at: string;
  operator_name: string;
  lines: string;
  payments_json: string;
  payment_method_id: string;
  total: string;
  receipt_kind: string | null;
}

interface PaymentJsonRow {
  method_code?: string;
}

/**
 * Lower bound of the selected period, as an ISO 8601 instant.
 *
 * Returned as ISO (not SQLite TEXT) so it can be handed straight to
 * `buildEndOfDayPreview`, which normalises it itself. Local midnight — not UTC
 * midnight — is the boundary a shopkeeper means by "today".
 *
 * @returns null when the period cannot be resolved (shift period, no open shift).
 */
export function periodStartIso(
  period: SalesPeriod,
  now: Date,
  shiftOpenedAt: string | null,
): string | null {
  if (period === 'shift') return shiftOpenedAt;

  const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  if (period === 'week') {
    // 6 days back from local midnight => a 7-day inclusive window.
    midnight.setDate(midnight.getDate() - 6);
  }
  return midnight.toISOString();
}

/**
 * Load the receipt rows for the period as display tickets.
 *
 * Applies the SAME row filter as the Z / end-of-day paths (`voided = 0 AND
 * is_training = 0`): a training-mode or voided receipt must never appear in a
 * manager's sales log. (Note: `fetchLocalShiftReceipts` in reportApi.ts applies
 * neither filter — that inconsistency is owned by another lane.)
 */
export async function loadSalesHistoryTickets(
  db: Database,
  terminalId: string,
  sinceIso: string,
): Promise<SalesHistoryTicket[]> {
  // The two reads are independent — issue them together rather than serially.
  const [rows, methods] = await Promise.all([
    queryAll<ReceiptRow>(
      db,
      `SELECT id, receipt_number, created_at, operator_name, lines, payments_json,
              payment_method_id, total, receipt_kind
         FROM offline_receipts
        WHERE terminal_id = $1 AND created_at >= $2 AND voided = 0 AND is_training = 0
        ORDER BY created_at DESC`,
      [terminalId, toSqliteUtc(sinceIso)],
    ),
    queryAll<{ id: string; code: string; name: string }>(
      db,
      `SELECT id, code, COALESCE(name, code) AS name FROM payment_methods`,
    ),
  ]);

  const nameByCode = new Map(methods.map((m) => [m.code, m.name]));
  const codeById = new Map(methods.map((m) => [m.id, m.code]));

  return rows.map((row) => {
    const codes = tenderCodes(row, codeById);
    const soleCode = codes.length === 1 ? codes[0] : undefined;
    return {
      id: row.id,
      receiptNumber: row.receipt_number,
      createdAt: row.created_at,
      operatorName: row.operator_name,
      itemCount: parseJsonArray(row.lines).length,
      methodCodes: codes,
      methodLabel: soleCode === undefined ? null : (nameByCode.get(soleCode) ?? soleCode),
      isRefund: row.receipt_kind === 'refund',
      total: row.total,
    };
  });
}

/**
 * Distinct tender codes for a receipt.
 *
 * Keyed on the RAW `method_code` in `payments_json` (like the signed Z and the
 * end-of-day preview) so a tender is never dropped because sync deactivated the
 * method mid-shift. Falls back to the denormalised primary method column when
 * `payments_json` is empty — the same legacy fallback the preview applies.
 */
function tenderCodes(row: ReceiptRow, codeById: Map<string, string>): string[] {
  const payments = parseJsonArray(row.payments_json) as PaymentJsonRow[];
  const codes: string[] = [];
  const seen = new Set<string>();
  for (const payment of payments) {
    const code = payment.method_code;
    if (typeof code !== 'string' || code === '') continue;
    if (seen.has(code)) continue;
    seen.add(code);
    codes.push(code);
  }
  if (codes.length > 0) return codes;

  const fallback = codeById.get(row.payment_method_id);
  return fallback ? [fallback] : [];
}

function parseJsonArray(raw: string | null): unknown[] {
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

/**
 * Refund counter-figures for the "excl. refunds" headline (owner ruling O-28):
 * the count of refund tickets and their positive magnitude.
 */
export function summarizeRefunds(
  tickets: readonly SalesHistoryTicket[],
  scale: number,
): RefundTotals {
  let count = 0;
  let amount = '0';
  for (const ticket of tickets) {
    if (!ticket.isRefund) continue;
    count += 1;
    amount = bcadd(amount, bcabs(ticket.total, scale), scale);
  }
  return { count, amount: bcformat(amount, scale) };
}

/**
 * Method + free-text filtering for the ticket table.
 *
 * A concrete code matches only SINGLE-tender tickets; split tenders are reached
 * through the {@link MIXED_METHOD} pseudo-option, so the options partition the
 * list instead of overlapping.
 */
export function filterTickets(
  tickets: readonly SalesHistoryTicket[],
  methodFilter: string,
  search: string,
): SalesHistoryTicket[] {
  const needle = search.trim().toLowerCase();
  return tickets.filter((ticket) => {
    const matchesMethod =
      methodFilter === 'all' ||
      (methodFilter === MIXED_METHOD
        ? ticket.methodCodes.length > 1
        : ticket.methodCodes.length === 1 && ticket.methodCodes[0] === methodFilter);
    if (!matchesMethod) return false;
    if (needle === '') return true;
    return (
      ticket.receiptNumber.toLowerCase().includes(needle) ||
      ticket.operatorName.toLowerCase().includes(needle)
    );
  });
}

/**
 * Integer share of a tender in the period, as a string percentage.
 *
 * Returned as a string so it can go straight into a CSS width without a
 * `Number(...)` round-trip. Negative shares (a tender net-negative after
 * refunds) clamp to 0 rather than drawing a backwards bar.
 */
export function paymentSharePercent(amount: string, totalAbs: string): string {
  if (bccomp(totalAbs, '0') === 0) return '0';
  const share = bcdiv(bcmul(amount, '100', 4), totalAbs, 0);
  // `bccomp('-0', '0') === 0`, so a signed zero survives a naive clamp and
  // reaches the DOM as `width: -0%`. Compare, then normalise the sign.
  return bccomp(share, '0') <= 0 ? '0' : share;
}

/**
 * Legacy (pre-v4) refunds settled in a period, by terminal.
 *
 * Legacy refunds NEVER write an `offline_receipts` row — `local_refund_records`
 * is the legacy path's sole durable trace (`offlineReceiptRepository.ts:353`,
 * `endOfDayPreview.ts:417-419`), and the two sources are DISJOINT BY
 * CONSTRUCTION: a v4 refund never writes `local_refund_records`, a legacy refund
 * never writes a `receipt_kind='refund'` row (`zReportService.ts:212-215`). So
 * this is an additive fold on top of {@link summarizeRefunds}, exactly the shape
 * the signed Z already uses — never a SQL UNION, which cannot work here (the
 * table has no `lines`, `payments_json`, `operator_name`, `voided` or
 * `is_training` columns, so a legacy refund can never become a LIST row).
 *
 * Bounded on purpose — filters on `created_at` (the local write instant), not
 * `settled_at` (the server `posted_at`). `settled_at` is ISO 8601 with a `T`
 * separator and so is NOT lexicographically comparable against a
 * `toSqliteUtc()` bound — the rule-20 hazard. The two diverge under the
 * documented crash/rollover races; see the ticket referenced in the module
 * docblock.
 */
export async function loadLegacyRefundTotalsForPeriod(
  db: Database,
  terminalId: string,
  sinceIso: string,
  scale: number,
): Promise<RefundTotals> {
  const rows = await queryAll<{ total: string }>(
    db,
    `SELECT total FROM local_refund_records
      WHERE terminal_id = $1 AND created_at >= $2`,
    [terminalId, toSqliteUtc(sinceIso)],
  );
  return foldRefundMagnitudes(rows, scale);
}

/**
 * Legacy refunds for one shift, through the shift-keyed repository the Z uses.
 *
 * Preferred over {@link loadLegacyRefundTotalsForPeriod} when a shift id is in
 * hand: `shift_id` is the table's only index, and it matches the signed Z's own
 * attribution exactly rather than approximating it with a timestamp window.
 */
export async function loadLegacyRefundTotalsForShift(
  db: Database,
  shiftId: string,
  scale: number,
): Promise<RefundTotals> {
  const records = await getRefundRecordsForShift(db, shiftId);
  return foldRefundMagnitudes(records, scale);
}

/**
 * Sum signed refund rows into a positive magnitude.
 *
 * `bcabs` per row is load-bearing: legacy rows store a NEGATIVE total while v4
 * refund authoring elsewhere stores a POSITIVE one, so an un-normalised sum
 * lets one convention cancel the other.
 */
function foldRefundMagnitudes(rows: readonly { total: string }[], scale: number): RefundTotals {
  let amount = '0';
  for (const row of rows) {
    amount = bcadd(amount, bcabs(row.total, scale), scale);
  }
  return { count: rows.length, amount: bcformat(amount, scale) };
}

/** Add the two disjoint refund sources (v4 receipt rows + legacy records). */
export function combineRefundTotals(
  a: RefundTotals,
  b: RefundTotals,
  scale: number,
): RefundTotals {
  return {
    count: a.count + b.count,
    amount: bcformat(bcadd(a.amount, b.amount, scale), scale),
  };
}

/**
 * The O-28 headline: gross sales NET of refunds.
 *
 * Owner ruling O-28 (LEDGER, 2026-08-21) — every Today's-Sales headline is NET,
 * EXCLUDING REFUNDS, and must be labelled accordingly. `gross` is the sale-only
 * figure; `refundMagnitude` is the positive total from
 * {@link combineRefundTotals}. Can legitimately go negative in a period that
 * holds only refunds.
 */
export function netOfRefunds(gross: string, refundMagnitude: string, scale: number): string {
  return bcformat(bcsub(gross, refundMagnitude, scale), scale);
}
