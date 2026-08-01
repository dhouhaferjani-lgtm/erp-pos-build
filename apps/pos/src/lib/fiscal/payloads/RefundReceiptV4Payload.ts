/**
 * RefundReceiptV4Payload — `event_version = 4` canonical SALE_RECEIPT
 * payload (v3-refund-chain-integration spec §3.2/§3.3/§3.5/§3.7).
 *
 * COMPOSES over `buildSaleReceiptV3Payload` — never a fork. `hydrateFromReceipt.ts`
 * is UNCHANGED (still produces negative-signed `CartItem`s for the on-screen
 * return cart, §3.2). This module's sole job is:
 *
 *   1. Pre-authoring refusals (§3.5, §3.7) against the ORIGINAL's own
 *      signed fields — BEFORE any normalization or payload construction.
 *   2. §3.2's four-step positive-magnitude normalization — converting the
 *      negative-signed `CartItem[]` into the positive-magnitude shape
 *      §3.1 requires.
 *   3. Delegating to `buildSaleReceiptV3Payload` with the normalized
 *      values (a v3-shaped, SALE-invoiced skeleton).
 *   4. Composing the v4 fields on top: `invoice_type_code: 'REFUND'`,
 *      `original_receipt_reference`, `original_line_references[]`,
 *      `refund_destination: 'cash'`, `settlement_allocation: null`.
 *
 * Build order is NORMATIVE (spec §3.2/§3.7) and must not be reordered:
 * training-original refusal runs BEFORE even the `kind === 'return'`
 * defense-in-depth assertion (a harder gate than a programmer-error
 * check), and the whole-discount-receipt refusal runs immediately after.
 */

