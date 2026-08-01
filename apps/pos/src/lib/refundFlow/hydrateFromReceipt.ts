import type { CartItem } from '@/types/cart';
import type { ReceiptTokenAccepted } from '@/types/refund';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';
import { bcmul } from '@/lib/decimal';

/** Decimal places present in a monetary string (default 2 if none). */
function inferScale(value: string): number {
  return value.includes('.') ? value.split('.')[1]!.length : 2;
}

/**
 * Shape of a persisted receipt line as stored in `offline_receipts.lines` JSON.
 * Mirrors the OfflineReceiptLine type used in getOfflineReceiptForPrint.ts.
 */
interface OfflineReceiptLine {
  product_id?: string;
  composite_item_id?: string;
  /**
   * T2 — variant identity persisted by receiptService alongside the line
   * (out of band from fiscal bytes). Threaded back onto the hydrated cart
   * item for display fidelity; NOT sent in the /return payload (the server
   * derives variants from the original lines).
   */
  variant_id?: string;
  variant_name?: string;
  name: string;
  sku: string;
  quantity: number;
  unit_price: string;
  line_total: string;
  tax_rate: string;
  tax_amount: string;
  discount_amount?: string | null;
  /**
   * v3-refund-chain-integration spec §3.2 — persisted alongside
   * `discount_amount` by `receiptService.ts:523-558` at sale time (the
   * ORIGINAL sale's per-line discount reason, independent of the
   * transaction-level `transaction_discount_reason`). Read here so a
   * refund of a discounted original carries the reason through onto the
   * returned `CartItem`, not just the amount.
   */
  discount_reason?: string | null;
  modifiers?: Array<{ name: string; price: string }>;
}

/**
 * Pure function: converts a local `offline_receipts` row + the accepted
 * receipt event into a list of `CartItem` entries with `kind: 'return'` and
 * negative quantities. No side effects; no API calls.
 *
 * Spec §6.2: "The active POS cart loads the original receipt's lines as
 * negative line items ('Returning' section)."
 *
 * @param event       The ReceiptTokenAccepted event (carries metadata only).
 * @param receipt     The full offline_receipts row (carries `lines` JSON).
 * @returns           CartItem[] each with quantity < 0, kind = 'return'.
 */
export function hydrateFromReceipt(
  event: ReceiptTokenAccepted,
  receipt: OfflineReceipt,
): CartItem[] {
  const rawLines = JSON.parse(receipt.lines) as OfflineReceiptLine[];

  return rawLines.map<CartItem>((line, idx) => {
    const negativeQty = -Math.abs(line.quantity);
    const unitPrice = line.unit_price;

    // line_total for a return line: negative (cashier owes money). Negate with
    // Big.js at the source string's own scale so the value is byte-identical
    // to a parseFloat-free negation.
    const lineTotal = bcmul(line.line_total, '-1', inferScale(line.line_total));
    const taxAmount = bcmul(line.tax_amount, '-1', inferScale(line.tax_amount));

    const productId = line.product_id ?? line.composite_item_id ?? `${event.receiptUuid}-line-${String(idx)}`;

    return {
      id: `return-${event.receiptUuid}-${String(idx)}`,
      product: {
        id: productId,
        name: line.name,
        sku: line.sku,
        price: unitPrice,
        ...(line.composite_item_id ? { sellableType: 'composite_item' as const } : {}),
        ...(line.variant_id ? { variant_id: line.variant_id } : {}),
        ...(line.variant_name ? { variant_name: line.variant_name } : {}),
      },
      quantity: negativeQty,
      unit_price: unitPrice,
      line_total: lineTotal,
      tax_rate: line.tax_rate,
      tax_amount: taxAmount,
      kind: 'return',
      // v3-refund-chain-integration spec §3.2 — carry the ORIGINAL line's
      // own discount fields through onto the returned CartItem. A
      // discount is a reduction regardless of direction, so its magnitude
      // is NOT negated (only line_total/tax_amount flip sign, above).
      ...(line.discount_amount != null ? { discount_amount: line.discount_amount } : {}),
      ...(line.discount_reason != null ? { discount_reason: line.discount_reason } : {}),
    };
  });
}
