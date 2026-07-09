/**
 * Task 12 — location-aware stock display on the product tile.
 *
 * The `locationStock` prop drives the tile's stock chrome:
 *   - slice present → bccomp-based out-of-stock/low-stock + trimmed count;
 *   - slice with incoming > 0 → arriving badge (+ transfer/PO title);
 *   - slice === null → stock-exempt: NO stock chrome, always clickable;
 *   - slice undefined → legacy `stock_quantity` rendering verbatim
 *     (Menu tenants / no local stock data — display chrome UNCHANGED).
 *
 * Assertions target rendered text and ARIA semantics, not class names.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductCard, type ProductCardProps } from '../ProductCard';
import { makeProduct } from '@/test/helpers';

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
    format: (amount: number | string) => `${String(amount)} EUR`,
  }),
}));

vi.mock('@/lib/images/useProductImage', () => ({
  useProductImage: () => null,
}));

function renderCard(overrides: Partial<ProductCardProps> = {}) {
  const defaults: ProductCardProps = {
    product: makeProduct({ id: 'p1', name: 'Test Widget', sale_price: '9.99', stock_quantity: 50 }),
    onAddToCart: vi.fn(),
  };
  return render(<ProductCard {...defaults} {...overrides} />);
}

const ZERO_SLICE = { available: '0.0000', incoming_transfer: '0', incoming_po: '0' };

describe('ProductCard location stock display', () => {
  it('renders out-of-stock and blocks activation when available is 0.0000', () => {
    const onAddToCart = vi.fn();
    renderCard({
      // Legacy field says "in stock" — the slice must win.
      product: makeProduct({ stock_quantity: 50 }),
      locationStock: ZERO_SLICE,
      onAddToCart,
    });

    expect(screen.getByText('products.outOfStock')).toBeInTheDocument();
    const card = screen.getByRole('button');
    expect(card).toHaveAttribute('aria-disabled', 'true');
    expect(card).toHaveAttribute('tabindex', '-1');
    fireEvent.click(card);
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('keeps an out-of-stock tile TAPPABLE under warn/off policy (hardBlockOutOfStock=false)', () => {
    // Codex final-review P1: under 'warn' the tap must reach the stock gate
    // (which allows + toasts) — only 'block' policy hard-disables the tile.
    // The out-of-stock STYLING still shows.
    const onAddToCart = vi.fn();
    renderCard({
      product: makeProduct(),
      locationStock: ZERO_SLICE,
      onAddToCart,
      hardBlockOutOfStock: false,
    });

    expect(screen.getByText('products.outOfStock')).toBeInTheDocument();
    const card = screen.getByRole('button');
    expect(card).toHaveAttribute('aria-disabled', 'false');
    expect(card).toHaveAttribute('tabindex', '0');
    fireEvent.click(card);
    expect(onAddToCart).toHaveBeenCalledTimes(1);
  });

  it('renders low-stock with the NUMBER shown (Task 10 — unified badge format), not the wordless label', () => {
    renderCard({
      locationStock: { available: '3.0000', incoming_transfer: '0', incoming_po: '0' },
    });

    expect(screen.getByText('products.stock:3')).toBeInTheDocument();
    expect(screen.queryByText('products.lowStock')).not.toBeInTheDocument();
    const badge = screen.getByTestId('stock-row');
    expect(badge).toHaveAttribute('data-status', 'low');
    expect(screen.getByRole('button')).toHaveAttribute('aria-disabled', 'false');
  });

  it('renders the available count trimmed when available is 25.0000', () => {
    renderCard({
      locationStock: { available: '25.0000', incoming_transfer: '0', incoming_po: '0' },
    });

    expect(screen.getByText('products.stock:25')).toBeInTheDocument();
  });

  it('renders the arriving badge with the trimmed incoming total', () => {
    renderCard({
      locationStock: { available: '25.0000', incoming_transfer: '6.0000', incoming_po: '0' },
    });

    const badge = screen.getByText(/stock\.incoming:6/);
    expect(badge).toBeInTheDocument();
    // Title distinguishes the transfer leg; the PO leg is 0 so it is omitted.
    const titled = badge.closest('[title]');
    expect(titled?.getAttribute('title')).toContain('stock.incomingFromTransfer');
    expect(titled?.getAttribute('title')).not.toContain('stock.incomingOnOrder');
  });

  it('sums transfer + PO incoming and titles both legs', () => {
    renderCard({
      locationStock: { available: '1.0000', incoming_transfer: '6.0000', incoming_po: '2.5000' },
    });

    const badge = screen.getByText(/stock\.incoming:8\.5/);
    const titled = badge.closest('[title]');
    expect(titled?.getAttribute('title')).toContain('stock.incomingFromTransfer');
    expect(titled?.getAttribute('title')).toContain('stock.incomingOnOrder');
  });

  it('shows no arriving badge when nothing is incoming', () => {
    renderCard({
      locationStock: { available: '25.0000', incoming_transfer: '0', incoming_po: '0' },
    });

    expect(screen.queryByText(/stock\.incoming/)).not.toBeInTheDocument();
  });

  it('keeps the legacy stock_quantity rendering when the slice is undefined (Menu parity)', () => {
    const onAddToCart = vi.fn();
    renderCard({
      product: makeProduct({ stock_quantity: 999 }),
      onAddToCart,
    });

    // Today's Menu path: green count from stock_quantity, clickable, no badge.
    expect(screen.getByText('products.stock:999')).toBeInTheDocument();
    expect(screen.queryByText(/stock\.incoming/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button'));
    expect(onAddToCart).toHaveBeenCalledTimes(1);
  });

  it('renders NO stock chrome and stays clickable when the slice is null (exempt)', () => {
    const onAddToCart = vi.fn();
    renderCard({
      product: makeProduct({ is_physical: false, stock_quantity: 0 }),
      locationStock: null,
      onAddToCart,
    });

    expect(screen.queryByTestId('stock-row')).not.toBeInTheDocument();
    expect(screen.queryByText(/stock\.incoming/)).not.toBeInTheDocument();
    expect(screen.queryByText('products.outOfStock')).not.toBeInTheDocument();
    const card = screen.getByRole('button');
    expect(card).toHaveAttribute('aria-disabled', 'false');
    fireEvent.click(card);
    expect(onAddToCart).toHaveBeenCalledTimes(1);
  });
});