import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bcformat } from '@/lib/decimal';
import type { OriginalFiscalEventLocalView } from '@/lib/db/repositories/fiscalEventRepository';
import type {
  SaleReceiptPaymentInput,
  SaleReceiptSellerInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import type { SaleReceiptApprovalReferenceInput } from '@/lib/fiscal/FiscalEventEngine';
import {
  buildSaleReceiptV3Payload,
  type SaleReceiptV3PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import type { CartItem } from '@/types/cart';

/**
 * v3-refund-chain-integration spec §3.3 — `original_line_references[i]`'s
 * exact frozen key set. `disposition`'s three literal values are the
 * exact, verbatim values of the existing PHP `ReturnLineDisposition`
 * enum (`restock`, `scrap`, `not_received`) — no new enum, no renaming.
 *
 * **Launch default, stated visibly (⚖️ coordinator ruling, wave-2 item 6):**
 * there is no disposition-picker UI at launch — `refundCheckoutStore.ts`'s
 * `begin()` stamps EVERY v4 refund line's `disposition` as `'restock'`
 * unconditionally. This is safe against the launch's single known hazard
 * (a regulated item wrongly restocked) because `PosCoreReceiptProjection`'s
 * `RestockPolicyResolver` (wave 1) already overrides regulated
 * never-restock items REGARDLESS of what the payload's own `disposition`
 * says — a `'restock'` default on the payload cannot actually restock a
 * regulated item. The accepted gap is narrower: a genuinely DAMAGED
 * (non-regulated) returned item will be restocked at launch and needs a
 * manual stock adjustment afterward — acceptable for tenant #1's single
 * terminal. A disposition-picker UI is ticketed as a post-launch follow-up
 * (§16), not attempted here.
 */
export const RETURN_LINE_DISPOSITIONS = ['restock', 'scrap', 'not_received'] as const;
export type ReturnLineDisposition = (typeof RETURN_LINE_DISPOSITIONS)[number];

export interface OriginalLineReferenceInput {
  readonly original_line_index: number;
  readonly product_id: string;
  readonly quantity: string;
  readonly disposition: ReturnLineDisposition;
}

/** One negative-signed return `CartItem`, paired with the cashier's
 *  disposition choice and the ORIGINAL's own `line_items[]` index it
 *  refunds (spec §3.3 — strict parallel-array positional alignment). */
export interface RefundLineInput {
  readonly cartItem: CartItem;
  readonly originalLineIndex: number;
  readonly disposition: ReturnLineDisposition;
  /**
   * Wave-2 fix-wave finding 3 (codex C-2) — the CANONICAL POSITIVE
   * quantity for this refund line, as a decimal STRING, normalized
   * exactly ONCE at the refund boundary (`refundCheckoutStore`'s
   * `buildV4RefundLines()`, via `bcabs(...)` at the quantity scale).
   *
   * It is then carried VERBATIM through the `refund_intents` line
   * snapshot, the cumulative-quantity cap, the `offline_receipts` line
   * mirror, and `original_line_references[i].quantity` — one derivation,
   * one string. Previously each of those four consumers re-derived its
   * own string from the number-typed `cartItem.quantity` with a
   * DIFFERENT formatter (`Math.abs(q)`, `Math.abs(q).toFixed(4)`,
   * `bcformat(String(q), 3)`), so the same source quantity could produce
   * three different strings — contaminating the cap and putting the §3.3
   * parallel-array byte-equality invariant at the mercy of coincidence.
   *
   * `cartItem.quantity` remains `number`-typed (a pre-existing, app-wide
   * `CartItem` property; retyping it is a cross-cutting refactor outside
   * this wave) and is still what `buildLineItems()` formats
   * `line_items[i].quantity` from — which is why the builder ASSERTS the
   * two agree byte-for-byte rather than assuming it.
   */
  readonly quantity: string;
}

export interface BuildRefundReceiptV4PayloadInput {
  readonly receiptId: string;
  readonly terminalId: string;
  readonly operatorId: string;
  readonly operatorName: string;
  readonly shiftId: string;
  readonly currency: string;
  readonly eventTimeDevice: Date;
  readonly businessDate: string;
  readonly lines: readonly RefundLineInput[];
  /** Single CASH leg only (§3.7) — `methodCode` MUST be exactly `'CASH'`
   *  and `instrumentType` MUST be omitted/null. Validated defensively
   *  below; the device UI never offers another destination at launch
   *  (§3.4, single `'cash'` literal). */
  readonly payment: SaleReceiptPaymentInput;
  readonly seller: SaleReceiptSellerInput;
  readonly refundReason: string;
  /** The ORIGINAL's own resolved local fiscal-event view — from
   *  `resolveOriginalFiscalEventLocally()`. Callers resolve this BEFORE
   *  invoking this builder so the §3.5/§3.7 refusals below can run
   *  immediately, without this module owning any DB access itself. */
  readonly original: OriginalFiscalEventLocalView;
  readonly originalReceiptUuid: string;
  readonly originalBusinessDate: string;
  /** §4.2's approval evidence -- the seven-field record
   *  `authorRefundReturnApprovalV3()` (a sibling module) produced, already
   *  shaped identically to `SaleReceiptApprovalReferenceInput`. Defaults
   *  to `[]` when omitted, matching `buildSaleReceiptPayload()`'s own
   *  default. */
  readonly approvalReferences?: SaleReceiptApprovalReferenceInput[];
}

export type RefundReceiptV4Payload = SaleReceiptV3PayloadInput & {
  readonly original_line_references: OriginalLineReferenceInput[];
  readonly refund_destination: 'cash';
  readonly settlement_allocation: null;
};

/**
 * v3-refund-chain-integration spec §3.7 — thrown when the resolved
 * original's own signed `payload.training_flag === true`. Read from the
 * ORIGINAL's signed payload, never the CURRENT session's training-mode
 * context (`PosOverrideContext.isTraining` — an unrelated concept: this
 * register presently being in training mode, not whether the original
 * sale being refunded was a training transaction).
 */
export class TrainingOriginalRefundRefusedError extends Error {
  /** Stable key for the (not-yet-built) UI catch block to resolve via
   *  `t()` — this module stays i18n-library-free. */
  readonly i18nKey = 'refundFlow.trainingOriginalRefused';

  constructor(public readonly originalReceiptUuid: string) {
    super(
      `Refund refused: original receipt ${originalReceiptUuid} was a TRAINING transaction (payload.training_flag=true). Training originals cannot be refunded.`,
    );
    this.name = 'TrainingOriginalRefundRefusedError';
  }
}

/**
 * v3-refund-chain-integration spec §3.5 — ⚖️ orchestrator ruling: launch
 * REFUSES any refund, partial or full, of a receipt whose ORIGINAL
 * `transaction_discount_amount` is non-zero. No proration algorithm is
 * built this launch (§16.4, roadmap).
 */
export class WholeReceiptDiscountRefundRefusedError extends Error {
  readonly i18nKey = 'refundFlow.wholeDiscountReceiptRefused';

  constructor(
    public readonly originalReceiptUuid: string,
    public readonly transactionDiscountAmount: string,
  ) {
    super(
      `Refund refused: original receipt ${originalReceiptUuid} has a non-zero transaction_discount_amount (${transactionDiscountAmount}). Whole-receipt-discount refunds are not available in this launch (spec §3.5/§16.4).`,
    );
    this.name = 'WholeReceiptDiscountRefundRefusedError';
  }
}

/**
 * v3-refund-chain-integration spec §9.6 — wave-2 fix-wave finding 8
 * (fiscal C-5). "The cash-only launch payload (§3) simply cannot represent
 * a refund of an original that wasn't cash-tendered — such an attempt is
 * REFUSED by the same typed mechanism as any other unsupported
 * destination." That refusal did not exist: the original's `payments[]`
 * was resolved into `OriginalFiscalEventLocalView` and then never read,
 * while `createRefundReceipt()` unconditionally built a single CASH payout
 * leg. A €200 CARD purchase could therefore be refunded €200 in CASH out
 * of the drawer, with the card leg never reversed — an uncontrolled
 * cash-out-for-card-sale channel.
 *
 * The check is deliberately conservative: it demands EXACTLY ONE payment
 * leg whose `method_code` is `CASH` (case-insensitively — `method_code` is
 * the tenant's own `payment_methods.code`, opaque at the payload boundary,
 * and the canonical v4 refund leg spells it uppercase). A mixed tender, a
 * card tender, a voucher tender, an empty `payments[]`, or a
 * structurally-odd member all fail closed.
 */
export class NonCashOriginalRefundRefusedError extends Error {
  readonly i18nKey = 'refundFlow.nonCashOriginalRefused';

  constructor(
    public readonly originalReceiptUuid: string,
    public readonly methodCodes: readonly string[],
  ) {
    super(
      `Refund refused: original receipt ${originalReceiptUuid} was not tendered as a single CASH leg (method codes: ${JSON.stringify(methodCodes)}). This launch pays refunds out in cash only, and can only refund cash-tendered originals (spec §9.6).`,
    );
    this.name = 'NonCashOriginalRefundRefusedError';
  }
}

/**
 * §3.2 step 1's defense-in-depth assertion: every input line must already
 * be classified `kind === 'return'` by `cartClassification.ts` before it
 * ever reaches this builder. A violation here is a programmer error, not
 * a user-facing refusal — it throws, never silently coerces (matching the
 * existing `LineArithmeticInvariantError` fail-loud convention).
 */
export class RefundLineNotAReturnError extends Error {
  constructor(public readonly index: number) {
    super(
      `RefundReceiptV4Payload: lines[${String(index)}].cartItem.kind must be 'return'; the classification boundary at cartClassification.ts should already guarantee this.`,
    );
    this.name = 'RefundLineNotAReturnError';
  }
}

/**
 * §3.7's single-cash-leg contract violated at the BUILDER boundary
 * (defense-in-depth — `FiscalEventEngine`'s own validator re-checks this
 * independently once the payload is authored).
 */
export class RefundPaymentNotSingleCashLegError extends Error {
  constructor(message: string) {
    super(`RefundReceiptV4Payload: ${message}`);
    this.name = 'RefundPaymentNotSingleCashLegError';
  }
}

/**
 * Wave-2 fix-wave finding 3 — §3.3's parallel-array invariant, asserted.
 * `original_line_references[i].quantity` MUST be byte-identical to
 * `line_items[i].quantity`; the two are derived by different code paths
 * (this module's canonical `RefundLineInput.quantity` string vs
 * `buildLineItems()`'s formatting of the number-typed
 * `CartItem.quantity`), so their agreement is PROVED here, not assumed.
 * A violation is a programmer error and fails loud — never a silent
 * fallback that signs two disagreeing quantities into one payload.
 */
export class RefundQuantityAlignmentError extends Error {
  constructor(
    public readonly index: number,
    public readonly referenceQuantity: string,
    public readonly signedLineQuantity: string | null,
  ) {
    super(
      `RefundReceiptV4Payload: original_line_references[${String(index)}].quantity (${referenceQuantity}) does not match line_items[${String(index)}].quantity (${signedLineQuantity ?? 'MISSING'}). §3.3's parallel-array invariant is violated.`,
    );
    this.name = 'RefundQuantityAlignmentError';
  }
}

/** §3.1 — quantity is frozen at 3 decimal places in the canonical payload. */
const FROZEN_QUANTITY_SCALE = 3;

/**
 * §3.5 errata T7 — the refusal's PRIMARY enforcement point: the spec
 * requires this check to run at the LOOKUP level (immediately after
 * `resolveOriginalFiscalEventLocally()`, in the refund flow's own
 * original-resolution step, §4.1) — BEFORE any approval authoring is
 * attempted, not merely inside this builder (which only runs much later,
 * after a manager PIN has already been spent). Extracted so
 * `refundCheckoutStore.ts`'s `begin()` and this builder's own Step 0/0.5
 * below share the EXACT same two checks and the exact same typed errors —
 * never two independent implementations that could drift.
 */
export function assertOriginalRefundable(
  original: OriginalFiscalEventLocalView,
  originalReceiptUuid: string,
): void {
  // -- §3.7 — training-original refusal. Read from the ORIGINAL's OWN
  //    signed payload.training_flag, never the current session's
  //    training-mode context (an unrelated concept).
  if (original.trainingFlag) {
    throw new TrainingOriginalRefundRefusedError(originalReceiptUuid);
  }

  // -- §3.5 — whole-receipt-discount refusal. The orchestrator ruling
  //    refuses BOTH partial and full refunds of a discounted original, no
  //    carve-out.
  if (bcformatIsNonZero(original.transactionDiscountAmount)) {
    throw new WholeReceiptDiscountRefundRefusedError(
      originalReceiptUuid,
      original.transactionDiscountAmount,
    );
  }

  // -- §9.6 (wave-2 fix-wave finding 8) — non-cash-original refusal. Same
  //    lookup-level placement and same one-implementation/two-call-sites
  //    discipline as the two refusals above.
  const methodCodes = original.payments.map((payment) =>
    typeof payment?.method_code === 'string' ? payment.method_code : '',
  );
  const isSingleCashLeg =
    methodCodes.length === 1 && methodCodes[0]!.trim().toUpperCase() === 'CASH';
  if (!isSingleCashLeg) {
    throw new NonCashOriginalRefundRefusedError(originalReceiptUuid, methodCodes);
  }
}

export function buildRefundReceiptV4Payload(
  input: BuildRefundReceiptV4PayloadInput,
): RefundReceiptV4Payload {
  // -- Step 0/0.5 (spec §3.5/§3.7) — re-asserted here as defense-in-depth
  //    (the store's begin() is the PRIMARY enforcement point, see
  //    assertOriginalRefundable's own docblock) — before any normalization
  //    or payload construction.
  assertOriginalRefundable(input.original, input.originalReceiptUuid);

  const scale = getCurrencyDecimals(input.currency);

  // -- Step 1 (spec §3.2) — defense-in-depth: every input line must
  //    already be classified 'return'.
  input.lines.forEach((line, index) => {
    if (line.cartItem.kind !== 'return') {
      throw new RefundLineNotAReturnError(index);
    }
  });

  // -- §3.7 single-cash-leg contract, checked at the builder boundary. --
  if (input.payment.methodCode !== 'CASH') {
    throw new RefundPaymentNotSingleCashLegError(
      `payment.methodCode must be exactly 'CASH'; got ${JSON.stringify(input.payment.methodCode)}.`,
    );
  }
  if (input.payment.instrumentType != null) {
    throw new RefundPaymentNotSingleCashLegError(
      `payment.instrumentType must be null/omitted on a v4 refund's single cash leg; got ${JSON.stringify(input.payment.instrumentType)}.`,
    );
  }

  // -- Step 2 (spec §3.2) — positive-magnitude normalization. quantity/
  //    line_total/tax_amount are bcabs'd; unit_price is already positive
  //    on a return CartItem (only the derived totals carry the negative
  //    sign) and is passed through unchanged; discount_amount is already
  //    a non-negative magnitude on both sale and return lines (a discount
  //    is a reduction regardless of direction) and is passed through
  //    unchanged, no bcabs() needed.
  //
  // Wave-2 fix-wave finding 3 — where the canonical quantity STRING lives
  // and where a number is still unavoidable.
  //
  // The authoritative quantity for this refund is `line.quantity`: a
  // canonical positive decimal string normalized ONCE at the refund
  // boundary and carried verbatim through the intent snapshot, the
  // cumulative-quantity cap, the `offline_receipts` line mirror and
  // `original_line_references[i].quantity` (Step 4). That is the whole of
  // the "decimal string end-to-end" contract.
  //
  // The ONE remaining numeric hop is narrow and structural:
  // `CartItem.quantity` is `number`-typed app-wide, and the SHARED
  // `buildSaleReceiptV3Payload`/`buildLineItems()` path (the SALE path,
  // deliberately untouched by this lane) reads it to derive both the gross
  // line-total identity check and `line_items[i].quantity`. Retyping
  // `CartItem.quantity` is a cross-cutting refactor outside this wave.
  //
  // `Math.abs` is used rather than `Number(line.quantity)`: on an existing
  // number it flips the sign bit EXACTLY, with no parse, whereas coercing
  // the string back to a float is a genuine float conversion (and is what
  // the `no-parsefloat-on-money` guard exists to stop). Either way the
  // result is PROVED correct rather than assumed — Step 4 asserts
  // `line_items[i].quantity` is byte-identical to the canonical string.
  const normalizedCartItems: CartItem[] = input.lines.map((line) => {
    const item = line.cartItem;
    return {
      ...item,
      quantity: Math.abs(item.quantity),
      line_total: bcabs(item.line_total, scale),
      tax_amount: bcabs(item.tax_amount, scale),
      kind: 'sale',
    };
  });

  const subtotalGross = normalizedCartItems.reduce(
    (sum, item) => bcadd(sum, item.line_total, scale),
    bcformat('0', scale),
  );
  const taxAmount = normalizedCartItems.reduce(
    (sum, item) => bcadd(sum, item.tax_amount, scale),
    bcformat('0', scale),
  );
  const total = bcformat(subtotalGross, scale);

  // -- Step 3 (spec §3.2) — delegate to buildSaleReceiptV3Payload with the
  //    normalized (positive) values. transaction_discount_amount is
  //    unconditionally canonical zero (§3.5 — a non-zero-original-discount
  //    refund never reaches this point, so this is a structural
  //    consequence of the refusal above, not a new runtime branch). No
  //    cash rounding on a refund payout (spec §7.2a: cash_rounding_adjustment
  //    is signed and mirrors the fiscal payload's own value, canonical
  //    zero when unrounded — a refund never rounds).
  const v3Skeleton = buildSaleReceiptV3Payload(
    {
      receiptId: input.receiptId,
      terminalId: input.terminalId,
      operatorId: input.operatorId,
      operatorName: input.operatorName,
      shiftId: input.shiftId,
      currency: input.currency,
      eventTimeDevice: input.eventTimeDevice,
      businessDate: input.businessDate,
      cartItems: normalizedCartItems,
      subtotalGross,
      taxAmount,
      total,
      transactionDiscountAmount: bcformat('0', scale),
      transactionDiscountReason: null,
      payments: [input.payment],
      isTraining: false,
      seller: input.seller,
      approvalReferences: input.approvalReferences,
    },
    {
      exactTotal: total,
      roundedTotal: total,
      adjustment: bcformat('0', scale),
      denomination: bcformat('0', scale),
    },
  );

  // -- Step 4 (spec §3.2/§3.3/§3.4) — compose the v4 fields on top. --
  //
  // Wave-2 fix-wave finding 3: the reference quantity is the CANONICAL
  // decimal string normalized once at the refund boundary, formatted at
  // the frozen payload scale — never a second, independently-derived
  // float formatting of `cartItem.quantity`. §3.3's parallel-array
  // invariant (`original_line_references[i].quantity` byte-identical to
  // `line_items[i].quantity`) is then ASSERTED rather than assumed: the
  // old `?? bcformat(String(Math.abs(...)), 3)` fallback was a silent
  // second derivation that could disagree with `buildLineItems()`.
  const originalLineReferences: OriginalLineReferenceInput[] = input.lines.map(
    (line, index) => {
      const signedLine = v3Skeleton.line_items[index];
      if (signedLine === undefined) {
        throw new RefundQuantityAlignmentError(index, line.quantity, null);
      }
      const referenceQuantity = bcformat(line.quantity, FROZEN_QUANTITY_SCALE);
      if (signedLine.quantity !== referenceQuantity) {
        throw new RefundQuantityAlignmentError(index, referenceQuantity, signedLine.quantity);
      }
      return {
        disposition: line.disposition,
        original_line_index: line.originalLineIndex,
        product_id: signedLine.product_id,
        quantity: referenceQuantity,
      };
    },
  );

  return {
    ...v3Skeleton,
    invoice_type_code: 'REFUND',
    original_line_references: originalLineReferences,
    original_receipt_reference: {
      fiscal_event_id: input.original.fiscalEventId,
      original_business_date: input.originalBusinessDate,
      original_receipt_uuid: input.originalReceiptUuid,
      refund_reason: input.refundReason,
    },
    refund_destination: 'cash',
    settlement_allocation: null,
  };
}

function bcformatIsNonZero(value: string): boolean {
  return !/^0(\.0+)?$/.test(value.trim());
}
