import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct } from '@/types/product';
import type { CartItem } from '@/types/cart';

// ── Mock the audit emit so we assert the enqueued shape without touching SQLite ──
const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

import { useCartStore } from '../cartStore';

function makeProduct(overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id: 'prod-1',
    name: 'Test Product',
    sku: 'SKU-001',
    sale_price: '10.00',
    stock_quantity: 50,
    ...overrides,
  };
}

function makeReturnItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: crypto.randomUUID(),
    product: { id: 'prod-r', name: 'Return', sku: 'SKU-R', price: '5.00' },
    quantity: -1,
    unit_price: '5.00',
    line_total: '-5.00',
    tax_rate: '0',
    tax_amount: '0.00',
    kind: 'return',
    ...overrides,
  };
}

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

describe('cartStore — cart_session_id lifecycle', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    // Reset to an empty, session-less cart without going through the
    // discard path (which would emit). Use replaceCart then null the id.
    useCartStore.setState({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });
  });

  it('has no session id on a fresh empty cart', () => {
    expect(useCartStore.getState().cartSessionId).toBeNull();
    expect(useCartStore.getState().getCartSessionId()).toBeNull();
  });

  it('generates a session id on the first mutation of an empty cart', () => {
    useCartStore.getState().addItem(makeProduct());
    const id = useCartStore.getState().cartSessionId;
    expect(id).toMatch(UUID_RE);
  });

  it('keeps the same session id across subsequent mutations', () => {
    useCartStore.getState().addItem(makeProduct());
    const first = useCartStore.getState().cartSessionId;
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));
    expect(useCartStore.getState().cartSessionId).toBe(first);
  });

  it('clears the session id on discard (clearCart non-checkout)', () => {
    useCartStore.getState().addItem(makeProduct());
    expect(useCartStore.getState().cartSessionId).toMatch(UUID_RE);
    useCartStore.getState().clearCart();
    expect(useCartStore.getState().cartSessionId).toBeNull();
  });

  it('clears the session id on checkout / hold / shift-close / operator-switch reasons', () => {
    for (const reason of ['checkout', 'hold', 'shift_close', 'operator_switch'] as const) {
      useCartStore.getState().addItem(makeProduct());
      expect(useCartStore.getState().cartSessionId).toMatch(UUID_RE);
      useCartStore.getState().clearCart(reason);
      expect(useCartStore.getState().cartSessionId).toBeNull();
    }
  });

  it('regenerates the session id on replaceCart', () => {
    useCartStore.getState().addItem(makeProduct());
    const before = useCartStore.getState().cartSessionId;
    useCartStore.getState().replaceCart(
      [
        {
          id: 'line-x',
          product: { id: 'p', name: 'P', sku: 'S', price: '1.00' },
          quantity: 1,
          unit_price: '1.00',
          line_total: '1.00',
          tax_rate: '0',
          tax_amount: '0.00',
        },
      ],
      undefined,
    );
    const after = useCartStore.getState().cartSessionId;
    expect(after).toMatch(UUID_RE);
    expect(after).not.toBe(before);
  });

  it('regenerates the session id on replaceReturnItems', () => {
    useCartStore.getState().addItem(makeProduct());
    const before = useCartStore.getState().cartSessionId;
    useCartStore.getState().replaceReturnItems([makeReturnItem()]);
    const after = useCartStore.getState().cartSessionId;
    expect(after).toMatch(UUID_RE);
    expect(after).not.toBe(before);
  });

  // Fix 1 regression: removing the last line must clear the session id so the
  // next sale gets a fresh id (not stale session A reused for a new sale).
  it('clears session id when removeItem empties the cart', () => {
    useCartStore.getState().addItem(makeProduct());
    const sessionA = useCartStore.getState().cartSessionId;
    expect(sessionA).toMatch(UUID_RE);

    const itemId = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(itemId);

    expect(useCartStore.getState().cartSessionId).toBeNull();
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('generates a NEW session id after removing last line and adding again (no stale reuse)', () => {
    useCartStore.getState().addItem(makeProduct());
    const sessionA = useCartStore.getState().cartSessionId;

    // Remove last line → session cleared
    const itemId = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(itemId);
    expect(useCartStore.getState().cartSessionId).toBeNull();

    // Add again → fresh session
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));
    const sessionB = useCartStore.getState().cartSessionId;
    expect(sessionB).toMatch(UUID_RE);
    expect(sessionB).not.toBe(sessionA);
  });

  it('keeps session id when removeItem does NOT empty the cart', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'prod-1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2' }));
    const sessionBefore = useCartStore.getState().cartSessionId;
    expect(useCartStore.getState().items).toHaveLength(2);

    const firstItemId = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(firstItemId);

    expect(useCartStore.getState().items).toHaveLength(1);
    expect(useCartStore.getState().cartSessionId).toBe(sessionBefore);
  });
});

