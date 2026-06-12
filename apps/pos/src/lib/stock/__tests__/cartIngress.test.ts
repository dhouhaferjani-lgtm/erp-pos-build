/**
 * Task 11 — ingress pins for the gated cart funnel.
 *
 * `addItemGated` / `updateQuantityGated` are the ONLY two functions that may
 * call the cart store's add/quantity-increase actions from the UI (L9: one
 * guard, every ingress). These tests use the REAL cartStore (zustand) so a
 * blocked gate provably leaves the cart untouched, and mock the gate module
 * to drive each StockGateResult branch.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { POSProduct, POSProductVariant } from '@/types/product';
import type { CartItem } from '@/types/cart';

const mocks = vi.hoisted(() => ({
  gateStockForAdd: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  translate: vi.fn((key: string) => key),
}));

vi.mock('../stockGate', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../stockGate')>();
  return { ...actual, gateStockForAdd: mocks.gateStockForAdd };
});
vi.mock('sonner', () => ({
  toast: { error: mocks.toastError, warning: mocks.toastWarning },
}));
vi.mock('@/lib/i18n', () => ({ default: { t: mocks.translate } }));
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: vi.fn(async () => {}),
}));

import { addItemGated, updateQuantityGated } from '../cartIngress';
import { useCartStore } from '@/stores/cartStore';

function product(overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id: 'p1',
    name: 'Test product',
    sku: 'SKU-1',
    sale_price: '10.000',
    stock_quantity: 0,
    sellableType: 'product',
    tax_rate: '0',
    ...overrides,
  };
}

const VARIANT: POSProductVariant = {
  id: 'vA',
  product_id: 'p1',
  variant_code: 'A',
  sku: 'SKU-1-A',
  name_suffix: ' — A',
  is_default: false,
  is_active: true,
  display_order: 1,
  stock_quantity: 0,
};

function pass(): { ok: true; warn: false } {
  return { ok: true, warn: false };
}

beforeEach(() => {
  vi.clearAllMocks();
  useCartStore.setState({
    items: [],
    transactionDiscount: undefined,
    cartSessionId: null,
    cartLinesRemovedThisSession: 0,
  });
});

describe('addItemGated', () => {
  it('blocked → addItem is NOT called (cart untouched) + toast.error with available', async () => {
    mocks.gateStockForAdd.mockResolvedValue({ ok: false, available: '2.0000' });

    const added = await addItemGated(product());

    expect(added).toBe(false);
    expect(useCartStore.getState().items).toHaveLength(0);
    expect(mocks.toastError).toHaveBeenCalledTimes(1);
    expect(mocks.translate).toHaveBeenCalledWith('pos:stock.blocked', { available: '2' });
    expect(mocks.toastWarning).not.toHaveBeenCalled();
  });

  it('warn → item IS added + toast.warning with available', async () => {
    mocks.gateStockForAdd.mockResolvedValue({ ok: true, warn: true, available: '1.5000' });

    const added = await addItemGated(product());

    expect(added).toBe(true);
    expect(useCartStore.getState().items).toHaveLength(1);
    expect(mocks.toastWarning).toHaveBeenCalledTimes(1);
    expect(mocks.translate).toHaveBeenCalledWith('pos:stock.warned', { available: '1.5' });
    expect(mocks.toastError).not.toHaveBeenCalled();
  });

  it('ok → item added, no toast; the gate ran BEFORE the store mutation', async () => {
    mocks.gateStockForAdd.mockImplementation(async () => {
      // The guard must see the PRE-add cart — pin the ordering.
      expect(useCartStore.getState().items).toHaveLength(0);
      return pass();
    });

    const added = await addItemGated(product());

    expect(added).toBe(true);
    expect(useCartStore.getState().items).toHaveLength(1);
    expect(mocks.toastError).not.toHaveBeenCalled();
    expect(mocks.toastWarning).not.toHaveBeenCalled();
  });

  it('passes the picked variant id to the gate and stamps the variant on the line', async () => {
    mocks.gateStockForAdd.mockResolvedValue(pass());

    await addItemGated(product({ has_variants: true }), { variant: VARIANT });

    expect(mocks.gateStockForAdd).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'p1' }),
      'vA',
      '1',
      [],
    );
    expect(useCartStore.getState().items[0]?.product.variant_id).toBe('vA');
  });

  it('withDefaults routes through addItemWithDefaults after the gate passes', async () => {
    mocks.gateStockForAdd.mockResolvedValue(pass());

    const added = await addItemGated(
      product({
        modifier_groups: [
          {
            id: 'g1',
            name: 'Size',
            selection_type: 'single',
            min_selections: 1,
            max_selections: 1,
            is_required: true,
            position: 0,
            modifiers: [
              {
                id: 'm1',
                name: 'Small',
                price_adjustment: '0.000',
                is_default: true,
                is_active: true,
                position: 0,
              },
            ],
          },
        ],
      }),
      { withDefaults: true },
    );

    expect(added).toBe(true);
    const line = useCartStore.getState().items[0];
    expect(line?.product.selectedModifiers).toHaveLength(1);
  });

  it('blocked gate with a variant: nothing reaches the cart', async () => {
    mocks.gateStockForAdd.mockResolvedValue({ ok: false, available: '0.0000' });

    const added = await addItemGated(product({ has_variants: true }), { variant: VARIANT });

    expect(added).toBe(false);
    expect(useCartStore.getState().items).toHaveLength(0);
  });
});

describe('updateQuantityGated', () => {
  const resolveProduct = (productId: string): POSProduct | undefined =>
    productId === 'p1' ? product({ is_physical: true }) : undefined;

  async function seedSaleLine(quantity = 1): Promise<string> {
    mocks.gateStockForAdd.mockResolvedValueOnce(pass());
    await addItemGated(product());
    const item = useCartStore.getState().items[0]!;
    if (quantity !== 1) {
      useCartStore.getState().updateQuantity(item.id, quantity);
    }
    mocks.gateStockForAdd.mockReset();
    return item.id;
  }

  function seedReturnLine(): string {
    const line: CartItem = {
      id: 'ret-1',
      product: { id: 'p1', name: 'Test product', sku: 'SKU-1', price: '10.000' },
      quantity: -1,
      unit_price: '10.000',
      line_total: '-10.000',
      tax_rate: '0',
      tax_amount: '0.00',
      kind: 'return',
    };
    useCartStore.getState().addReturnItems([line]);
    return line.id;
  }

  it('increase + blocked → quantity unchanged + toast.error', async () => {
    const itemId = await seedSaleLine(1);
    mocks.gateStockForAdd.mockResolvedValue({ ok: false, available: '1.0000' });

    const updated = await updateQuantityGated(itemId, 2, resolveProduct);

    expect(updated).toBe(false);
    expect(useCartStore.getState().items[0]?.quantity).toBe(1);
    expect(mocks.toastError).toHaveBeenCalledTimes(1);
  });

  it('increase + ok → quantity updated; gate received the DELTA at scale 4', async () => {
    const itemId = await seedSaleLine(1);
    mocks.gateStockForAdd.mockResolvedValue(pass());

    const updated = await updateQuantityGated(itemId, 3, resolveProduct);

    expect(updated).toBe(true);
    expect(useCartStore.getState().items[0]?.quantity).toBe(3);
    expect(mocks.gateStockForAdd).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'p1' }),
      null,
      '2.0000',
      expect.any(Array),
    );
  });

  it('increase + warn → quantity updated + toast.warning', async () => {
    const itemId = await seedSaleLine(1);
    mocks.gateStockForAdd.mockResolvedValue({ ok: true, warn: true, available: '0.0000' });

    const updated = await updateQuantityGated(itemId, 5, resolveProduct);

    expect(updated).toBe(true);
    expect(useCartStore.getState().items[0]?.quantity).toBe(5);
    expect(mocks.toastWarning).toHaveBeenCalledTimes(1);
  });

  it('DECREASE is never gated', async () => {
    const itemId = await seedSaleLine(4);

    const updated = await updateQuantityGated(itemId, 2, resolveProduct);

    expect(updated).toBe(true);
    expect(useCartStore.getState().items[0]?.quantity).toBe(2);
    expect(mocks.gateStockForAdd).not.toHaveBeenCalled();
  });

  it('quantity 0 passes through to the store (line removal) ungated', async () => {
    const itemId = await seedSaleLine(2);

    await updateQuantityGated(itemId, 0, resolveProduct);

    expect(useCartStore.getState().items).toHaveLength(0);
    expect(mocks.gateStockForAdd).not.toHaveBeenCalled();
  });

  it('return lines are never gated (returning MORE is not a stock add)', async () => {
    const itemId = seedReturnLine();

    const updated = await updateQuantityGated(itemId, -2, resolveProduct);

    expect(updated).toBe(true);
    expect(useCartStore.getState().items[0]?.quantity).toBe(-2);
    expect(mocks.gateStockForAdd).not.toHaveBeenCalled();
  });

  it('unknown item id → no-op', async () => {
    const updated = await updateQuantityGated('nope', 5, resolveProduct);

    expect(updated).toBe(false);
    expect(mocks.gateStockForAdd).not.toHaveBeenCalled();
  });

  it('falls back to a cart-line product slice when the catalog lookup misses', async () => {
    const itemId = await seedSaleLine(1);
    mocks.gateStockForAdd.mockResolvedValue(pass());

    await updateQuantityGated(itemId, 2, () => undefined);

    // Fallback slice keeps the product id (+ sellableType) so exemption rules
    // still apply; absent is_physical fails toward enforcement.
    expect(mocks.gateStockForAdd).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'p1', sellableType: 'product' }),
      null,
      '1.0000',
      expect.any(Array),
    );
  });
});
