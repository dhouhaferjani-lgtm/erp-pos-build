import type { CartItem } from '@/types/cart';
import type { CartTransactionDiscount } from '@/stores/cartStore';
import i18n from '@/lib/i18n';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
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
  // ── D-1 gate r1 finding 4 (owner ruling 2026-08-25) ─────────────────────
  // This mapper still seals `subtotal`/`vatTotal` on the PRE-remise line
  // roll-up and applies the remise to `total` alone — verbatim the defect D-1
  // removed from SALE_RECEIPT, on a sibling event type fed by the SAME cart
  // and the SAME transaction-discount UI. Since D-1 a paid ticket seals a
  // POST-remise base; letting an on-account ticket seal a pre-remise one would
  // make ONE cart declare two different taxable bases depending on tender, and
  // `createPOSChargeEntry()` would over-credit VAT collected on every
  // discounted on-account sale.
  //
  // Ventilating ACCOUNT_CHARGE properly is a versioned-payload change of its
  // own (new event version, projection column, GL era awareness) and cannot be
  // authored or tested end-to-end inside D-1. So the remise is REFUSED here
  // until that lane lands: fail-closed, immediately correct, reversible in one
  // block. Nothing is signed when it throws — the cashier is told to clear the
  // remise or take payment now. The server refuses it too
  // (`payload_account_charge_transaction_discount_unsupported`), which is what
  // binds a device that has not taken this build.
  if (input.transactionDiscount) {
    throw new AccountChargeCartError(
      i18n.t('account_charge.errors.remise_not_supported', { ns: 'pos' }),
    );
  }
  // The refusal above makes these constants, not a branch. They are KEPT (a) so
  // the canonical payload still carries the two contract fields at their
  // canonical-zero values, and (b) so re-enabling the remise here — once
  // ACCOUNT_CHARGE ventilates — is deleting a guard rather than rebuilding a
  // computation. Canonical contract §6.A: `transaction_discount_reason` must be
  // null while the amount is zero, which is exactly what a refused remise
  // leaves behind.
  const transactionDiscountAmount = '0';
  const transactionDiscountReason: string | null = null;
  const total = grossTotal;

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
