/**
 * SaleReceiptV5 — `event_version = 5` canonical SALE_RECEIPT
 * (D-1 post-remise VAT base, owner ruling 2026-08-25 option (a)).
 *
 * ## What changes at v5
 *
 * `subtotal`, `vat_total` and every `vat_breakdown[]` row are sealed NET of the
 * ticket-level remise, ventilated pro-rata per rate
 * (`lib/fiscal/vatDiscountAllocation`), and each breakdown row gains
 * `discount_allocated` — that group's share of `transaction_discount_amount`.
 *
 * The aggregate identity flips with it:
 *   - v1/v2/v3: `subtotal + vat_total == (total − adjustment) + discount`
 *     (the discount is added BACK, because the base was the PRE-discount gross);
 *   - v5:       `subtotal + vat_total == total − adjustment`
 *     (the base is already net of the remise), plus
 *     `Σ vat_breakdown[].discount_allocated == transaction_discount_amount`.
 *
 * ## Why this is a NEW builder, not an edit
 *
 * Rule 8 — Events are Immutable Forever. V1/V2/V3 keep authoring and verifying
 * the pre-D-1 bytes for every receipt already in a chain, and for every device
 * still on an older build (the cutover is forward-only; the server accepts v3
 * forever). V5 therefore composes the SAME unmutated line/seller/payment/
 * voucher builders V1 and V2 use — it just replaces the VAT roll-up and the
 * aggregate assert.
 *
 * V5 is a superset of V3's shape: it keeps the two signed cash-rounding fields
 * and reuses `assertSaleReceiptAggregatesV3`'s rounding binds verbatim, so the
 * rounding contract cannot drift between the two versions.
 *
 * ## 100 %-comp tickets (G3-A fold-in)
 *
 * A fully comped ticket tenders nothing, and `pos_receipt_payments
 * CHECK (amount > 0)` refuses the 0.000 leg the device used to emit. At v5 the
 * device emits NO tender row for a full comp; the discount line carries the
 * story. Every group lands on exactly `net == vat == 0` (the allocator clamps
 * the split inside the group's own line sums), so the aggregate identity holds
 * with `total == '0'`.
 */

