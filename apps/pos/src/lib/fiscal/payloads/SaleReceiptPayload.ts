import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcmul, bcsub } from '@/lib/decimal';
import type {
  AddressInput,
  LineItemInput,
  PaymentInput,
  SaleReceiptApprovalReferenceInput,
  SaleReceiptPayloadInput,
  SellerBlockInput,
  VatBreakdownInput,
  VoucherRedeemedInput,
} from '@/lib/fiscal/FiscalEventEngine';
import type { CartItem } from '@/types/cart';

export class SaleReceiptPayloadInputError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SaleReceiptPayloadInputError';
  }
}

/**
 * Thrown when a cart line is internally inconsistent at canonical-build time.
 *
 * The device authors the canonical receipt + hash; the server stores it
 * verbatim and verifies by re-hashing the bytes — it does NOT recompute the
 * arithmetic. So a cart bug (or a modifier `price_adjustment` not folded into
 * `unit_price`/`discount`) could produce a line whose `line_total` differs from
 * `unit_price * quantity - line_discount_amount`, and the hash would still
 * verify (the chain proves *un-tampered*, not *arithmetically correct*). This
 * error is the device-side guarantee of arithmetic correctness; it is NOT part
 * of the hash chain. A line that trips it must never be signed.
 */
export class LineArithmeticInvariantError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'LineArithmeticInvariantError';
  }
}

/**
 * Thrown when the receipt's own ticket AGGREGATES are internally inconsistent
 * at canonical-build time.
 *
 * NF525 secures the ticket aggregates (subtotal / vat_total / total /
 * vat_breakdown) — these carry the VAT-declaration integrity. The device
 * authors and signs them; the server stores the bytes verbatim and re-hashes,
 * so an internally-inconsistent aggregate (e.g. subtotal + vat_total != total,
 * or a vat_breakdown that doesn't sum to the declared totals) would hash +
 * verify fine yet be fiscally wrong. This error guarantees the device never
 * signs an internally-inconsistent ticket; it is NOT part of the hash chain.
 */
export class SaleReceiptAggregateInvariantError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'SaleReceiptAggregateInvariantError';
  }
}

export interface SaleReceiptSellerInput {
  name: string | null | undefined;
  taxNumber: string | null | undefined;
  countryCode: string | null | undefined;
  street: string | null | undefined;
  city: string | null | undefined;
  postalCode: string | null | undefined;
}

export interface SaleReceiptPaymentInput {
  methodCode: string;
  amount: string;
  instrumentType?: 'store_voucher' | 'restaurant_voucher' | 'gift_card' | null;
  instrumentSerial?: string | null;
}

export interface BuildSaleReceiptPayloadInput {
  receiptId: string;
  terminalId: string;
  operatorId: string;
  operatorName: string;
  shiftId: string;
  currency: string;
  eventTimeDevice: Date;
  businessDate: string;
  cartItems: CartItem[];
  subtotalGross: string;
  taxAmount: string;
  total: string;
  transactionDiscountAmount: string;
  transactionDiscountReason: string | null;
  payments: SaleReceiptPaymentInput[];
  consumptionMode?: string | null;
  tableId?: string | null;
  isTraining: boolean;
  seller: SaleReceiptSellerInput;
  approvalReferences?: SaleReceiptApprovalReferenceInput[];
}

interface VatAccumulator {
  rate: string;
  category: string;
  net: string;
  vat: string;
}

export function buildSaleReceiptPayload(
  input: BuildSaleReceiptPayloadInput,
): SaleReceiptPayloadInput {
  const scale = getCurrencyDecimals(input.currency);
  if (scale !== 0 && scale !== 2 && scale !== 3) {
    throw new SaleReceiptPayloadInputError(
      `Unsupported currency scale ${String(scale)} for ${input.currency}; expected 0, 2, or 3.`,
    );
  }

  const seller = buildSellerBlock(input.seller);
  const lineItems = buildLineItems(input.cartItems, scale);
  const vatBreakdown = buildVatBreakdown(lineItems, scale);
  const payments = buildPayments(input.payments, scale);
  const vouchersRedeemed = buildVouchersRedeemed(input.payments, scale);
  const subtotalNet = bcformat(bcsub(input.subtotalGross, input.taxAmount), scale);
  const discountAmount = bcformat(input.transactionDiscountAmount, scale);
  const discountReason = input.transactionDiscountReason;

  if (bccomp(discountAmount, '0') > 0 && (discountReason === null || discountReason === '')) {
    throw new SaleReceiptPayloadInputError(
      'transaction_discount_reason is required when transaction_discount_amount is non-zero.',
    );
  }

  const total = bcformat(input.total, scale);
  const vatTotal = bcformat(input.taxAmount, scale);

  // ── Ticket-aggregate invariant (NF525 VAT-declaration integrity) ─────────
  // The device authors + signs the aggregates; the server stores the bytes
  // verbatim and re-hashes — it does NOT recompute prices. So the device must
  // guarantee its own aggregates add up before signing:
  //   1. subtotal + vat_total == total + transaction_discount_amount
  //   2. Σ vat_breakdown[].net_amount == subtotal
  //   3. Σ vat_breakdown[].vat_amount == vat_total
  //   4. per group: gross_amount == net_amount + vat_amount
  // All comparisons EXACT (bccomp == 0) at currency scale, never a tolerance.
  // The subtotal/vat_total describe pre-transaction-discount gross; the device
  // computes total = subtotalGross − transaction_discount_amount, so the
  // identity must add the discount back to total to be symmetric with the
  // server's validateSaleReceiptAggregateConsistency.
  assertSaleReceiptAggregates(subtotalNet, vatTotal, total, discountAmount, vatBreakdown, scale);

  return {
    approval_references: input.approvalReferences ?? [],
    business_date: input.businessDate,
    buyer: null,
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
    subtotal: subtotalNet,
    table_id: input.tableId ?? null,
    terminal_id: input.terminalId,
    total,
    training_flag: input.isTraining,
    transaction_discount_amount: discountAmount,
    transaction_discount_reason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
    vat_breakdown: vatBreakdown,
    vat_total: vatTotal,
    vouchers_redeemed: vouchersRedeemed,
  };
}

