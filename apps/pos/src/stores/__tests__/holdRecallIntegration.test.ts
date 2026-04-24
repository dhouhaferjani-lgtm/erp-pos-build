import { describe, it, expect, vi, beforeEach } from 'vitest';

// In-memory stand-in for the repository layer to give us a full round-trip.
const store: Map<string, Parameters<typeof import('@/lib/db/repositories/heldTransactionRepository').insertHeldTransaction>[1]> = new Map();

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/repositories/heldTransactionRepository', () => ({
  insertHeldTransaction: vi.fn(async (_db, row) => { store.set(row.id, row); }),
  listHeldTransactions: vi.fn(async () => Array.from(store.values())),
  deleteHeldTransaction: vi.fn(async (_db, id) => { store.delete(id); }),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: () => ({ companyId: 'co-1', companies: [{ id: 'co-1', currency: 'EUR' }] }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: () => ({ terminal: { id: 'term-1' } }),
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: () => ({ operator: { id: 'op-1' } }),
  },
}));

import type { POSProduct } from '@/types/product';
import { useHoldStore } from '../holdStore';
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

describe('Hold → Recall integration (BG3 regression)', () => {
  beforeEach(() => {
    store.clear();
    useCartStore.getState().clearCart();
    useHoldStore.setState({ heldTransactions: [], isLoading: false, error: null });
  });

  it('holds a cart with two items @ 9.80 and 6.30 and recalls total 16.10', async () => {
    useCartStore.getState().addItem(makeProduct({ id: 'p1', sale_price: '9.80' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2', sale_price: '6.30' }));
    expect(useCartStore.getState().total()).toBeCloseTo(16.1);

    await useHoldStore.getState().holdCurrentCart('Table 3');
    const heldId = useHoldStore.getState().heldTransactions[0]!.id;
    expect(useHoldStore.getState().heldTransactions[0]!.total).toBeCloseTo(16.1);
    // cart is cleared
    expect(useCartStore.getState().items).toHaveLength(0);

    const tx = await useHoldStore.getState().recallTransaction(heldId);
    expect(tx).toBeDefined();

    useCartStore.getState().replaceCart(tx!.items, tx!.transactionDiscount);

    expect(useCartStore.getState().items).toHaveLength(2);
    expect(useCartStore.getState().subtotal()).toBeCloseTo(16.1);
    expect(useCartStore.getState().total()).toBeCloseTo(16.1);
  });

  it('survives a simulated reload (hydrate from repository)', async () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '25.90' }));
    await useHoldStore.getState().holdCurrentCart('Order reloaded');
    expect(useHoldStore.getState().heldTransactions).toHaveLength(1);

    // Simulate a reload: clear in-memory state but keep the SQLite mock backing store.
    useHoldStore.setState({ heldTransactions: [], isLoading: false, error: null });
    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);

    await useHoldStore.getState().loadHeldTransactions();

    const held = useHoldStore.getState().heldTransactions;
    expect(held).toHaveLength(1);
    expect(held[0]!.label).toBe('Order reloaded');
    expect(held[0]!.total).toBeCloseTo(25.9);
  });
});