import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
import type { VatBreakdownInput } from '@/lib/fiscal/FiscalEventEngine';
import {
  SaleReceiptAggregateInvariantError,
  SaleReceiptPayloadInputError,
  buildLineItems,
  buildPayments,
  buildSellerBlock,
  buildVouchersRedeemed,
  mapConsumptionMode,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import {
  enrichLineItemsWithVariantIdentity,
  type LineItemV2Input,
} from '@/lib/fiscal/payloads/SaleReceiptV2Payload';
import {
  assertSaleReceiptAggregatesV3,
  type SaleReceiptV3PayloadInput,
  type SaleReceiptV3RoundingInput,
} from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
import {
  allocateTransactionDiscount,
  type VatGroupLineSums,
} from '@/lib/fiscal/vatDiscountAllocation';

/** One `vat_breakdown[]` row at v5 — the v3 row plus its allocated remise. */
export interface VatBreakdownV5Input extends VatBreakdownInput {
  /** This group's pro-rata share of `transaction_discount_amount`. */
  readonly discount_allocated: string;
}

export interface SaleReceiptV5PayloadInput
  extends Omit<SaleReceiptV3PayloadInput, 'vat_breakdown'> {
  readonly vat_breakdown: ReadonlyArray<VatBreakdownV5Input>;
}

export function buildSaleReceiptV5Payload(
  input: BuildSaleReceiptPayloadInput,
  rounding: SaleReceiptV3RoundingInput,
): SaleReceiptV5PayloadInput {
  const scale = getCurrencyDecimals(input.currency);
  if (scale !== 0 && scale !== 2 && scale !== 3) {
    throw new SaleReceiptPayloadInputError(
      `Unsupported currency scale ${String(scale)} for ${input.currency}; expected 0, 2, or 3.`,
    );
  }

  const seller = buildSellerBlock(input.seller);
  const lineItems: LineItemV2Input[] = enrichLineItemsWithVariantIdentity(
    buildLineItems(input.cartItems, scale),
    input.cartItems,
  );
  const payments = buildPayments(input.payments, scale);
  const vouchersRedeemed = buildVouchersRedeemed(input.payments, scale);

  const discountAmount = bcformat(input.transactionDiscountAmount, scale);
  const discountReason = input.transactionDiscountReason;
  if (bccomp(discountAmount, '0') > 0 && (discountReason === null || discountReason === '')) {
    throw new SaleReceiptPayloadInputError(
      'transaction_discount_reason is required when transaction_discount_amount is non-zero.',
    );
  }

  // The cart aggregates the checkout gate signed OFF on must reconcile with
  // the canonical line roll-up before anything is ventilated — a mismatch here
  // means the screen and the ticket disagree, and nothing may be signed.
  assertCartAggregatesMatchLines(lineItems, input, scale);

  const allocated = allocateTransactionDiscount(
    groupLinesByRate(lineItems, scale),
    discountAmount,
    scale,
  );

  const vatBreakdown: VatBreakdownV5Input[] = allocated.map((group) => ({
    discount_allocated: group.discountAllocated,
    gross_amount: group.grossAmount,
    net_amount: group.netAmount,
    rate: group.rate,
    tax_category_code: group.category,
    vat_amount: group.vatAmount,
  }));

  const subtotal = sumAt(vatBreakdown.map((r) => r.net_amount), scale);
  const vatTotal = sumAt(vatBreakdown.map((r) => r.vat_amount), scale);

  const payload: SaleReceiptV5PayloadInput = {
    approval_references: input.approvalReferences ?? [],
    business_date: input.businessDate,
    buyer: input.buyer ?? null,
    cash_rounding_adjustment: canonicalMoney(rounding.adjustment, scale),
    cash_rounding_denomination: canonicalMoney(rounding.denomination, scale),
    cashier_id: input.operatorId,
    cashier_name: input.operatorName,
    consumption_mode: mapConsumptionMode(input.consumptionMode ?? null),
    currency_code: input.currency,
    currency_scale: scale,
    event_time_device: input.eventTimeDevice.toISOString(),
    invoice_type_code: input.isTraining ? 'TRAINING' : 'SALE',
    line_items: lineItems,
    lottery_code: null,
    notes: null,
    original_receipt_reference: null,
    payments,
    receipt_uuid: input.receiptId,
    seller,
    shift_id: input.shiftId,
    subtotal,
    table_id: input.tableId ?? null,
    terminal_id: input.terminalId,
    total: canonicalMoney(rounding.roundedTotal, scale),
    training_flag: input.isTraining,
    transaction_discount_amount: discountAmount,
    transaction_discount_reason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
    vat_breakdown: vatBreakdown,
    vat_total: vatTotal,
    vouchers_redeemed: vouchersRedeemed,
  };

  assertSaleReceiptAggregatesV5(payload, scale);

  return payload;
}

/**
 * v5 aggregate identity + rounding binds. EXACT comparison at currency scale,
 * never a tolerance.
 *
 *   1. `subtotal + vat_total == total − cash_rounding_adjustment`
 *      (the discount is NOT added back — the base is already net of it).
 *   2. `Σ vat_breakdown[].discount_allocated == transaction_discount_amount`.
 *   3. `Σ net_amount == subtotal`, `Σ vat_amount == vat_total`.
 *   4. per group: `gross_amount == net_amount + vat_amount`, and every money
 *      field non-negative.
 *   5. the v3 rounding binds, verbatim, via `assertSaleReceiptAggregatesV3`.
 */
export function assertSaleReceiptAggregatesV5(
  payload: SaleReceiptV5PayloadInput,
  scale: number,
): void {
  const zero = bcformat('0', scale);

  // 1. the flipped identity.
  const lhs = bcformat(bcadd(payload.subtotal, payload.vat_total, scale), scale);
  const rhs = bcformat(bcsub(payload.total, payload.cash_rounding_adjustment, scale), scale);
  if (bccomp(lhs, rhs) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: subtotal (${payload.subtotal}) + vat_total `
      + `(${payload.vat_total}) = ${lhs} != total (${payload.total}) - `
      + `cash_rounding_adjustment (${payload.cash_rounding_adjustment}) = ${rhs}. `
      + 'At v5 the taxable base is already net of the transaction discount; it '
      + 'must NOT be added back.',
    );
  }

  let sumNet = zero;
  let sumVat = zero;
  let sumDiscount = zero;
  for (const group of payload.vat_breakdown) {
    for (const [field, value] of [
      ['net_amount', group.net_amount],
      ['vat_amount', group.vat_amount],
      ['gross_amount', group.gross_amount],
      ['discount_allocated', group.discount_allocated],
    ] as const) {
      if (bccomp(value, '0') < 0) {
        throw new SaleReceiptAggregateInvariantError(
          `V5 aggregate invariant violated: vat_breakdown group (rate ${group.rate}, `
          + `category "${group.tax_category_code}") ${field} ${value} is negative.`,
        );
      }
    }

    const groupGross = bcformat(bcadd(group.net_amount, group.vat_amount, scale), scale);
    if (bccomp(groupGross, group.gross_amount) !== 0) {
      throw new SaleReceiptAggregateInvariantError(
        `V5 aggregate invariant violated: vat_breakdown group (rate ${group.rate}, `
        + `category "${group.tax_category_code}") gross_amount ${group.gross_amount} `
        + `!= net_amount (${group.net_amount}) + vat_amount (${group.vat_amount}) = ${groupGross}.`,
      );
    }

    sumNet = bcformat(bcadd(sumNet, group.net_amount, scale), scale);
    sumVat = bcformat(bcadd(sumVat, group.vat_amount, scale), scale);
    sumDiscount = bcformat(bcadd(sumDiscount, group.discount_allocated, scale), scale);
  }

  if (bccomp(sumNet, payload.subtotal) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: Σ vat_breakdown.net_amount (${sumNet}) != subtotal ${payload.subtotal}.`,
    );
  }
  if (bccomp(sumVat, payload.vat_total) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: Σ vat_breakdown.vat_amount (${sumVat}) != vat_total ${payload.vat_total}.`,
    );
  }
  if (bccomp(sumDiscount, payload.transaction_discount_amount) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: Σ vat_breakdown.discount_allocated (${sumDiscount}) `
      + `!= transaction_discount_amount ${payload.transaction_discount_amount}.`,
    );
  }

  // 5. the v3 rounding binds, unchanged. The v3 aggregate identity itself is
  //    NOT reused (it adds the discount back); this call is reached with a
  //    payload whose v3-shaped identity is deliberately different, so the
  //    identity check is done above and only the rounding binds are borrowed.
  assertSaleReceiptAggregatesV3(
    {
      ...payload,
      // Feed the v3 assert a discount-free view: its identity term
      // `(total − adjustment) + discount` then reduces to the v5 identity
      // already proven above, and its rounding binds run verbatim.
      transaction_discount_amount: bcformat('0', scale),
      vat_breakdown: payload.vat_breakdown.map(({ discount_allocated: _d, ...rest }) => rest),
    },
    scale,
  );
}

