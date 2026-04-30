/**
 * Typed refund-flow events emitted by the POS scan dispatcher (Task 50)
 * and consumed by the unified cart hydration code (Task 52).
 *
 * `ReceiptTokenAccepted` represents the decision the cashier made on the
 * Receipt-Scan Confirmation Sheet — i.e. "this is a sale receipt I want
 * to start a refund or exchange against." The cart MUST NOT be mutated
 * before this event fires; Task 52 owns hydration.
 */
export interface ReceiptTokenAccepted {
  /** Lowercase hyphenated UUID of the original sale receipt. */
  receiptUuid: string;
  /** Human-readable receipt number (e.g. `R-0001`). */
  receiptNumber: string;
  /**
   * The raw `v:kid:receipt_uuid:mac` token as scanned. May be null for
   * offline-issued receipts whose token has not yet been signed by the
   * server (the local index allows null).
   */
  receiptToken: string | null;
  /** ISO-8601 UTC timestamp of when the receipt was posted. */
  postedAt: string;
  /** Decimal-string total at the server's internal precision. */
  total: string;
  /** ISO-4217 currency code. */
  currency: string;
}
