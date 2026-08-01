/**
 * v3-refund-chain-integration spec §4.3/§7.2/§7.2a — the v4 refund's
 * append-first write-gate transaction, now with the `offline_receipts`
 * insert as a THIRD write. Sibling to `receiptService.ts` (the SALE
 * authoring path) but for the refund payout/reprint record.
 *
 * Write order inside the ONE `withWriteTransaction('fiscal', ...)` call
 * (spec §4.3, unchanged append-first ordering):
 *   1. `engine.append()` — the v4 SALE_RECEIPT/REFUND fiscal event.
 *   2. `refund_intents` update — `refund_fiscal_event_id` is only known
 *      AFTER step 1 returns (§4.3's "read-back convenience", never the
 *      idempotency mechanism).
 *   3. `offline_receipts` insert (§7.2, THIS file's new mechanism) — the
 *      AVOIR's local, negative-signed, print/report representation.
 */
import { getDatabase } from '@/lib/db';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bcformat, bcmul } from '@/lib/decimal';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { withWriteTransaction } from '@/lib/db/writeGate';
import type { FiscalEventAppendResult, SaleReceiptApprovalReferenceInput } from '@/lib/fiscal/FiscalEventEngine';
import {
  buildRefundReceiptV4Payload,
  type RefundLineInput,
} from '@/lib/fiscal/payloads/RefundReceiptV4Payload';
import type { SaleReceiptSellerInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import { markRefundEventAppended } from '@/lib/db/repositories/refundIntentRepository';
import { insertOfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import type Database from '@tauri-apps/plugin-sql';

/** Device-side quantity scale (rule 19) — must match
 *  `refundIntentRepository.ts`'s own `QUANTITY_SCALE`. */
const QUANTITY_SCALE = 4;

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** Same numbering convention `receiptService.ts`'s (private, unexported)
 *  `generateReceiptNumber()` uses. */
function generateReceiptNumber(locationCode: string, terminalCode: string, sequence: number): string {
  const year = new Date().getFullYear();
  const paddedSeq = String(sequence).padStart(8, '0');
  return `${locationCode}-${terminalCode}-${year}-${paddedSeq}`;
}

interface OfflineReceiptLine {
  product_id?: string;
  composite_item_id?: string;
  variant_id?: string;
  variant_name?: string;
  name: string;
  sku: string;
  /**
   * Wave-2 fix-wave finding 3 (codex C-2) — a negative-signed decimal
   * STRING (§7.2's sign convention applied to the canonical positive
   * quantity string the refund boundary normalized once), never a
   * number. `receiptService.ts`'s SALE rows still write a number here;
   * that is the pre-existing app-wide `CartItem.quantity` type and is
   * out of this lane's scope. Every consumer of this field reads it as
   * an opaque display/aggregation value, so the widened type is safe.
   */
  quantity: string;
  unit_price: string;
  line_total: string;
  tax_rate: string;
  tax_amount: string;
  discount_amount?: string | null;
  discount_reason?: string | null;
}

/** §7.2 — the offline_receipts row stores the SAME negative-signed shape
 *  the AVOIR was originally built/printed from (`hydrateFromReceipt.ts`'s
 *  negative CartItems, unchanged) -- a deliberate, stated difference from
 *  the positive-magnitude signed fiscal payload. */
function cartItemToOfflineReceiptLine(
  line: RefundLineInput,
  quantityScale: number,
): OfflineReceiptLine {
  const item = line.cartItem;
  return {
    product_id: item.product.sellableType === 'composite_item' ? undefined : item.product.id,
    composite_item_id: item.product.sellableType === 'composite_item' ? item.product.id : undefined,
    variant_id: item.product.variant_id,
    variant_name: item.product.variant_name,
    name: item.product.name,
    sku: item.product.sku,
    // Negative-signed by DECIMAL arithmetic on the canonical positive
    // string (§7.2 / rule 19) — never `-Math.abs(number)`.
    quantity: bcmul(line.quantity, '-1', quantityScale),
    unit_price: item.unit_price,
    line_total: item.line_total,
    tax_rate: item.tax_rate,
    tax_amount: item.tax_amount,
    discount_amount: item.discount_amount ?? null,
    discount_reason: item.discount_reason ?? null,
  };
}

export interface CreateRefundReceiptInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  terminalCode: string;
  locationCode: string;
  operatorId: string;
  operatorName: string;
  /** The CURRENT shift the refund is processed under (spec §5.2's
   *  evidence (a): the refund's own signed shift_id is later read as
   *  server-observable payout evidence for the write-off decision). */
  shiftId: string;
  businessDate: string;
  eventTimeDevice?: Date;
  currency: string;
  seller: SaleReceiptSellerInput;
  refundReason: string;
  /** Negative-signed return lines, paired with disposition + original
   *  index (spec §3.3) -- the SAME array `buildRefundReceiptV4Payload()`
   *  (item 1) normalizes to positive magnitudes internally. */
  lines: RefundLineInput[];
  /** Resolved via `resolveOriginalFiscalEventLocally()` -- carries the
   *  refusal-relevant fields AND the original's own fiscal event id for
   *  `original_receipt_reference.fiscal_event_id`. */
  original: OriginalFiscalEventLocalView;
  originalReceiptUuid: string;
  originalBusinessDate: string;
  /** The seven-field evidence `authorRefundReturnApprovalV3()` (item 3)
   *  returned, already shaped identically to
   *  `SaleReceiptApprovalReferenceInput`. */
  approvalReferences: SaleReceiptApprovalReferenceInput[];
  /** `refund_intents.id` -- becomes BOTH the fiscal event's
   *  `source_event_id` (§4.3) and the `offline_receipts` row's
   *  `idempotency_key` (§7.2). */
  refundIntentId: string;
  paymentMethodId: string;
  paymentRepositoryId: string;
}

export interface CreateRefundReceiptResult {
  fiscalEvent: FiscalEventAppendResult;
  offlineReceiptId: string;
  receiptNumber: string;
}

/**
 * Runs the three-write append-first transaction. Throws (rolling back
 * every write) on any failure -- e.g. the builder's own §3.5/§3.7
 * refusals, or a chain-head/idempotency failure inside `engine.append()`.
 */
export async function createRefundReceipt(
  input: CreateRefundReceiptInput,
): Promise<CreateRefundReceiptResult> {
  const scale = getCurrencyDecimals(input.currency);
  const eventTimeDevice = input.eventTimeDevice ?? new Date();

  // Pre-compute the cash tender leg's amount from the SAME lines
  // buildRefundReceiptV4Payload() normalizes internally -- both sides
  // apply the identical bcabs/bcadd sequence, so they agree byte-for-byte
  // (deterministic), letting the payment leg and the payload's own
  // `total` be constructed independently without a circular dependency.
  const total = input.lines.reduce(
    (sum, line) => bcadd(sum, bcabs(line.cartItem.line_total, scale), scale),
    bcformat('0', scale),
  );

  const payload = buildRefundReceiptV4Payload({
    receiptId: crypto.randomUUID(),
    terminalId: input.terminalId,
    operatorId: input.operatorId,
    operatorName: input.operatorName,
    shiftId: input.shiftId,
    currency: input.currency,
    eventTimeDevice,
    businessDate: input.businessDate,
    lines: input.lines,
    payment: { methodCode: 'CASH', amount: total },
    seller: input.seller,
    refundReason: input.refundReason,
    original: input.original,
    originalReceiptUuid: input.originalReceiptUuid,
    originalBusinessDate: input.originalBusinessDate,
    // §4.2's approval_references[] -- the evidence
    // authorRefundReturnApprovalV3() (item 3) produced, threaded straight
    // through the builder the same way a legacy discount/tender-tolerance
    // override already composes onto a sale.
    approvalReferences: [...input.approvalReferences],
  });

  // §7.2 discount_amount -- negative-signed sum of the refunded lines'
  // OWN per-line discounts (positive on each CartItem; a discount is a
  // reduction regardless of direction), never a transaction-level figure
  // (§3.5 guarantees transaction_discount_amount is always zero on a v4
  // refund).
  const positiveDiscountSum = input.lines.reduce(
    (sum, line) => bcadd(sum, bcformat(line.cartItem.discount_amount ?? '0', scale), scale),
    bcformat('0', scale),
  );
  const negativeDiscountAmount = bcmul(positiveDiscountSum, '-1', scale);

  const negativeTotal = bcmul(total, '-1', scale);
  const offlineReceiptLines: OfflineReceiptLine[] = input.lines.map((line) =>
    cartItemToOfflineReceiptLine(line, QUANTITY_SCALE),
  );

  const db = await getDatabase(input.companyId);
  const engine = await getFiscalEventEngine(input.companyId, db);
  const offlineReceiptId = crypto.randomUUID();

  const fiscalEvent = await withWriteTransaction('fiscal', async (tx) => {
    // -- 1. fiscal-event append (spec §4.3 append-first). --
    const appended = await engine.append(tx, {
      event_type: 'SALE_RECEIPT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: input.businessDate,
      payload,
      source_event_class: 'refund_intents',
      source_event_id: input.refundIntentId,
    });

    // -- 2. refund_intents update -- refund_fiscal_event_id is only known
    //    NOW (§4.3's read-back convenience, never the idempotency
    //    mechanism -- engine.append()'s own source_event_id dedup is). --
    await markRefundEventAppended(tx as unknown as Database, input.refundIntentId, appended.id);

    // -- 3. offline_receipts insert (§7.2, THIS section's new write) --
    //    the AVOIR's negative-signed, local print/report representation.
    const receiptNumber = generateReceiptNumber(
      input.locationCode,
      input.terminalCode,
      appended.sequence_number,
    );

    const offlineReceipt: Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> = {
      id: offlineReceiptId,
      idempotency_key: input.refundIntentId,
      receipt_number: receiptNumber,
      terminal_id: input.terminalId,
      terminal_code: input.terminalCode,
      operator_id: input.operatorId,
      operator_name: input.operatorName,
      lines: JSON.stringify(offlineReceiptLines),
      // Wave-2 review fix (finding 14 / codex M-2) — `subtotal` is the
      // negative NET, never a third alias of the gross total. The signed
      // v4 payload already carries the correct net (`subtotal =
      // subtotalGross − taxAmount`, SaleReceiptPayload.ts's own
      // `subtotalNet`), so it is negated here rather than recomputed:
      // one source, no drift. The §7.2a invariant this restores is
      // `subtotal + tax_amount == total` at the configured scale
      // (−10.00 + −2.00 == −12.00), which `negativeTotal` in this slot
      // violated for every taxed refund.
      subtotal: bcmul(payload.subtotal, '-1', scale),
      tax_amount: bcmul(payload.vat_total, '-1', scale),
      discount_amount: negativeDiscountAmount,
      total: negativeTotal,
      currency: input.currency,
      fiscal_hash: appended.current_hash,
      previous_hash: appended.previous_hash,
      hash_sequence: appended.sequence_number,
      // §3.5 guarantees this is always the case -- a non-zero-original-
      // discount refund never reaches payload construction at all.
      transaction_discount_amount: null,
      transaction_discount_reason: null,
      // A refund has no tender/change concept -- explicitly NULL, not
      // '0', so downstream per-row branching can distinguish "not
      // applicable" from "zero" (§7.2a).
      tendered_amount: null,
      change_due: null,
      payment_method_id: input.paymentMethodId,
      payment_repository_id: input.paymentRepositoryId,
      status: 'pending',
      // Positive-magnitude on every row, sale or refund (§7.2a) --
      // consumed by aggregateReportData's payment-method breakdown as a
      // magnitude to be added/subtracted depending on receipt_kind, not
      // as a pre-signed delta.
      payments_json: JSON.stringify([
        {
          payment_method_id: input.paymentMethodId,
          repository_id: input.paymentRepositoryId,
          amount: total,
          card_last_four: null,
          transaction_reference: null,
          method_code: 'CASH',
          instrument_type: null,
          instrument_serial: null,
        },
      ]),
      consumption_mode: null,
      table_id: null,
      // Signed, mirrors the fiscal payload's own cash_rounding_adjustment
      // -- canonical zero here (a refund payout never rounds, §7.2a).
      cash_rounding_adjustment: bcformat('0', scale),
      cash_rounding_denomination: bcformat('0', scale),
      // No tender-tolerance concept applies to a refund payout.
      tolerance_shortfall: null,
      fiscal_schema_version: 4,
      is_training: 0,
      canonical_bytes: appended.canonical_bytes,
      receipt_kind: 'refund',
    };

    await insertOfflineReceipt(tx as unknown as Database, offlineReceipt);

    return appended;
  });

  return {
    fiscalEvent,
    offlineReceiptId,
    receiptNumber: generateReceiptNumber(input.locationCode, input.terminalCode, fiscalEvent.sequence_number),
  };
}