/** Roll the canonical line items up per `(vat_rate, tax_category_code)`. */
function groupLinesByRate(
  lineItems: ReadonlyArray<LineItemV2Input>,
  scale: number,
): VatGroupLineSums[] {
  const groups = new Map<string, { rate: string; category: string; lineNet: string; lineVat: string }>();
  for (const line of lineItems) {
    const key = `${line.vat_rate}|${line.tax_category_code}`;
    const current = groups.get(key) ?? {
      rate: line.vat_rate,
      category: line.tax_category_code,
      lineNet: bcformat('0', scale),
      lineVat: bcformat('0', scale),
    };
    current.lineNet = bcformat(bcadd(current.lineNet, line.line_subtotal, scale), scale);
    current.lineVat = bcformat(bcadd(current.lineVat, line.line_vat, scale), scale);
    groups.set(key, current);
  }

  return [...groups.values()];
}

/**
 * The cart totals handed in must equal the canonical line roll-up.
 *
 * V1 enforced this implicitly through `subtotalNet = subtotalGross − taxAmount`
 * feeding its aggregate assert. V5 derives `subtotal`/`vat_total` from the
 * lines instead, so the cart aggregates would otherwise be UNCHECKED — a cart
 * bug could put the screen and the sealed ticket in disagreement with nothing
 * catching it.
 */
function assertCartAggregatesMatchLines(
  lineItems: ReadonlyArray<LineItemV2Input>,
  input: BuildSaleReceiptPayloadInput,
  scale: number,
): void {
  const lineGross = sumAt(
    lineItems.map((l) => bcformat(bcadd(l.line_subtotal, l.line_vat, scale), scale)),
    scale,
  );
  const lineVat = sumAt(lineItems.map((l) => l.line_vat), scale);
  const cartGross = bcformat(input.subtotalGross, scale);
  const cartVat = bcformat(input.taxAmount, scale);

  if (bccomp(lineGross, cartGross) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: Σ line gross (${lineGross}) != cart subtotalGross ${cartGross}.`,
    );
  }
  if (bccomp(lineVat, cartVat) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V5 aggregate invariant violated: Σ line_vat (${lineVat}) != cart taxAmount ${cartVat}.`,
    );
  }
}

function sumAt(values: ReadonlyArray<string>, scale: number): string {
  let acc = bcformat('0', scale);
  for (const value of values) {
    acc = bcformat(bcadd(acc, value, scale), scale);
  }

  return acc;
}

/**
 * Format at `scale` and collapse every BCMath-equivalent zero onto the unsigned
 * canonical zero — big.js does not fully normalize `-0`, and the server rejects
 * `payload_money_negative_zero` on bytes that can never be repaired in place.
 * Mirrors `SaleReceiptV3Payload.canonicalMoney` (deliberately duplicated: what
 * may be SIGNED must not change because a sibling version changed).
 */
function canonicalMoney(value: string, scale: number): string {
  const formatted = bcformat(value, scale);

  return bccomp(formatted, '0') === 0 ? bcformat('0', scale) : formatted;
}
