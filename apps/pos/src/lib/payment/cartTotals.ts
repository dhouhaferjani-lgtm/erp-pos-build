import { getCurrencyDecimals } from '@/lib/currency';
import { bccomp, bcdiv, bcformat, bcmul, bcsub, bcsum } from '@/lib/decimal';
import type { CartItem } from '@/types/cart';
import type { CartTransactionDiscount } from '@/stores/cartStore';

interface ExactCartTotals {
  /** Σ line_total at the currency scale. */
  subtotal: string;
  /** The transaction discount actually applied, at the currency scale. */
  discountAmount: string;
  /** subtotal − discount, clamped at zero, at the currency scale. */
  total: string;
}

/**
 * The one and only cart-total computation. Both exported helpers read from
 * this so the discount they report and the total they report can never be
 * produced by two different arithmetic paths.
 *
 * All arithmetic at the CURRENCY scale (never the bc* default of 3), and
 * byte-for-byte identical to `cartStore.discountAmountString` /
 * `cartStore.totalString` — the numbers the cashier is looking at when they
 * hit Confirm.
 */
function computeCartTotals(
  cartItems: CartItem[],
  transactionDiscount: CartTransactionDiscount | undefined,
  currency: string,
): ExactCartTotals {
  const decimals = getCurrencyDecimals(currency);
  const subtotal = bcsum(cartItems.map((item) => item.line_total), decimals);
  const zero = bcformat('0', decimals);

  if (transactionDiscount === undefined) {
    return { subtotal, discountAmount: zero, total: subtotal };
  }

  // Stay in the decimal domain: safeBig maps '' to 0, the catch covers a
  // non-numeric value (treated as no discount).
  let discountIsPositive = false;
  try {
    discountIsPositive = bccomp(transactionDiscount.value, '0') > 0;
  } catch {
    discountIsPositive = false;
  }
  if (!discountIsPositive) {
    return { subtotal, discountAmount: zero, total: subtotal };
  }

  // Round ONCE, here, at the currency scale — including the fixed-amount branch.
  // A fixed value finer than the scale (e.g. '0.005' on EUR; DiscountModal caps
  // neither the decimal count nor the scale) would otherwise be subtracted raw
  // while being REPORTED rounded, so `total + discount != subtotal` and the
  // signed payload's aggregate invariant aborts authoring mid-sale.
  const rawDiscount = transactionDiscount.type === 'percentage'
    ? bcdiv(bcmul(subtotal, transactionDiscount.value, decimals), '100', decimals)
    : bcformat(transactionDiscount.value, decimals);
  // Clamp the discount to the subtotal so the total can never go negative.
  const isClamped = bccomp(rawDiscount, subtotal) > 0;
  const discount = isClamped ? subtotal : rawDiscount;
  const total = bcsub(subtotal, discount, decimals);

  return {
    subtotal,
    discountAmount: discount,
    total: bccomp(total, '0') < 0 ? zero : total,
  };
}

/**
 * THE cart total (spec 2026-07-27 §4.3, r2 F4). Both the checkout gate
 * (paymentStore.estimateCartTotal) and the fiscal authoring path
 * (offline/receiptService) call this ONE function, so a scale-2 percentage
 * discount can no longer produce two different "exact totals" — a divergence
 * that lands on either side of a rounding tie.
 *
 * All arithmetic at the CURRENCY scale (never the bc* default of 3).
 */
export function computeExactCartTotal(
  cartItems: CartItem[],
  transactionDiscount: CartTransactionDiscount | undefined,
  currency: string,
): string {
  return computeCartTotals(cartItems, transactionDiscount, currency).total;
}

/**
 * The transaction-discount amount actually applied by {@link computeExactCartTotal},
 * at currency scale. Kept beside the total so the receipt row, the signed
 * payload and the gate can never disagree on which discount was used.
 */
export function computeExactDiscountAmount(
  cartItems: CartItem[],
  transactionDiscount: CartTransactionDiscount | undefined,
  currency: string,
): string {
  return computeCartTotals(cartItems, transactionDiscount, currency).discountAmount;
}