describe('cartStore — cartLinesRemovedThisSession counter (Fix 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    useCartStore.setState({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });
  });

  it('starts at 0 on a fresh cart', () => {
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);
  });

  it('increments on each removeItem while the cart is not emptied', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p3' }));
    expect(useCartStore.getState().items).toHaveLength(3);

    const [id0, id1] = useCartStore.getState().items.map((i) => i.id);
    useCartStore.getState().removeItem(id0!);
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(1);
    useCartStore.getState().removeItem(id1!);
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(2);
  });

  it('resets to 0 when removeItem empties the cart (session cleared)', () => {
    useCartStore.getState().addItem(makeProduct());
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().removeItem(itemId);

    expect(useCartStore.getState().cartSessionId).toBeNull();
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);
  });

  it('resets to 0 on replaceCart (new session)', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2' }));
    const idToRemove = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(idToRemove);
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(1);

    useCartStore.getState().replaceCart(
      [{ id: 'line-r', product: { id: 'p', name: 'P', sku: 'S', price: '1.00' }, quantity: 1, unit_price: '1.00', line_total: '1.00', tax_rate: '0', tax_amount: '0.00' }],
      undefined,
    );
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);
  });

  it('resets to 0 on clearCart (session cleared)', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2' }));
    const idToRemove = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(idToRemove);
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(1);

    useCartStore.getState().clearCart();

    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);
  });

  it('includes line_count_removed_before in pos.cart_discarded payload', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p3' }));

    // Remove one line first, then discard the rest
    const idToRemove = useCartStore.getState().items[0]!.id;
    useCartStore.getState().removeItem(idToRemove);
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(1);

    useCartStore.getState().clearCart(); // discard

    const call = lastCallOfType('pos.cart_discarded');
    expect(call).toBeDefined();
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.line_count_removed_before).toBe(1);
  });

  it('resets counter to 0 on new session after adding to empty cart', () => {
    // Add, remove-all (empties, resets counter+session), then add again
    useCartStore.getState().addItem(makeProduct({ id: 'p1' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2' }));
    const [id0, id1] = useCartStore.getState().items.map((i) => i.id);
    useCartStore.getState().removeItem(id0!); // counter = 1
    useCartStore.getState().removeItem(id1!); // empties cart → counter reset = 0, session null

    expect(useCartStore.getState().cartSessionId).toBeNull();
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);

    // New sale
    useCartStore.getState().addItem(makeProduct({ id: 'p3' }));
    expect(useCartStore.getState().cartLinesRemovedThisSession).toBe(0);
  });
});

describe('cartStore — line-discount actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    useCartStore.setState({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });
  });

  it('applyLineDiscount sets a percentage discount and recomputes the line total', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().applyLineDiscount(itemId, {
      type: 'percentage',
      value: '10',
      reason: 'loyalty',
    });

    const item = useCartStore.getState().items[0]!;
    expect(item.discount_type).toBe('percentage');
    expect(item.discount_percent).toBe('10');
    expect(item.discount_amount).toBe('10.00');
    expect(item.line_total).toBe('90.00');
    expect(item.discount_reason).toBe('loyalty');
  });

  it('applyLineDiscount sets a fixed discount and clamps the line total at 0', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '10.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().applyLineDiscount(itemId, {
      type: 'fixed',
      value: '50',
      reason: '',
    });

    const item = useCartStore.getState().items[0]!;
    expect(item.discount_type).toBe('fixed');
    expect(item.discount_percent).toBeUndefined();
    expect(item.line_total).toBe('0.00');
  });

  it('removeLineDiscount restores the gross line total', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    const itemId = useCartStore.getState().items[0]!.id;
    useCartStore.getState().applyLineDiscount(itemId, { type: 'percentage', value: '25', reason: '' });
    expect(useCartStore.getState().items[0]!.line_total).toBe('75.00');

    useCartStore.getState().removeLineDiscount(itemId);

    const item = useCartStore.getState().items[0]!;
    expect(item.discount_type).toBeUndefined();
    expect(item.discount_percent).toBeUndefined();
    expect(item.discount_amount).toBeUndefined();
    expect(item.line_total).toBe('100.00');
  });
});

