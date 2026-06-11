import { describe, expect, it } from 'vitest';
import type { CartItem } from '@/types/cart';
import {
  classifyCartForCheckout,
  decidePayInterception,
  mustBlockMidModalSettlement,
} from '../cartClassification';

function item(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: crypto.randomUUID(),
    product: { id: 'prod-1', name: 'Widget', sku: 'SKU-1', price: '10.00' },
    quantity: 1,
    unit_price: '10.00',
    line_total: '10.00',
    tax_rate: '0',
    tax_amount: '0.00',
    ...overrides,
  };
}

describe('classifyCartForCheckout', () => {
  it('classifies an empty cart as empty', () => {
    expect(classifyCartForCheckout([])).toBe('empty');
  });

  it('classifies a cart with only sale lines as sale', () => {
    expect(classifyCartForCheckout([item(), item({ kind: 'sale' })])).toBe('sale');
  });

  it('treats an undefined kind as a sale line (default)', () => {
    expect(classifyCartForCheckout([item({ kind: undefined })])).toBe('sale');
  });

  it('classifies a return-only cart as refund', () => {
    expect(
      classifyCartForCheckout([
        item({ kind: 'return', quantity: -1, line_total: '-10.00' }),
        item({ kind: 'return', quantity: -2, line_total: '-20.00' }),
      ]),
    ).toBe('refund');
  });

  it('classifies a mixed return + sale cart as mixed', () => {
    expect(
      classifyCartForCheckout([
        item({ kind: 'return', quantity: -1, line_total: '-10.00' }),
        item({ kind: 'sale' }),
      ]),
    ).toBe('mixed');
  });
});

// The Pay-button dispatch matrix used by BOTH HomePage entry points
// (handlePayCash / handleAdvancedPayments). Extracted so the routing
// semantics are pinned here — the residual untested seam is only the
// useCallback wiring inside HomePage itself.
describe('decidePayInterception', () => {
  it('empty cart → ignore (Pay is a no-op)', () => {
    expect(decidePayInterception([])).toBe('ignore');
  });

  it('sale-only cart → proceed to the normal sale checkout', () => {
    expect(decidePayInterception([item()])).toBe('proceed-sale');
  });

  it('return-only cart → start the refund settlement flow', () => {
    expect(
      decidePayInterception([item({ kind: 'return', quantity: -1, line_total: '-10.00' })]),
    ).toBe('start-refund');
  });

  it('mixed cart → block with the complete-return-first toast', () => {
    expect(
      decidePayInterception([
        item({ kind: 'return', quantity: -1, line_total: '-10.00' }),
        item(),
      ]),
    ).toBe('block-mixed');
  });
});

// Defense-in-depth gate for settlement actions INSIDE an already-open sale
// modal (cash confirm / advanced complete / charge-to-account): a scan can
// hydrate return lines while the modal is up, and a non-pure-sale cart must
// never reach buildSaleReceiptPayload or be charged to a customer account.
describe('mustBlockMidModalSettlement', () => {
  it('blocks an empty cart', () => {
    expect(mustBlockMidModalSettlement([])).toBe(true);
  });

  it('allows a pure-sale cart', () => {
    expect(mustBlockMidModalSettlement([item(), item({ kind: 'sale' })])).toBe(false);
  });

  it('blocks a refund-only cart (scan-hydrated mid-modal)', () => {
    expect(
      mustBlockMidModalSettlement([item({ kind: 'return', quantity: -1, line_total: '-10.00' })]),
    ).toBe(true);
  });

  it('blocks a mixed cart', () => {
    expect(
      mustBlockMidModalSettlement([
        item({ kind: 'return', quantity: -1, line_total: '-10.00' }),
        item(),
      ]),
    ).toBe(true);
  });
});
