import { describe, it, expect, beforeEach } from 'vitest';
import { useHoldStore } from '../holdStore';
import { useCartStore } from '../cartStore';
import type { POSProduct } from '@/types/product';

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

describe('holdStore', () => {
  beforeEach(() => {
    useCartStore.getState().clearCart();
    useHoldStore.setState({
      heldTransactions: [],
      isLoading: false,
      error: null,
    });
  });

  it('has correct initial state', () => {
    const state = useHoldStore.getState();
    expect(state.heldTransactions).toHaveLength(0);
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeNull();
  });

  it('holds the current cart and clears it', () => {
    useCartStore.getState().addItem(makeProduct());
    useCartStore.getState().addItem(makeProduct({ id: 'prod-2', name: 'Product 2', sale_price: '20.00' }));

    useHoldStore.getState().holdCurrentCart('Order #1');

    const held = useHoldStore.getState().heldTransactions;
    expect(held).toHaveLength(1);
    expect(held[0]!.label).toBe('Order #1');
    expect(held[0]!.itemCount).toBe(2);
    expect(held[0]!.subtotal).toBeCloseTo(30);
    expect(held[0]!.total).toBeCloseTo(30);

    // Cart should be cleared
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('does nothing when cart is empty', () => {
    useHoldStore.getState().holdCurrentCart('Empty');

    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('uses timestamp as label when label is empty', () => {
    useCartStore.getState().addItem(makeProduct());

    useHoldStore.getState().holdCurrentCart('');

    const held = useHoldStore.getState().heldTransactions;
    expect(held).toHaveLength(1);
    // Label should be a time string (non-empty since it falls back to toLocaleTimeString)
    expect(held[0]!.label.length).toBeGreaterThan(0);
  });

  it('recalls a held transaction and removes it from list', () => {
    useCartStore.getState().addItem(makeProduct());
    useHoldStore.getState().holdCurrentCart('Order A');

    const heldId = useHoldStore.getState().heldTransactions[0]!.id;

    const recalled = useHoldStore.getState().recallTransaction(heldId);

    expect(recalled).toBeDefined();
    expect(recalled!.label).toBe('Order A');
    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('returns undefined when recalling non-existent transaction', () => {
    const result = useHoldStore.getState().recallTransaction('nonexistent-id');
    expect(result).toBeUndefined();
  });

  it('discards a held transaction', () => {
    useCartStore.getState().addItem(makeProduct());
    useHoldStore.getState().holdCurrentCart('To Discard');

    const heldId = useHoldStore.getState().heldTransactions[0]!.id;
    useHoldStore.getState().discardTransaction(heldId);

    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('holds multiple transactions', () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1', sale_price: '10.00' }));
    useHoldStore.getState().holdCurrentCart('First');

    useCartStore.getState().addItem(makeProduct({ id: 'p2', sale_price: '20.00' }));
    useHoldStore.getState().holdCurrentCart('Second');

    expect(useHoldStore.getState().heldTransactions).toHaveLength(2);
    expect(useHoldStore.getState().heldTransactions[0]!.label).toBe('First');
    expect(useHoldStore.getState().heldTransactions[1]!.label).toBe('Second');
  });

  it('preserves transaction discount in held transaction', () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
    useCartStore.getState().setTransactionDiscount({ amount: '15.00', reason: 'Loyalty' });

    useHoldStore.getState().holdCurrentCart('Discounted');

    const held = useHoldStore.getState().heldTransactions[0]!;
    expect(held.transactionDiscount).toBeDefined();
    expect(held.transactionDiscount!.amount).toBe('15.00');
    expect(held.transactionDiscount!.reason).toBe('Loyalty');
    expect(held.total).toBeCloseTo(85);
  });
});
