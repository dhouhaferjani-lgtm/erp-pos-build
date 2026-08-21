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
 * Rule 19 — every money value stays a decimal string; bcmath only.
 * Rule 20 — every JS-supplied period boundary is bound through `toSqliteUtc`,
 *           because `offline_receipts.created_at` is `datetime('now')` TEXT
 *           (space separator) and SQLite compares TEXT lexicographically.
 */

import type Database from '@tauri-apps/plugin-sql';
import { queryAll } from '@/lib/db';
import { bcabs, bcadd, bcdiv, bccomp, bcformat, bcmul } from '@/lib/decimal';
import { toSqliteUtc } from '@/lib/db/sqliteTime';

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

const SQLITE_UTC_FORMAT = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

/**
 * Read a SQLite UTC TEXT timestamp as an instant.
 *
 * `new Date('2026-08-21 10:05:00')` is interpreted in the DEVICE timezone by
 * every JS engine, which silently shifts every rendered receipt time by the
 * local UTC offset. The stored value is UTC, so say so explicitly.
 */
export function sqliteUtcToDate(timestamp: string): Date {
  if (SQLITE_UTC_FORMAT.test(timestamp)) {
    return new Date(`${timestamp.replace(' ', 'T')}Z`);
  }
  return new Date(timestamp);
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
  const rows = await queryAll<ReceiptRow>(
    db,
    `SELECT id, receipt_number, created_at, operator_name, lines, payments_json,
            payment_method_id, total, receipt_kind
       FROM offline_receipts
      WHERE terminal_id = $1 AND created_at >= $2 AND voided = 0 AND is_training = 0
      ORDER BY created_at DESC`,
    [terminalId, toSqliteUtc(sinceIso)],
  );

  const methods = await queryAll<{ id: string; code: string; name: string }>(
    db,
    `SELECT id, code, COALESCE(name, code) AS name FROM payment_methods`,
  );
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
  for (const payment of payments) {
    const code = payment.method_code;
    if (typeof code !== 'string' || code === '') continue;
    if (!codes.includes(code)) codes.push(code);
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
