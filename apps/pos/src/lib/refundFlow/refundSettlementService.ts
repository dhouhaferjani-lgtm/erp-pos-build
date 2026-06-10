/**
 * Refund settlement service (Task 53 / Phase 2) — pure lib code, no React.
 *
 * Orchestrates the ONLINE-ONLY refund settlement against
 * `POST /pos/receipts/{id}/return`, in two phases so the UI can author the
 * manager-PIN approval evidence in between (the approval target binds the
 * server line_ids, which only exist after mapping):
 *
 *   1. `prepareRefundSettlement` — resolves the LOCAL receipt to its SERVER
 *      id via the local `receipt_qr_index` (no entry → NOT_SYNCED: the sale
 *      has not synced yet, settle at the original terminal / after sync),
 *      fetches the server receipt, and maps the refund cart lines onto
 *      server line_ids (typed LINE_MAPPING_FAILED on mismatch).
 *   2. `submitRefundReturn` — builds the StoreReturnRequest payload (six
 *      approval-evidence fields are an INPUT, obtained by the UI via
 *      manager PIN) and POSTs it.
 *
 * Fiscal online-gating policy (mirrors `scopedManagerPin`'s anti-downgrade
 * branching): a reachable-but-erroring server (5xx/408/429) is retried up to
 * a bounded cap then FAILS CLOSED (SERVER_ERROR); only POSITIVE offline
 * evidence (typed FetchTimeoutError, or the connectivity store's
 * authoritative isOnline === false) yields OFFLINE; any other 4xx is an
 * immediate VALIDATION_FAILED carrying the server message; unexpected errors
 * fail closed as SERVER_ERROR.
 *
 * Idempotency: `refund_request_id` must be generated ONCE per settlement
 * attempt (`generateRefundRequestId`) and REUSED across retries — internal
 * retries reuse the same payload object, and callers retrying a failed
 * submit must pass the SAME id again so the server replays the original
 * return receipt instead of paying out twice. Callers must also SERIALIZE
 * submits: never run two concurrent `submitRefundReturn` calls for the same
 * settlement — the UI must disable the action while a submit is in flight.
 * Server-side idempotency is the backstop, not the primary guard.
 *
 * NO user-facing strings here — errors are typed codes the UI translates.
 */
import type Database from '@tauri-apps/plugin-sql';
import { ApiRequestError, apiGet, apiPost } from '@/lib/api';
import {
  findReceiptByNumber,
  findReceiptByQrToken,
} from '@/lib/offline/voucherRepository';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';
import { useConnectivityStore } from '@/stores/connectivityStore';
import type { CartItem } from '@/types/cart';
import {
  mapRefundItemsToServerLines,
  type LineMappingFailureReason,
  type MappedReturnLine,
  type ServerReceiptLine,
} from './refundLineMapping';

// ─── Destinations ────────────────────────────────────────────────────────────

/** Destination as produced by RefundDestinationPicker. */
export type PickerRefundDestination = 'original' | 'cash' | 'store_voucher';

/** Cashier-selectable destination values of the server RefundDestination enum. */
export type ServerRefundDestination = 'original_payment' | 'cash' | 'store_voucher';

/** Map the picker's 'original' onto the server enum's 'original_payment'. */
export function toServerRefundDestination(
  destination: PickerRefundDestination,
): ServerRefundDestination {
  return destination === 'original' ? 'original_payment' : destination;
}

// ─── Contract types ──────────────────────────────────────────────────────────

/** Server ReturnReason enum values. */
export type RefundReturnReason =
  | 'defective'
  | 'wrong_item'
  | 'customer_changed_mind'
  | 'other';

/**
 * The six approval-evidence fields the /return endpoint verifies against the
 * OPERATOR_APPROVAL_GRANTED / OVERRIDE_VOID_OR_RETURN fiscal-event chain.
 * Obtained by the UI via the manager-PIN flow — an input to this service.
 */
export interface RefundApprovalEvidence {
  approval_id: string;
  approval_fiscal_event_id: string;
  approval_scope: 'void_or_return_override';
  approval_supervisor_user_id: string;
  approval_override_event_id: string;
  /** Server validates same:approval_supervisor_user_id. */
  authorized_by_user_id: string;
}

