import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/test/i18n';
import { ProductCard, type ProductCardProps } from '../ProductCard';
import { makeProduct } from '@/test/helpers';
import type { POSProduct } from '@/types/product';

vi.mock('react-i18next', async (importActual) => {
  const actual = await importActual<typeof import('react-i18next')>();
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
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
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

vi.mock('@/lib/images/useProductImage', () => ({
  useProductImage: () => null,
}));

function renderCard(overrides: Partial<ProductCardProps> = {}) {
  const defaults: ProductCardProps = {
    product: makeProduct({ id: 'p1', name: 'Test Widget', sale_price: '9.99', stock_quantity: 20 }),
    onAddToCart: vi.fn(),
  };
  return render(<ProductCard {...defaults} {...overrides} />);
}

describe('ProductCard', () => {
  it('renders product name', () => {
    renderCard();
    expect(screen.getByText('Test Widget')).toBeInTheDocument();
  });

  it('renders product price', () => {
    renderCard();
    expect(screen.getByText('9.99 EUR')).toBeInTheDocument();
  });

  it('renders in visual mode', () => {
    renderCard({ displayMode: 'visual' });
    expect(screen.getByText('Test Widget')).toBeInTheDocument();
    expect(screen.getByText('9.99 EUR')).toBeInTheDocument();
  });

  it('calls onAddToCart when clicked', () => {
    const onAddToCart = vi.fn();
    const product = makeProduct({ id: 'p1', name: 'Clickable', sale_price: '5.00', stock_quantity: 10 });
    renderCard({ product, onAddToCart });
    fireEvent.click(screen.getByRole('button'));
    expect(onAddToCart).toHaveBeenCalledWith(product);
  });

  it('does not call onAddToCart when out of stock', () => {
    const onAddToCart = vi.fn();
    renderCard({
      product: makeProduct({ stock_quantity: 0 }),
      onAddToCart,
    });
    const btn = screen.getByRole('button');
    // The outer card is now a div[role="button"]; native `disabled` does not apply.
    // Accessibility state is communicated via aria-disabled instead.
    expect(btn).toHaveAttribute('aria-disabled', 'true');
    fireEvent.click(btn);
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('renders consistently on rerender', () => {
    const product = makeProduct({ id: 'p2', name: 'Stable Product', sale_price: '15.00', stock_quantity: 5 });
    const onAddToCart = vi.fn();
    const { rerender } = render(<ProductCard product={product} onAddToCart={onAddToCart} />);
    rerender(<ProductCard product={product} onAddToCart={onAddToCart} />);
    expect(screen.getByText('Stable Product')).toBeInTheDocument();
  });

  it('shows in-cart visual state when isInCart is true', () => {
    renderCard({ isInCart: true });
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('border-l-primary-500');
  });
});

const longNameProduct: POSProduct = {
  id: 'p1',
  name: 'Aquarium Water Conditioner — Tropical Edition 250ml',
  sku: 'AWC-250',
  sale_price: '32.000',
  stock_quantity: 5,
  barcode: null,
};

describe('ProductCard layout regressions', () => {
  it('renders the full product name in a `title` attribute for hover tooltip (grid mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('renders the full product name in a `title` attribute for hover tooltip (visual mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('marks the price row with shrink-0 so it cannot be squeezed by a long name (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const priceRow = container.querySelector('[data-testid="price-row"]');
    expect(priceRow).not.toBeNull();
    expect(priceRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('marks the stock row with shrink-0 so it stays anchored at the bottom (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const stockRow = container.querySelector('[data-testid="stock-row"]');
    expect(stockRow).not.toBeNull();
    expect(stockRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('renders the outer card as role="button" rather than a <button> so the inner customize button is not nested', () => {
    const productWithModifiers: POSProduct = {
      ...longNameProduct,
      modifier_groups: [{ id: 'g1', name: 'Size', is_required: true, modifiers: [] }] as never,
    };
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={productWithModifiers}
          onAddToCart={vi.fn()}
          onCustomize={vi.fn()}
          displayMode="grid"
        />
      </I18nextProvider>,
    );
    const cardRoot = container.firstElementChild!;
    expect(cardRoot.tagName).not.toBe('BUTTON');
    expect(cardRoot.getAttribute('role')).toBe('button');
    expect(cardRoot.getAttribute('tabindex')).toBe('0');
    expect(container.querySelector('button button')).toBeNull();
    const customize = container.querySelector('[data-testid="customize-button"]');
    expect(customize?.tagName).toBe('BUTTON');
  });

  it('fires onAddToCart when the outer card is activated via Enter or Space', () => {
    const onAdd = vi.fn();
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={onAdd} displayMode="grid" />
      </I18nextProvider>,
    );
    const card = container.firstElementChild as HTMLElement;
    card.focus();
    card.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(1);
    card.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(2);
  });
});
