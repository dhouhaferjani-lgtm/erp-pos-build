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

/** Dispatch decision for a Pay press (cash or advanced). */
export type PayInterception =
  /** Empty cart — Pay does nothing. */
  | 'ignore'
  /** Mixed return + sale cart — block with the complete-return-first toast. */
  | 'block-mixed'
  /** All-return cart — enter the refund settlement flow. */
  | 'start-refund'
  /** Pure sale cart — continue into the existing sale checkout path. */
  | 'proceed-sale';

/**
 * The Pay-button interception matrix shared by BOTH HomePage entry points
 * (handlePayCash / handleAdvancedPayments). Extracted so the dispatch
 * semantics are unit-testable without rendering HomePage.
 */
export function decidePayInterception(items: readonly CartItem[]): PayInterception {
  switch (classifyCartForCheckout(items)) {
    case 'empty':
      return 'ignore';
    case 'mixed':
      return 'block-mixed';
    case 'refund':
      return 'start-refund';
    case 'sale':
      return 'proceed-sale';
  }
}

/**
 * Defense-in-depth gate for settlement actions fired from INSIDE an
 * already-open sale modal (cash confirm, advanced complete, charge to
 * account): a receipt scan can hydrate return lines while the modal is up,
 * and a non-pure-sale cart must NEVER reach buildSaleReceiptPayload or be
 * charged to a customer account. True → close the modal and show the
 * complete-return-first toast.
 */
export function mustBlockMidModalSettlement(items: readonly CartItem[]): boolean {
  return classifyCartForCheckout(items) !== 'sale';
}
