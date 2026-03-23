import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductCard, type ProductCardProps } from '../ProductCard';
import { makeProduct } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts?.count !== undefined) return `${key}:${String(opts.count)}`;
      return key;
    },
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
    expect(btn).toBeDisabled();
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