/**
 * Verify the receipt's ticket aggregates are internally consistent before the
 * canonical payload is finalized and signed. EXACT comparison at currency scale.
 */
function assertSaleReceiptAggregates(
  subtotal: string,
  vatTotal: string,
  total: string,
  transactionDiscountAmount: string,
  vatBreakdown: ReadonlyArray<VatBreakdownInput>,
  scale: number,
): void {
  // 1. subtotal + vat_total == total + transaction_discount_amount
  const subtotalPlusVat = bcformat(bcadd(subtotal, vatTotal, scale), scale);
  const totalPlusDiscount = bcformat(bcadd(total, transactionDiscountAmount, scale), scale);
  if (bccomp(subtotalPlusVat, totalPlusDiscount) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `Aggregate invariant violated: subtotal (${subtotal}) + vat_total (${vatTotal}) `
      + `= ${subtotalPlusVat} != total (${total}) + transaction_discount_amount `
      + `(${transactionDiscountAmount}) = ${totalPlusDiscount}.`,
    );
  }

  // 2 + 3 + 4. vat_breakdown nets/vats sum to subtotal/vat_total; per group gross == net + vat.
  let sumNet = bcformat('0', scale);
  let sumVat = bcformat('0', scale);
  for (const group of vatBreakdown) {
    const groupGross = bcformat(bcadd(group.net_amount, group.vat_amount, scale), scale);
    if (bccomp(groupGross, group.gross_amount) !== 0) {
      throw new SaleReceiptAggregateInvariantError(
        `Aggregate invariant violated: vat_breakdown group (rate ${group.rate}, `
        + `category "${group.tax_category_code}") gross_amount ${group.gross_amount} `
        + `!= net_amount (${group.net_amount}) + vat_amount (${group.vat_amount}) = ${groupGross}.`,
      );
    }
    sumNet = bcformat(bcadd(sumNet, group.net_amount, scale), scale);
    sumVat = bcformat(bcadd(sumVat, group.vat_amount, scale), scale);
  }

  if (bccomp(sumNet, subtotal) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `Aggregate invariant violated: Σ vat_breakdown.net_amount (${sumNet}) != subtotal ${subtotal}.`,
    );
  }
  if (bccomp(sumVat, vatTotal) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `Aggregate invariant violated: Σ vat_breakdown.vat_amount (${sumVat}) != vat_total ${vatTotal}.`,
    );
  }
}

export function buildSellerBlock(input: SaleReceiptSellerInput): SellerBlockInput {
  const name = requireText(input.name, 'seller.name');
  const countryCode = requireText(input.countryCode, 'seller.address.country_code').toUpperCase();
  const taxNumber = normalizeSellerTaxNumber(
    requireText(input.taxNumber, 'seller.tax_number'),
    countryCode,
  );
  const address: AddressInput = {
    city: requireText(input.city, 'seller.address.city'),
    country_code: countryCode,
    postal_code: requireText(input.postalCode, 'seller.address.postal_code'),
    street: requireText(input.street, 'seller.address.street'),
  };

  return {
    address,
    name,
    tax_jurisdiction_country_code: countryCode,
    tax_number: taxNumber,
  };
}

