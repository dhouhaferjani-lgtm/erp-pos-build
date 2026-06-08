import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductVariantStockView } from '../ProductVariantStockView';
import type { POSProductVariant } from '@/types/product';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts ? `${key}:${JSON.stringify(opts)}` : key,
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

function makeVariant(overrides: Partial<POSProductVariant> = {}): POSProductVariant {
  return {
    id: 'var-1',
    product_id: 'prod-1',
    variant_code: 'SHOE-39-BLK',
    sku: 'SHOE-39-BLK',
    barcode: null,
    name_suffix: ' — 39 / Black',
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: '14.50',
    image_url: null,
    stock_quantity: 7,
    ...overrides,
  };
}

describe('ProductVariantStockView', () => {
  it('renders one selectable row per variant with its label and stock', () => {
    const variants = [
      makeVariant({ id: 'var-1', name_suffix: ' — 39', stock_quantity: 7 }),
      makeVariant({ id: 'var-2', name_suffix: ' — 40', stock_quantity: 0 }),
    ];

    render(
      <ProductVariantStockView
        basePrice="10.00"
        variants={variants}
        selectedVariantId={null}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText(/— 39/)).toBeTruthy();
    expect(screen.getByText(/— 40/)).toBeTruthy();
    // Variant 1 stock shown
    expect(screen.getByText('variants.inStock:{"count":7}')).toBeTruthy();
    // Variant 2 is out of stock
    expect(screen.getByText('variants.outOfStock')).toBeTruthy();
  });

  it('shows the variant price_override, falling back to base price', () => {
    const variants = [
      makeVariant({ id: 'var-1', price_override: '14.50' }),
      makeVariant({ id: 'var-2', price_override: null }),
    ];

    render(
      <ProductVariantStockView
        basePrice="10.00"
        variants={variants}
        selectedVariantId={null}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('14.50 EUR')).toBeTruthy();
    expect(screen.getByText('10.00 EUR')).toBeTruthy();
  });

  it('emits the variant id when a row is clicked', () => {
    const onSelect = vi.fn();
    const variants = [makeVariant({ id: 'var-1', name_suffix: ' — 39', stock_quantity: 7 })];

    render(
      <ProductVariantStockView
        basePrice="10.00"
        variants={variants}
        selectedVariantId={null}
        onSelect={onSelect}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: /— 39/ }));
    expect(onSelect).toHaveBeenCalledWith('var-1');
  });

  it('disables out-of-stock variants so they cannot be picked', () => {
    const onSelect = vi.fn();
    const variants = [makeVariant({ id: 'var-2', name_suffix: ' — 40', stock_quantity: 0 })];

    render(
      <ProductVariantStockView
        basePrice="10.00"
        variants={variants}
        selectedVariantId={null}
        onSelect={onSelect}
      />,
    );

    const row = screen.getByRole('button', { name: /— 40/ });
    expect((row as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(row);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('renders an empty state when there are no variants', () => {
    render(
      <ProductVariantStockView
        basePrice="10.00"
        variants={[]}
        selectedVariantId={null}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('variants.empty')).toBeTruthy();
  });
});