/** Full POST /pos/receipts/{id}/return request body. */
export interface StoreReturnRequestPayload {
  terminal_id: string;
  return_reason: RefundReturnReason;
  lines: MappedReturnLine[];
  notes?: string;
  refund_request_id: string;
  refund_destination: ServerRefundDestination;
  approval_id: string;
  approval_fiscal_event_id: string;
  approval_scope: 'void_or_return_override';
  approval_supervisor_user_id: string;
  approval_override_event_id: string;
  authorized_by_user_id: string;
  override_reason?: string;
}

export interface IssuedVoucher {
  id: string;
  code: string;
  initial_balance: string;
  currency: string;
  expires_at: string | null;
  redemption_mode: string;
  partner_id: string | null;
}

export interface ReturnSettlementLine {
  product_name: string;
  quantity: string;
  unit_price: string;
  line_total: string;
}

/** Response of POST /pos/receipts/{id}/return (consumed by the print task). */
export interface ReturnSettlementResponse {
  id: string;
  receipt_number: string;
  receipt_type: string;
  original_receipt_id: string;
  return_reason: string | null;
  subtotal: string;
  tax_amount: string;
  total: string;
  currency: string;
  posted_at: string;
  qr_token: string | null;
  issued_voucher: IssuedVoucher | null;
  lines: ReturnSettlementLine[];
}

// ─── Typed errors / results ──────────────────────────────────────────────────

/** Terminal HTTP/transport failure classifications shared by both phases. */
export type RefundTransportError =
  /** Positive offline evidence — terminal genuinely offline. */
  | { code: 'OFFLINE' }
  /** Reachable-but-erroring server, retries exhausted (FAIL CLOSED), or unexpected error. */
  | { code: 'SERVER_ERROR'; status?: number }
  /** Server explicitly rejected the request (4xx other than 408/429). */
  | { code: 'VALIDATION_FAILED'; status: number; apiCode: string; message: string };

export type RefundSettlementError =
  /** Local receipt has no receipt_qr_index entry — sale not synced yet. */
  | { code: 'NOT_SYNCED' }
  /** Refund cart lines could not be mapped onto the server receipt's lines. */
  | { code: 'LINE_MAPPING_FAILED'; reason: LineMappingFailureReason; itemId: string }
  | RefundTransportError;

export interface PreparedRefundSettlement {
  /** pos_receipts.id on the server (= receipt_qr_index.receipt_uuid). */
  serverReceiptId: string;
  /** Mapped /return lines — the UI binds these ids into the approval target. */
  lines: MappedReturnLine[];
  /** Server lines as fetched (display fidelity / debugging). */
  serverLines: ServerReceiptLine[];
}

export type PrepareRefundResult =
  | { ok: true; value: PreparedRefundSettlement }
  | { ok: false; error: RefundSettlementError };

export type SubmitRefundResult =
  | { ok: true; response: ReturnSettlementResponse }
  | { ok: false; error: RefundTransportError };

// ─── Retry policy (mirrors scopedManagerPin anti-downgrade) ──────────────────

const MAX_RETRIES = 2; // 1 initial attempt + 2 retries = 3 attempts total.
const RETRY_BACKOFF_MS = 400;

