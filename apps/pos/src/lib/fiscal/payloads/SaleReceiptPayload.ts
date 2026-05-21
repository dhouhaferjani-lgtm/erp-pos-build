import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
import type {
  AddressInput,
  LineItemInput,
  PaymentInput,
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

  return {
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
    total: bcformat(input.total, scale),
    training_flag: input.isTraining,
    transaction_discount_amount: discountAmount,
    transaction_discount_reason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
    vat_breakdown: vatBreakdown,
    vat_total: bcformat(input.taxAmount, scale),
    vouchers_redeemed: vouchersRedeemed,
  };
}

function buildSellerBlock(input: SaleReceiptSellerInput): SellerBlockInput {
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

function buildLineItems(cartItems: CartItem[], scale: number): LineItemInput[] {
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

function buildPayments(
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

function buildVouchersRedeemed(
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

function mapConsumptionMode(value: string | null): 'dine_in' | 'takeaway' | null {
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
