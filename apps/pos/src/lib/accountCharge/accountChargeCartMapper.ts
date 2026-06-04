import type { CartItem } from '@/types/cart';
import type { CartTransactionDiscount } from '@/stores/cartStore';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcmul, bcdiv, bcsub } from '@/lib/decimal';
import type {
  AccountChargeLineInput,
  AccountChargeVatBreakdownInput,
} from './accountChargeService';

export class AccountChargeCartError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'AccountChargeCartError';
  }
}

export interface BuildAccountChargeCartInput {
  cartItems: CartItem[];
  currency: string;
  transactionDiscount: CartTransactionDiscount | null;
}

export interface BuildAccountChargeCartResult {
  lines: AccountChargeLineInput[];
  vatBreakdown: AccountChargeVatBreakdownInput[];
  subtotal: string;
  vatTotal: string;
  total: string;
  transactionDiscountAmount: string;
  transactionDiscountReason: string | null;
}

/**
 * Returns the NET subtotal (Σ line_total − tax_amount) and total tax for a
 * set of cart items.
 *
 * Contrast with `computeLineTotals` in `receiptService.ts`, which sums
 * GROSS line totals (tax-inclusive). This function always strips tax first,
 * producing the NET figure the ACCOUNT_CHARGE `totals.subtotal` field requires.
 */
function computeNetLineTotals(cartItems: CartItem[]): { netSubtotal: string; taxAmount: string } {
  let netSubtotal = '0';
  let taxAmount = '0';
  for (const item of cartItems) {
    netSubtotal = bcadd(netSubtotal, bcsub(item.line_total, item.tax_amount));
    taxAmount = bcadd(taxAmount, item.tax_amount);
  }
  return { netSubtotal, taxAmount };
}

export function buildAccountChargeCart(
  input: BuildAccountChargeCartInput,
): BuildAccountChargeCartResult {
  const scale = getCurrencyDecimals(input.currency);
  const { netSubtotal, taxAmount } = computeNetLineTotals(input.cartItems);

  const lines: AccountChargeLineInput[] = input.cartItems.map((item) => {
    const discountAmount = bcformat(item.discount_amount ?? '0', scale);
    const discountReason = item.discount_reason ?? null;
    if (bccomp(discountAmount, '0') > 0 && (discountReason === null || discountReason === '')) {
      throw new AccountChargeCartError(
        `line discount reason is required for product ${item.product.id}.`,
      );
    }
    const lineVat = bcformat(item.tax_amount, scale);
    const lineSubtotal = bcformat(bcsub(item.line_total, item.tax_amount), scale);
    return {
      lineUuid: crypto.randomUUID(),
      productId: item.product.id,
      sku: item.product.sku ?? null,
      name: item.product.name,
      quantity: bcformat(String(item.quantity), 3),
      unitPrice: bcformat(item.unit_price, scale), // gross / tax-inclusive, verbatim
      lineSubtotal,                                 // net
      lineVat,
      lineDiscountAmount: discountAmount,
      lineDiscountReason: bccomp(discountAmount, '0') === 0 ? null : discountReason,
      vatRate: bcformat(item.tax_rate, 2),
      taxCategoryCode: '',
      gtin: null,
    };
  });

  const groups = new Map<string, AccountChargeVatBreakdownInput>();
  for (const line of lines) {
    const key = `${line.vatRate}|${line.taxCategoryCode ?? ''}`;
    const current = groups.get(key) ?? {
      rate: line.vatRate,
      taxCategoryCode: line.taxCategoryCode ?? '',
      netAmount: bcformat('0', scale),
      vatAmount: bcformat('0', scale),
      grossAmount: bcformat('0', scale),
    };
    current.netAmount = bcformat(bcadd(current.netAmount, line.lineSubtotal), scale);
    current.vatAmount = bcformat(bcadd(current.vatAmount, line.lineVat), scale);
    current.grossAmount = bcformat(bcadd(current.netAmount, current.vatAmount), scale);
    groups.set(key, current);
  }

  const grossTotal = bcadd(netSubtotal, taxAmount);
  let transactionDiscountAmount = '0';
  if (input.transactionDiscount) {
    transactionDiscountAmount = input.transactionDiscount.type === 'percentage'
      ? bcdiv(bcmul(grossTotal, input.transactionDiscount.value), '100')
      : input.transactionDiscount.value;
  }
  // Canonical contract §6.A (PHP validateDiscountReasonPair + TS engine line 430):
  // transaction_discount_reason MUST be non-null when transaction_discount_amount > 0.
  const transactionDiscountReason = input.transactionDiscount?.reason ?? null;
  if (bccomp(transactionDiscountAmount, '0') > 0 && (transactionDiscountReason === null || transactionDiscountReason === '')) {
    throw new AccountChargeCartError(
      'transaction discount reason is required when a transaction discount amount is present.',
    );
  }
  const rawTotal = bcsub(grossTotal, transactionDiscountAmount);
  const total = bccomp(rawTotal, '0') >= 0 ? rawTotal : '0';

  return {
    lines,
    vatBreakdown: [...groups.values()].sort((a, b) =>
      `${a.rate}|${a.taxCategoryCode}`.localeCompare(`${b.rate}|${b.taxCategoryCode}`),
    ),
    subtotal: bcformat(netSubtotal, scale),
    vatTotal: bcformat(taxAmount, scale),
    total: bcformat(total, scale),
    transactionDiscountAmount: bcformat(transactionDiscountAmount, scale),
    transactionDiscountReason,
  };
}
