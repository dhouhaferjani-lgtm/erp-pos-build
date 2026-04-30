/**
 * POS scan dispatcher (Phase H Task 50).
 *
 * When the cashier scans bytes in any POS mode, this dispatcher decides
 * whether the bytes are a receipt-token (`v:kid:receipt_uuid:mac`) that
 * resolves to a sale receipt at THIS terminal. If so, the caller shows
 * the Receipt-Scan Confirmation Sheet — the cart is NEVER silently
 * mutated. Otherwise the bytes fall through to product-barcode lookup.
 *
 * Spec references:
 *   - §6.1 entry 2 (scan-from-anywhere with confirmation sheet)
 *   - §4.5 (token format `v:kid:receipt_uuid:mac`, MAC verified server-side)
 *   - Codex review 3 finding G (terminal-of-issue check, no API calls)
 *
 * No-fetch contract: this module MUST NOT import `apiGet`, `apiPost`, or
 * `@tauri-apps/plugin-http`. The lookup goes through the local-only
 * `findReceiptByQrToken` helper in `voucherRepository`.
 */

import type Database from '@tauri-apps/plugin-sql';
import {
  findReceiptByQrToken,
  parseReceiptUuidFromQrToken,
  type LocalReceiptQrIndexEntry,
} from '@/lib/offline/voucherRepository';

export interface ParsedReceiptToken {
  /** Lowercase hyphenated UUID extracted from token field 3. */
  receiptUuid: string;
}

/**
 * Dispatcher-side wrapper around the canonical `parseReceiptUuidFromQrToken`
 * in `voucherRepository`. The wrapper exists for the dispatcher contract —
 * its `{ receiptUuid: string } | null` shape is what the dispatch flow
 * works in — but the parsing logic itself lives in one place to prevent
 * drift if §4.5 ever changes.
 */
export function parseReceiptToken(token: string): ParsedReceiptToken | null {
  const receiptUuid = parseReceiptUuidFromQrToken(token);
  if (receiptUuid === null) return null;
  return { receiptUuid };
}

/**
 * Discriminated dispatch result. The dispatcher returns `'receipt-token'`
 * ONLY when (a) the token parses, (b) `findReceiptByQrToken` returns a
 * non-null entry, AND (c) the entry's terminal_id matches the active
 * terminal (Codex review 3 finding G — receipts scanned at a different
 * terminal must NOT trigger the confirmation sheet).
 */
export type DispatchResult =
  | { kind: 'receipt-token'; entry: LocalReceiptQrIndexEntry }
  | { kind: 'fallthrough' };

export interface DispatchScanArgs {
  token: string;
  db: Database;
  /**
   * The active terminal's UUID. May be null when the cashier has not yet
   * activated a terminal — in that case we always fall through.
   */
  terminalId: string | null;
}

export async function dispatchScan(
  args: DispatchScanArgs,
): Promise<DispatchResult> {
  const { token, db, terminalId } = args;

  // No active terminal → can't safely classify scope; fall through.
  if (!terminalId) return { kind: 'fallthrough' };

  // Cheap parse first — avoids hitting SQLite for plain product barcodes.
  const parsed = parseReceiptToken(token);
  if (parsed === null) return { kind: 'fallthrough' };

  const entry = await findReceiptByQrToken(db, token);
  if (entry === null) return { kind: 'fallthrough' };

  // Terminal-of-issue gate — Codex finding G. A customer's old receipt
  // from another terminal must NOT trigger the confirmation sheet here;
  // the receipt-locator screen (Task 51) is the explicit cross-terminal
  // entry point.
  if (entry.terminal_id !== terminalId) return { kind: 'fallthrough' };

  return { kind: 'receipt-token', entry };
}