export interface RetryOptions {
  maxRetries?: number;
  backoffMs?: number;
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

/**
 * Positive evidence the terminal is GENUINELY offline — the only condition
 * that downgrades to the typed OFFLINE error. Anything else fails closed.
 */
function isGenuineOfflineFailure(error: unknown): boolean {
  if (error instanceof FetchTimeoutError) return true;
  if (!useConnectivityStore.getState().isOnline) return true;
  return false;
}

/** Reachable-but-erroring server — transiently unable to decide. Retryable. */
function isRetryableServerError(error: unknown): error is ApiRequestError {
  return (
    error instanceof ApiRequestError &&
    (error.status >= 500 || error.status === 408 || error.status === 429)
  );
}

function classifyTerminalFailure(error: unknown): RefundTransportError {
  if (error instanceof ApiRequestError) {
    if (error.status >= 500 || error.status === 408 || error.status === 429) {
      // Retries exhausted on a reachable server → FAIL CLOSED.
      return { code: 'SERVER_ERROR', status: error.status };
    }
    return {
      code: 'VALIDATION_FAILED',
      status: error.status,
      apiCode: error.code,
      message: error.apiMessage,
    };
  }
  if (isGenuineOfflineFailure(error)) {
    return { code: 'OFFLINE' };
  }
  // Unexpected error while not known-offline → FAIL CLOSED.
  return { code: 'SERVER_ERROR' };
}

/**
 * Run an API call under the bounded-retry / fail-closed policy. The SAME
 * `fn` (and therefore the same payload, including refund_request_id) is
 * reused on every attempt.
 */
async function requestWithRetryPolicy<T>(
  fn: () => Promise<T>,
  retry?: RetryOptions,
): Promise<{ ok: true; value: T } | { ok: false; error: RefundTransportError }> {
  const maxRetries = retry?.maxRetries ?? MAX_RETRIES;
  const backoffMs = retry?.backoffMs ?? RETRY_BACKOFF_MS;

  for (let attempt = 0; ; attempt++) {
    try {
      return { ok: true, value: await fn() };
    } catch (error) {
      if (isRetryableServerError(error) && attempt < maxRetries) {
        await delay(backoffMs);
        continue;
      }
      return { ok: false, error: classifyTerminalFailure(error) };
    }
  }
}

// ─── Local → server receipt resolution ───────────────────────────────────────

/**
 * Resolve the SERVER pos_receipts.id for a locally-scanned receipt via the
 * local receipt_qr_index (written by the sync layer). Returns null when the
 * receipt has not synced yet — the caller surfaces NOT_SYNCED.
 */
export async function resolveServerReceiptId(
  db: Database,
  identity: { receiptToken: string | null; receiptNumber: string },
): Promise<string | null> {
  if (identity.receiptToken !== null) {
    const byToken = await findReceiptByQrToken(db, identity.receiptToken);
    if (byToken !== null) return byToken.receipt_uuid;
  }
  const byNumber = await findReceiptByNumber(db, identity.receiptNumber);
  if (byNumber === null) return null;

  // Cross-check the fallback: if a token WAS scanned but the number-matched
  // entry carries a DIFFERENT (non-null) token, the receipt number collided
  // with another receipt — binding it would settle the refund against the
  // wrong receipt. Treat as not-synced rather than guessing.
  if (
    identity.receiptToken !== null &&
    byNumber.qr_token !== null &&
    byNumber.qr_token !== identity.receiptToken
  ) {
    return null;
  }

  return byNumber.receipt_uuid;
}

// ─── Server receipt fetch (unknown + type guards at the API boundary) ────────

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isServerReceiptLine(value: unknown): value is ServerReceiptLine {
  if (!isRecord(value)) return false;
  return (
    typeof value['id'] === 'string' &&
    (typeof value['product_id'] === 'string' || value['product_id'] === null) &&
    // variant_id: string on variant lines, null on non-variant lines, absent
    // on pre-variant serializations — all map to the same `?? null` identity.
    (typeof value['variant_id'] === 'string' ||
      value['variant_id'] === null ||
      value['variant_id'] === undefined) &&
    (typeof value['product_code'] === 'string' || value['product_code'] === null) &&
    typeof value['quantity'] === 'string' &&
    typeof value['unit_price'] === 'string' &&
    typeof value['returned_quantity'] === 'string'
  );
}

function parseServerReceiptLines(value: unknown): ServerReceiptLine[] | null {
  if (!isRecord(value)) return null;
  const lines = value['lines'];
  if (!Array.isArray(lines)) return null;
  if (!lines.every(isServerReceiptLine)) return null;
  return lines;
}

function isIssuedVoucher(value: unknown): value is IssuedVoucher {
  if (!isRecord(value)) return false;
  return (
    typeof value['id'] === 'string' &&
    typeof value['code'] === 'string' &&
    typeof value['initial_balance'] === 'string' &&
    typeof value['currency'] === 'string'
  );
}

function isReturnSettlementLine(value: unknown): value is ReturnSettlementLine {
  if (!isRecord(value)) return false;
  return (
    typeof value['product_name'] === 'string' &&
    typeof value['quantity'] === 'string' &&
    typeof value['unit_price'] === 'string' &&
    typeof value['line_total'] === 'string'
  );
}

function parseReturnSettlementResponse(value: unknown): ReturnSettlementResponse | null {
  if (!isRecord(value)) return null;
  if (
    typeof value['id'] !== 'string' ||
    typeof value['receipt_number'] !== 'string' ||
    typeof value['total'] !== 'string' ||
    // The AVOIR print path (Phase 3) consumes these directly — a response
    // missing them must fail closed, not reach the printer half-shaped.
    typeof value['subtotal'] !== 'string' ||
    typeof value['tax_amount'] !== 'string' ||
    typeof value['currency'] !== 'string' ||
    typeof value['posted_at'] !== 'string'
  ) {
    return null;
  }
  const qrToken = value['qr_token'];
  if (qrToken !== null && typeof qrToken !== 'string') return null;
  const voucher = value['issued_voucher'];
  if (voucher !== null && !isIssuedVoucher(voucher)) return null;
  const lines = value['lines'];
  if (!Array.isArray(lines) || !lines.every(isReturnSettlementLine)) return null;

  return value as unknown as ReturnSettlementResponse;
}

// ─── Phase 1: prepare (resolve + fetch + map) ────────────────────────────────

export interface PrepareRefundInput {
  db: Database;
  /** Raw scanned QR token (nullable for offline-issued receipts). */
  receiptToken: string | null;
  receiptNumber: string;
  /** Cart items — only `kind: 'return'` entries are mapped. */
  refundItems: CartItem[];
  retry?: RetryOptions;
}

export async function prepareRefundSettlement(
  input: PrepareRefundInput,
): Promise<PrepareRefundResult> {
  const serverReceiptId = await resolveServerReceiptId(input.db, {
    receiptToken: input.receiptToken,
    receiptNumber: input.receiptNumber,
  });
  if (serverReceiptId === null) {
    return { ok: false, error: { code: 'NOT_SYNCED' } };
  }

  const fetched = await requestWithRetryPolicy(
    () => apiGet<unknown>(`/pos/receipts/${serverReceiptId}`),
    input.retry,
  );
  if (!fetched.ok) {
    return fetched;
  }

  const serverLines = parseServerReceiptLines(fetched.value);
  if (serverLines === null) {
    // Malformed response shape — fail closed rather than guessing.
    return { ok: false, error: { code: 'SERVER_ERROR' } };
  }

  const mapping = mapRefundItemsToServerLines(input.refundItems, serverLines);
  if (!mapping.ok) {
    return {
      ok: false,
      error: {
        code: 'LINE_MAPPING_FAILED',
        reason: mapping.reason,
        itemId: mapping.itemId,
      },
    };
  }

  return {
    ok: true,
    value: { serverReceiptId, lines: mapping.lines, serverLines },
  };
}

// ─── Phase 2: submit ─────────────────────────────────────────────────────────

/**
 * Generate the idempotency key for ONE settlement attempt. Call once, then
 * reuse the value for every retry of that settlement (internal retries do
 * this automatically; caller-level retries must pass the same id back in).
 */
export function generateRefundRequestId(): string {
  return crypto.randomUUID();
}

export interface SubmitRefundInput {
  serverReceiptId: string;
  terminalId: string;
  returnReason: RefundReturnReason;
  lines: MappedReturnLine[];
  destination: PickerRefundDestination;
  refundRequestId: string;
  approval: RefundApprovalEvidence;
  notes?: string;
  overrideReason?: string;
  retry?: RetryOptions;
}

/** Build the exact StoreReturnRequest body. Pure — exported for testing. */
export function buildStoreReturnPayload(input: SubmitRefundInput): StoreReturnRequestPayload {
  return {
    terminal_id: input.terminalId,
    return_reason: input.returnReason,
    lines: input.lines,
    ...(input.notes !== undefined ? { notes: input.notes } : {}),
    refund_request_id: input.refundRequestId,
    refund_destination: toServerRefundDestination(input.destination),
    approval_id: input.approval.approval_id,
    approval_fiscal_event_id: input.approval.approval_fiscal_event_id,
    approval_scope: input.approval.approval_scope,
    approval_supervisor_user_id: input.approval.approval_supervisor_user_id,
    approval_override_event_id: input.approval.approval_override_event_id,
    authorized_by_user_id: input.approval.authorized_by_user_id,
    ...(input.overrideReason !== undefined ? { override_reason: input.overrideReason } : {}),
  };
}

export async function submitRefundReturn(input: SubmitRefundInput): Promise<SubmitRefundResult> {
  // Build ONCE — every retry posts byte-identical content (same
  // refund_request_id) so the server's idempotency replay applies.
  const payload = buildStoreReturnPayload(input);

  const posted = await requestWithRetryPolicy(
    () => apiPost<unknown>(`/pos/receipts/${input.serverReceiptId}/return`, payload),
    input.retry,
  );
  if (!posted.ok) {
    return posted;
  }

  const response = parseReturnSettlementResponse(posted.value);
  if (response === null) {
    return { ok: false, error: { code: 'SERVER_ERROR' } };
  }

  return { ok: true, response };
}
