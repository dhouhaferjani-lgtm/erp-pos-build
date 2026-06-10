/**
 * Cart classification for the Pay entry point (Task 2b checkout interception).
 *
 * An all-return cart must NEVER reach the sale checkout path — negative lines
 * fail the FiscalEventEngine money invariant inside buildSaleReceiptPayload.
 * Pay therefore classifies the cart FIRST:
 *
 *   'refund' — at least one `kind: 'return'` line and no sale lines →
 *              route into the refund settlement flow (refundCheckoutStore).
 *   'mixed'  — return + sale lines together → Pay is BLOCKED (the cashier
 *              must complete the return first; exchange settlement is a
 *              later phase).
 *   'sale'   — only sale lines (kind 'sale' or undefined) → the existing
 *              sale checkout path, untouched.
 *   'empty'  — nothing to pay.
 */
import type { CartItem } from '@/types/cart';

export type CartCheckoutClassification = 'empty' | 'sale' | 'refund' | 'mixed';

export function classifyCartForCheckout(
  items: readonly CartItem[],
): CartCheckoutClassification {
  let hasReturn = false;
  let hasSale = false;

  for (const item of items) {
    if ((item.kind ?? 'sale') === 'return') {
      hasReturn = true;
    } else {
      hasSale = true;
    }
  }

  if (hasReturn && hasSale) return 'mixed';
  if (hasReturn) return 'refund';
  if (hasSale) return 'sale';
  return 'empty';
}
