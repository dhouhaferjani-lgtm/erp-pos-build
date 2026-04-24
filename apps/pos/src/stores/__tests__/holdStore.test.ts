import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct } from '@/types/product';

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({} as import('@tauri-apps/plugin-sql').default),
}));

vi.mock('@/lib/db/repositories/heldTransactionRepository', () => ({
  insertHeldTransaction: vi.fn().mockResolvedValue(undefined),
  listHeldTransactions: vi.fn().mockResolvedValue([]),
  deleteHeldTransaction: vi.fn().mockResolvedValue(undefined),
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

import {
  insertHeldTransaction,
  listHeldTransactions,
  deleteHeldTransaction,
} from '@/lib/db/repositories/heldTransactionRepository';
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

describe('holdStore (SQLite-backed)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useCartStore.getState().clearCart();
    useHoldStore.setState({ heldTransactions: [], isLoading: false, error: null });
  });

  it('has correct initial state', () => {
    const state = useHoldStore.getState();
    expect(state.heldTransactions).toHaveLength(0);
    expect(state.isLoading).toBe(false);
    expect(state.error).toBeNull();
  });

  it('holdCurrentCart persists to SQLite, populates in-memory list, and clears the cart', async () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '9.80' }));
    useCartStore.getState().addItem(makeProduct({ id: 'p2', sale_price: '6.30' }));

    await useHoldStore.getState().holdCurrentCart('Table 3');

    expect(insertHeldTransaction).toHaveBeenCalledTimes(1);
    const call = vi.mocked(insertHeldTransaction).mock.calls[0]!;
    const row = call[1];
    expect(row.terminal_id).toBe('term-1');
    expect(row.operator_id).toBe('op-1');
    expect(row.label).toBe('Table 3');
    expect(row.item_count).toBe(2);
    // BG3 regression: totals must be correct at hold-time.
    expect(parseFloat(row.subtotal)).toBeCloseTo(16.1);
    expect(parseFloat(row.total)).toBeCloseTo(16.1);

    const held = useHoldStore.getState().heldTransactions;
    expect(held).toHaveLength(1);
    expect(held[0]!.total).toBeCloseTo(16.1);

    // Cart is cleared after holding.
    expect(useCartStore.getState().items).toHaveLength(0);
  });

  it('holdCurrentCart is a no-op when cart is empty', async () => {
    await useHoldStore.getState().holdCurrentCart('nope');
    expect(insertHeldTransaction).not.toHaveBeenCalled();
    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('recallTransaction returns the row and deletes it from SQLite + in-memory list', async () => {
    useCartStore.getState().addItem(makeProduct({ sale_price: '9.80' }));
    await useHoldStore.getState().holdCurrentCart('Order A');
    const heldId = useHoldStore.getState().heldTransactions[0]!.id;

    const recalled = await useHoldStore.getState().recallTransaction(heldId);

    expect(recalled).toBeDefined();
    expect(recalled!.label).toBe('Order A');
    expect(deleteHeldTransaction).toHaveBeenCalledWith(expect.anything(), heldId);
    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('discardTransaction deletes from SQLite + in-memory list', async () => {
    useCartStore.getState().addItem(makeProduct());
    await useHoldStore.getState().holdCurrentCart('To discard');
    const heldId = useHoldStore.getState().heldTransactions[0]!.id;

    await useHoldStore.getState().discardTransaction(heldId);

    expect(deleteHeldTransaction).toHaveBeenCalledWith(expect.anything(), heldId);
    expect(useHoldStore.getState().heldTransactions).toHaveLength(0);
  });

  it('loadHeldTransactions hydrates in-memory list from SQLite rows (parses JSON)', async () => {
    vi.mocked(listHeldTransactions).mockResolvedValue([
      {
        id: 'h1',
        terminal_id: 'term-1',
        operator_id: 'op-1',
        label: 'Order Z',
        items_json: JSON.stringify([
          { id: 'line-1', product: { id: 'p', name: 'Foo', sku: 'F', price: '9.80' }, quantity: 1, unit_price: '9.80', line_total: '9.80', tax_rate: '0', tax_amount: '0.00' },
        ]),
        transaction_discount_json: null,
        subtotal: '9.80',
        total: '9.80',
        item_count: 1,
        held_at: '2026-04-23T09:00:00Z',
      },
    ]);

    await useHoldStore.getState().loadHeldTransactions();

    const held = useHoldStore.getState().heldTransactions;
    expect(held).toHaveLength(1);
    expect(held[0]!.label).toBe('Order Z');
    expect(held[0]!.items).toHaveLength(1);
    expect(held[0]!.items[0]!.product.name).toBe('Foo');
    expect(held[0]!.total).toBeCloseTo(9.8);
  });
});
