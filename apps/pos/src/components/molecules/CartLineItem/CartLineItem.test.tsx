import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));
vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: string | number) => String(v), decimals: 3, currency: 'TND' }),
}));

import { CartLineItem } from './CartLineItem';
import { tokens } from '@/lib/designTokens';
import type { CartItem } from '@/types/cart';

const baseItem: CartItem = {
  id: 'line-1',
  quantity: 2,
  unit_price: '10.000',
  line_total: '20.000',
  product: { id: 'p1', name: 'Crème Hydratante', sku: 'CR1', price: '10.000' },
} as unknown as CartItem;

describe('CartLineItem collapse/expand', () => {
  it('collapses by default when onToggleExpand is provided (no qty stepper visible)', () => {
    const { getByTestId, queryByLabelText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        expanded={false}
        onToggleExpand={vi.fn()}
      />,
    );
    expect(getByTestId('cart-line').getAttribute('data-expanded')).toBe('false');
    expect(queryByLabelText('cart.incrementQty')).toBeNull();
  });

  it('reveals the stepper when expanded and increments via it', () => {
    const onUpdateQuantity = vi.fn();
    const { getByLabelText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={onUpdateQuantity}
        onRemove={vi.fn()}
        expanded
        onToggleExpand={vi.fn()}
      />,
    );
    fireEvent.click(getByLabelText('cart.incrementQty'));
    expect(onUpdateQuantity).toHaveBeenCalledWith('line-1', 3);
  });

  it('tapping the row body toggles expand', () => {
    const onToggleExpand = vi.fn();
    const { getByText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={vi.fn()}
        expanded={false}
        onToggleExpand={onToggleExpand}
      />,
    );
    fireEvent.click(getByText('Crème Hydratante'));
    expect(onToggleExpand).toHaveBeenCalledWith('line-1');
  });

  it('renders fully expanded (legacy) when no onToggleExpand is given', () => {
    const { getByTestId, getByLabelText } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} />,
    );
    expect(getByTestId('cart-line').getAttribute('data-expanded')).toBe('true');
    expect(getByLabelText('cart.incrementQty')).toBeTruthy();
  });

  it('hides the remove control while collapsed (prevents mis-tap next to expand)', () => {
    const onRemove = vi.fn();
    const { queryByLabelText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded={false}
        onToggleExpand={vi.fn()}
      />,
    );
    expect(queryByLabelText('cart.removeItem')).toBeNull();
  });

  it('removes immediately from the expanded controls when confirmDelete is off', () => {
    const onRemove = vi.fn();
    const { getByLabelText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded
        onToggleExpand={vi.fn()}
      />,
    );
    fireEvent.click(getByLabelText('cart.removeItem'));
    expect(onRemove).toHaveBeenCalledWith('line-1');
  });

  it('arms on first tap and removes only on the second when confirmDelete is on', () => {
    const onRemove = vi.fn();
    const { getByLabelText, queryByLabelText } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded
        onToggleExpand={vi.fn()}
        confirmDelete
      />,
    );
    // First tap arms the guard — does NOT remove.
    fireEvent.click(getByLabelText('cart.removeItem'));
    expect(onRemove).not.toHaveBeenCalled();
    // Control now asks for confirmation.
    const confirmBtn = getByLabelText('cart.confirmRemoveItem');
    expect(confirmBtn).toBeTruthy();
    // Second tap confirms.
    fireEvent.click(confirmBtn);
    expect(onRemove).toHaveBeenCalledWith('line-1');
    expect(queryByLabelText('cart.confirmRemoveItem')).toBeNull();
  });

  it('disarms the delete guard when the line collapses', () => {
    const onRemove = vi.fn();
    const { getByLabelText, queryByLabelText, rerender } = render(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded
        onToggleExpand={vi.fn()}
        confirmDelete
      />,
    );
    fireEvent.click(getByLabelText('cart.removeItem')); // arm
    expect(queryByLabelText('cart.confirmRemoveItem')).toBeTruthy();
    // Collapse, then re-expand: the guard must be reset (not still armed).
    rerender(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded={false}
        onToggleExpand={vi.fn()}
        confirmDelete
      />,
    );
    rerender(
      <CartLineItem
        item={baseItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded
        onToggleExpand={vi.fn()}
        confirmDelete
      />,
    );
    expect(queryByLabelText('cart.confirmRemoveItem')).toBeNull();
    expect(getByLabelText('cart.removeItem')).toBeTruthy();
  });

  it('renders the product name with the shared tokens.productName recipe (unified typography)', () => {
    const { getByText } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} />,
    );
    const name = getByText('Crème Hydratante');
    expect(name.className).toContain(tokens.productName.base);
    expect(name.className).toContain('truncate');
    expect(name.className).toContain('text-ink');
  });

  it('decrement at qty 1 removes the line', () => {
    const onRemove = vi.fn();
    const { getByLabelText } = render(
      <CartLineItem
        item={{ ...baseItem, quantity: 1 } as CartItem}
        onUpdateQuantity={vi.fn()}
        onRemove={onRemove}
        expanded
        onToggleExpand={vi.fn()}
      />,
    );
    fireEvent.click(getByLabelText('cart.decrementQty'));
    expect(onRemove).toHaveBeenCalledWith('line-1');
  });
});

describe('CartLineItem — added-line pulse (cart-always-foreground v1)', () => {
  it('renders no pulse overlay without a token', () => {
    const { queryByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} />,
    );
    expect(queryByTestId('cart-line-pulse')).toBeNull();
  });

  it('renders the token-based pulse overlay when pulseToken > 0', () => {
    const { getByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    const pulse = getByTestId('cart-line-pulse');
    expect(pulse.className).toContain('ez-line-pulse');
    expect(pulse.className).toContain('pointer-events-none');
    expect(pulse).toHaveAttribute('aria-hidden', 'true');
  });

  it('clears the overlay on animationend', () => {
    const { getByTestId, queryByTestId } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    fireEvent.animationEnd(getByTestId('cart-line-pulse'));
    expect(queryByTestId('cart-line-pulse')).toBeNull();
  });

  it('re-fires when the token changes (repeat add of the same line)', () => {
    const { getByTestId, queryByTestId, rerender } = render(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={1} />,
    );
    fireEvent.animationEnd(getByTestId('cart-line-pulse'));
    expect(queryByTestId('cart-line-pulse')).toBeNull();
    rerender(
      <CartLineItem item={baseItem} onUpdateQuantity={vi.fn()} onRemove={vi.fn()} pulseToken={2} />,
    );
    expect(getByTestId('cart-line-pulse')).toBeInTheDocument();
  });
});
