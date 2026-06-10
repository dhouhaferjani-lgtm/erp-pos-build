import { describe, expect, it } from 'vitest';
import type { CartItem } from '@/types/cart';
import { classifyCartForCheckout } from '../cartClassification';

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