describe('cartStore — audit emits', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    useCartStore.setState({ items: [], transactionDiscount: undefined, cartSessionId: null, cartLinesRemovedThisSession: 0 });
  });

  it('emits pos.cart_discarded on discard with a pre-clear snapshot', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '10.00' }));
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2', sale_price: '5.00' }));
    const sessionId = useCartStore.getState().cartSessionId;

    useCartStore.getState().clearCart();

    const call = lastCallOfType('pos.cart_discarded');
    expect(call).toBeDefined();
    expect(call!.aggregateType).toBe('PosSale');
    expect(call!.aggregateId).toBe(sessionId);
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.line_count).toBe(2);
    expect(payload.subtotal).toBe(15);
    expect(payload).toHaveProperty('discount_total');
    expect(payload).toHaveProperty('had_return_items');
  });

  it('does NOT emit pos.cart_discarded on a checkout-reason clear', () => {
    useCartStore.getState().addItem(makeProduct());
    useCartStore.getState().clearCart('checkout');
    expect(lastCallOfType('pos.cart_discarded')).toBeUndefined();
  });

  it('does NOT emit pos.cart_discarded when the cart is already empty', () => {
    useCartStore.getState().clearCart();
    expect(lastCallOfType('pos.cart_discarded')).toBeUndefined();
  });

  it('emits pos.cart_line_removed on removeItem', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '10.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().removeItem(itemId);

    const call = lastCallOfType('pos.cart_line_removed');
    expect(call).toBeDefined();
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.product_id).toBe('prod-1');
    expect(payload.qty).toBe(1);
    expect(payload.unit_price).toBe('10.00');
    expect(payload.line_total).toBe('10.00');
    expect(payload.kind).toBe('sale');
  });

  it('emits pos.cart_quantity_updated on updateQuantity', () => {
    useCartStore.getState().addItem(makeProduct());
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().updateQuantity(itemId, 3);

    const call = lastCallOfType('pos.cart_quantity_updated');
    expect(call).toBeDefined();
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.line_id).toBe(itemId);
    expect(payload.product_id).toBe('prod-1');
    expect(payload.old_qty).toBe(1);
    expect(payload.new_qty).toBe(3);
    expect(payload.kind).toBe('sale');
  });

  it('emits pos.line_discount_applied on applyLineDiscount', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    const itemId = useCartStore.getState().items[0]!.id;

    useCartStore.getState().applyLineDiscount(itemId, {
      type: 'percentage',
      value: '10',
      reason: 'promo',
    });

    const call = lastCallOfType('pos.line_discount_applied');
    expect(call).toBeDefined();
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.line_id).toBe(itemId);
    expect(payload.product_id).toBe('prod-1');
    expect(payload.discount_type).toBe('percentage');
    expect(payload.discount_amount).toBe('10.00');
    expect(payload.discount_percent).toBe('10');
    expect(payload.has_approval_evidence).toBe(false);
  });

  it('emits pos.transaction_discount_applied when setting (not clearing) a tx discount', () => {
    useCartStore.getState().addItem(makeProduct());
    useCartStore.getState().setTransactionDiscount({
      type: 'fixed',
      value: '5',
      reason: 'manager',
    });

    const call = lastCallOfType('pos.transaction_discount_applied');
    expect(call).toBeDefined();
    const payload = call!.payload as Record<string, unknown>;
    expect(payload.discount_type).toBe('fixed');
    expect(payload.discount_amount).toBe('5');
    expect(payload.reason).toBe('manager');
    expect(payload.has_approval_evidence).toBe(false);
  });

  it('does NOT emit pos.transaction_discount_applied when clearing the tx discount', () => {
    useCartStore.getState().addItem(makeProduct());
    useCartStore.getState().setTransactionDiscount({ type: 'fixed', value: '5' });
    vi.clearAllMocks();
    useCartStore.getState().setTransactionDiscount(undefined);
    expect(lastCallOfType('pos.transaction_discount_applied')).toBeUndefined();
  });

  it('does not break the action when the audit emit rejects', () => {
    recordAuditEvent.mockRejectedValue(new Error('audit down'));
    useCartStore.getState().addItem(makeProduct());
    const itemId = useCartStore.getState().items[0]!.id;

    // None of these should throw despite the rejected emit.
    expect(() => useCartStore.getState().updateQuantity(itemId, 2)).not.toThrow();
    expect(() => useCartStore.getState().removeItem(itemId)).not.toThrow();
    expect(() => useCartStore.getState().clearCart()).not.toThrow();
  });
});
