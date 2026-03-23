import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TransactionCart, type TransactionCartProps } from '../TransactionCart';
import { makeCartItem } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@/components/molecules/CartLineItem', () => ({
  CartLineItem: ({ item }: { item: { id: string; product: { name: string } } }) => (
    <div data-testid={`cart-line-${item.id}`}>{item.product.name}</div>
  ),
}));

vi.mock('@/components/organisms/PaymentSummary', () => ({
  PaymentSummary: () => <div data-testid="payment-summary">Payment Summary</div>,
}));

vi.mock('@/components/molecules/QuickActions', () => ({
  QuickActions: () => <div data-testid="quick-actions">Quick Actions</div>,
}));

function renderCart(overrides: Partial<TransactionCartProps> = {}) {
  const defaults: TransactionCartProps = {
    items: [],
    subtotal: 0,
    taxAmount: 0,
    discountAmount: 0,
    total: 0,
    itemCount: 0,
    hasDiscount: false,
    onUpdateQuantity: vi.fn(),
    onRemoveItem: vi.fn(),
    onClearCart: vi.fn(),
    onPayCash: vi.fn(),
    shiftNumber: 1,
    openingCash: '100.00',
  };
  return render(<TransactionCart {...defaults} {...overrides} />);
}

describe('TransactionCart', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders cart title', () => {
    renderCart();
    expect(screen.getByText('cart.title')).toBeInTheDocument();
  });

  it('shows empty state when no items', () => {
    renderCart();
    expect(screen.getByText('cart.empty')).toBeInTheDocument();
    expect(screen.getByText('cart.addProducts')).toBeInTheDocument();
  });

  it('renders cart items when present', () => {
    const items = [
      makeCartItem({ id: 'item-1', product: { id: 'p1', name: 'Widget', sku: 'W-001', price: '10.00' } }),
      makeCartItem({ id: 'item-2', product: { id: 'p2', name: 'Gadget', sku: 'G-001', price: '20.00' } }),
    ];

    renderCart({ items, itemCount: 2 });

    expect(screen.getByTestId('cart-line-item-1')).toBeInTheDocument();
    expect(screen.getByTestId('cart-line-item-2')).toBeInTheDocument();
  });

  it('shows item count badge when items present', () => {
    renderCart({ items: [makeCartItem()], itemCount: 3 });
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('does not show item count badge when empty', () => {
    renderCart({ itemCount: 0 });
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });

  it('shows clear button only when items present', () => {
    const { rerender } = render(
      <TransactionCart
        items={[]}
        subtotal={0}
        taxAmount={0}
        discountAmount={0}
        total={0}
        itemCount={0}
        hasDiscount={false}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onClearCart={vi.fn()}
        onPayCash={vi.fn()}
        shiftNumber={1}
        openingCash="100.00"
      />,
    );
    expect(screen.queryByText('cart.clear')).not.toBeInTheDocument();

    rerender(
      <TransactionCart
        items={[makeCartItem()]}
        subtotal={10}
        taxAmount={0}
        discountAmount={0}
        total={10}
        itemCount={1}
        hasDiscount={false}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onClearCart={vi.fn()}
        onPayCash={vi.fn()}
        shiftNumber={1}
        openingCash="100.00"
      />,
    );
    expect(screen.getByText('cart.clear')).toBeInTheDocument();
  });

  it('calls onClearCart when clear button clicked', () => {
    const onClearCart = vi.fn();
    renderCart({ items: [makeCartItem()], itemCount: 1, onClearCart });

    fireEvent.click(screen.getByText('cart.clear'));

    expect(onClearCart).toHaveBeenCalledOnce();
  });

  it('shows shift info footer', () => {
    renderCart({ shiftNumber: 3, openingCash: '250.00' });

    // The t() mock returns key:opts format
    expect(screen.getByText(/shift\.number/)).toBeInTheDocument();
    expect(screen.getByText(/shift\.opening/)).toBeInTheDocument();
  });

  it('shows payment summary when items present', () => {
    renderCart({ items: [makeCartItem()], itemCount: 1 });
    expect(screen.getByTestId('payment-summary')).toBeInTheDocument();
  });

  it('hides payment summary when cart is empty', () => {
    renderCart();
    expect(screen.queryByTestId('payment-summary')).not.toBeInTheDocument();
  });
});