function normalizeSellerTaxNumber(taxNumber: string, countryCode: string): string {
  if (countryCode === 'TN') {
    return taxNumber.replace(/\//g, '');
  }

  return taxNumber;
}

export function buildLineItems(cartItems: CartItem[], scale: number): LineItemInput[] {
  return cartItems.map((item) => {
    const discountAmount = bcformat(item.discount_amount ?? '0', scale);
    const discountReason = item.discount_reason ?? null;
    if (bccomp(discountAmount, '0') > 0 && (discountReason === null || discountReason === '')) {
      throw new SaleReceiptPayloadInputError(
        `line discount reason is required for product ${item.product.id}.`,
      );
    }
    const lineVat = bcformat(item.tax_amount, scale);
    const lineSubtotal = bcformat(bcsub(item.line_total, item.tax_amount), scale);

    // ── Device-side line-arithmetic invariant ──────────────────────────────
    // GROSS self-consistency: line_total must equal unit_price * quantity
    // - line_discount_amount, rounded with the SAME rule the cart/device uses
    // (Big.RM half-up, currency scale — see cartStore.recalcLineTotal /
    // decimal.bcmul). Threshold is EXACT (bccomp == 0), never a tolerance.
    // A malformed line must be rejected BEFORE it is folded into the canonical
    // payload + signed; the hash chain proves un-tampered, not correct.
    const expectedGross = bcformat(
      bcsub(bcmul(item.unit_price, String(item.quantity), scale), discountAmount, scale),
      scale,
    );
    const actualGross = bcformat(item.line_total, scale);
    if (bccomp(actualGross, expectedGross) !== 0) {
      throw new LineArithmeticInvariantError(
        `Line arithmetic invariant violated for product ${item.product.id} (${item.product.name}): `
        + `line_total ${actualGross} != unit_price (${bcformat(item.unit_price, scale)}) `
        + `× quantity (${bcformat(String(item.quantity), 3)}) − discount (${discountAmount}) `
        + `= ${expectedGross}. A modifier price_adjustment was likely not folded into `
        + `unit_price/discount before line_total was computed.`,
      );
    }

    // NET/TAX decomposition: the payload derives line_subtotal as
    // line_total − tax_amount, so line_subtotal + line_vat must reconstitute
    // line_total at scale. A tax_amount carrying more precision than the
    // currency allows can make the rounded subtotal + rounded vat drift.
    const recomposedGross = bcformat(bcadd(lineSubtotal, lineVat, scale), scale);
    if (bccomp(recomposedGross, actualGross) !== 0) {
      throw new LineArithmeticInvariantError(
        `Line net/tax decomposition invariant violated for product ${item.product.id} `
        + `(${item.product.name}): line_subtotal (${lineSubtotal}) + line_vat (${lineVat}) `
        + `= ${recomposedGross} != line_total ${actualGross}.`,
      );
    }

    return {
      gtin: null,
      line_discount_amount: discountAmount,
      line_discount_reason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
      line_subtotal: lineSubtotal,
      line_vat: lineVat,
      name: item.product.name,
      non_collected_subtype: null,
      product_id: item.product.id,
      quantity: bcformat(String(item.quantity), 3),
      sku: item.product.sku,
      tax_category_code: '',
      unit_price: bcformat(item.unit_price, scale),
      vat_rate: bcformat(item.tax_rate, 2),
    };
  });
}

function buildVatBreakdown(
  lineItems: ReadonlyArray<LineItemInput>,
  scale: number,
): VatBreakdownInput[] {
  const groups = new Map<string, VatAccumulator>();
  for (const line of lineItems) {
    const key = `${line.vat_rate}|${line.tax_category_code}`;
    const current = groups.get(key) ?? {
      rate: line.vat_rate,
      category: line.tax_category_code,
      net: bcformat('0', scale),
      vat: bcformat('0', scale),
    };
    current.net = bcformat(bcadd(current.net, line.line_subtotal), scale);
    current.vat = bcformat(bcadd(current.vat, line.line_vat), scale);
    groups.set(key, current);
  }

  return [...groups.values()]
    .sort((a, b) => `${a.rate}|${a.category}`.localeCompare(`${b.rate}|${b.category}`))
    .map((group) => ({
      gross_amount: bcformat(bcadd(group.net, group.vat), scale),
      net_amount: group.net,
      rate: group.rate,
      tax_category_code: group.category,
      vat_amount: group.vat,
    }));
}

export function buildPayments(
  payments: ReadonlyArray<SaleReceiptPaymentInput>,
  scale: number,
): PaymentInput[] {
  return payments.map((payment) => ({
    amount: bcformat(payment.amount, scale),
    foreign_currency_amount: null,
    foreign_currency_code: null,
    instrument_serial: payment.instrumentSerial ?? null,
    instrument_type: payment.instrumentType ?? null,
    method_code: payment.methodCode,
  }));
}

export function buildVouchersRedeemed(
  payments: ReadonlyArray<SaleReceiptPaymentInput>,
  scale: number,
): VoucherRedeemedInput[] {
  return payments
    .filter((payment) => payment.instrumentType === 'store_voucher' && payment.instrumentSerial)
    .map((payment) => ({
      redeemed_amount: bcformat(payment.amount, scale),
      voucher_code: payment.instrumentSerial ?? '',
    }));
}

export function mapConsumptionMode(value: string | null): 'dine_in' | 'takeaway' | null {
  if (value === null || value === '') return null;
  if (value === 'SUR_PLACE' || value === 'dine_in') return 'dine_in';
  if (value === 'A_EMPORTER' || value === 'takeaway') return 'takeaway';
  throw new SaleReceiptPayloadInputError(
    `Unsupported consumption mode ${JSON.stringify(value)} for canonical SALE_RECEIPT.`,
  );
}

function requireText(value: string | null | undefined, label: string): string {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new SaleReceiptPayloadInputError(`${label} is required for SALE_RECEIPT canonical payload.`);
  }
  return value.trim();
}
