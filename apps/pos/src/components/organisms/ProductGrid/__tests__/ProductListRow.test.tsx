/**
 * Task 12 — ProductListRow (the "Liste" touch-density row).
 *
 * Mirrors the mocking convention of
 * `ProductCard/__tests__/ProductCard.stock.test.tsx`: react-i18next is
 * mocked to return raw keys for generic lookups, except `products.outOfStock`
 * which is mapped to its real fr copy ("Rupture") so the out-of-stock
 * assertion reads the actual production label.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProductListRow, type ProductListRowProps } from '../ProductListRow';
import { tokens } from '@/lib/designTokens';
import { makeProduct } from '@/test/helpers';

// Task 16 — optional-field toggle. Mutable so individual tests can flip
// `showSkuOnRows` without re-mocking the module.
const settingsStoreMock = vi.hoisted(() => ({
  state: { showSkuOnRows: false },
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: <T,>(selector: (s: typeof settingsStoreMock.state) => T): T =>
    selector(settingsStoreMock.state),
}));

vi.mock('react-i18next', async (importActual) => {
  const actual = await importActual<typeof import('react-i18next')>();
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
        if (key === 'products.outOfStock') return 'Rupture';
        if (opts?.count !== undefined) return `${key}:${String(opts.count)}`;
        return key;
      },
    }),
  };
});

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => `${String(amount)} EUR`,
  }),
}));

vi.mock('@/lib/images/useProductImage', () => ({
  useProductImage: () => null,
}));

function renderRow(overrides: Partial<ProductListRowProps> = {}) {
  const defaults: ProductListRowProps = {
    product: makeProduct({ id: 'p1', name: 'Test Widget', sale_price: '9.99', stock_quantity: 50 }),
    onAddToCart: vi.fn(),
  };
  return render(<ProductListRow {...defaults} {...overrides} />);
}

describe('ProductListRow', () => {
  it('in-stock row: min-h-[48px], price is ink, + enabled', () => {
    renderRow({
      locationStock: { available: '12', incoming_transfer: '0', incoming_po: '0' },
    });

    const row = screen.getByTestId('product-list-row');
    expect(row.className).toContain('min-h-[48px]');

    const priceRow = screen.getByTestId('price-row');
    expect(priceRow.className).toContain('text-ink');
    expect(priceRow.className).not.toContain('text-ink-faint');

    const addButton = screen.getByTestId('add-button');
    expect(addButton.getAttribute('aria-disabled')).not.toBe('true');
  });

  it('out-of-stock + hardBlockOutOfStock: + is aria-disabled, row still shows price + "Rupture"', () => {
    renderRow({
      locationStock: { available: '0', incoming_transfer: '0', incoming_po: '0' },
      hardBlockOutOfStock: true,
    });

    const addButton = screen.getByTestId('add-button');
    expect(addButton.getAttribute('aria-disabled')).toBe('true');

    expect(screen.getByTestId('price-row')).toBeInTheDocument();
    expect(screen.getByText('Rupture')).toBeTruthy();
  });

  it('clicking + does not add to cart when activation is blocked', () => {
    const onAddToCart = vi.fn();
    renderRow({
      locationStock: { available: '0', incoming_transfer: '0', incoming_po: '0' },
      hardBlockOutOfStock: true,
      onAddToCart,
    });

    screen.getByTestId('add-button').click();
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('clicking + adds to cart when in stock', () => {
    const onAddToCart = vi.fn();
    renderRow({
      locationStock: { available: '12', incoming_transfer: '0', incoming_po: '0' },
      onAddToCart,
    });

    screen.getByTestId('add-button').click();
    expect(onAddToCart).toHaveBeenCalledTimes(1);
  });

  it('renders NO stock badge and stays enabled when the slice is null (exempt)', () => {
    renderRow({
      product: makeProduct({ is_physical: false, stock_quantity: 0 }),
      locationStock: null,
    });

    expect(screen.queryByTestId('stock-row')).not.toBeInTheDocument();
    expect(screen.getByTestId('add-button').getAttribute('aria-disabled')).not.toBe('true');
  });

  it('renders the view-details eye button when onViewDetails is provided, sized as a 48px touch target', () => {
    const onViewDetails = vi.fn();
    renderRow({ onViewDetails });

    const eye = screen.getByTestId('view-details-button');
    // 48px touch target (matches the + add button's height) — owner feedback
    // 2026-07-08: the previous h-10 (40px) read too small on a 15" touchscreen.
    expect(eye.className).toContain('h-12');
    expect(eye.className).toContain('w-12');
    // Persistent rest surface so it reads as tappable without hover (touch).
    expect(eye.className).toContain('bg-surface-sunken');

    eye.click();
    expect(onViewDetails).toHaveBeenCalledTimes(1);
  });

  it('renders the product name with the shared tokens.productName recipe (1-line truncate)', () => {
    renderRow();
    const name = screen.getByText('Test Widget');
    expect(name.className).toContain(tokens.productName.base);
    expect(name.className).toContain('truncate');
    expect(name.className).toContain('text-ink');
  });

  describe('showSkuOnRows (Task 16 — optional-field toggle, default off)', () => {
    it('does not render the SKU line when the flag is off', () => {
      settingsStoreMock.state.showSkuOnRows = false;
      renderRow({ product: makeProduct({ sku: 'SKU-999' }) });

      expect(screen.queryByTestId('sku-row')).not.toBeInTheDocument();
    });

    it('renders the SKU line when the flag is on', () => {
      settingsStoreMock.state.showSkuOnRows = true;
      renderRow({ product: makeProduct({ sku: 'SKU-999' }) });

      expect(screen.getByTestId('sku-row')).toHaveTextContent('SKU-999');
    });
  });
});
